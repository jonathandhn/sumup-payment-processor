<?php

declare(strict_types=1);

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Payment;
use Civi\Api4\PaymentToken;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentprocessorWebhook;
use Civi\Api4\SumupReader;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;
use SumUp\Types\CheckoutSuccess;
use SumUp\Types\CheckoutAccepted;
use SumUp\Types\TransactionFull;
use SumUp\Exception\SDKException;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
class CRM_Core_Payment_Sumup extends CRM_Core_Payment
{
    private const WIDGET_SIGNED_FIELDS = [
        'cancel_url',
        'contribution_id',
        'expires',
        'processor_id',
        'return_url',
    ];

    private const SAVED_CARD_SIGNED_FIELDS = [
        'checkout_id',
        'contribution_id',
        'expires',
        'processor_id',
    ];

    /**
     * @param string $mode
     * @param array<string, mixed> $paymentProcessor
     */
    public function __construct(string $mode, &$paymentProcessor)
    {
        $this->_paymentProcessor = $paymentProcessor;
        if (!array_key_exists('is_test', $this->_paymentProcessor)) {
            $this->_paymentProcessor['is_test'] = ($mode === 'test');
        }
    }

    public function checkConfig(): ?string
    {
        $error = $this->getOperationalConfigurationError();
        if ($error === null || !empty($this->_paymentProcessor['is_test'])) {
            return $error;
        }

        return $this->hasConfiguredTestSibling() ? null : $error;
    }

    private function getOperationalConfigurationError(): ?string
    {
        if (empty($this->_paymentProcessor['user_name'])) {
            return E::ts('The SumUp merchant code is missing.');
        }
        if (empty($this->_paymentProcessor['password'])) {
            return E::ts('The SumUp API key is missing.');
        }
        if (!class_exists(\SumUp\SumUp::class)) {
            return E::ts('The SumUp PHP SDK is not installed.');
        }
        $mode = CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode();
        if (CRM_SumupPaymentProcessor_CheckoutMode::usesWallet($mode)) {
            if (!str_starts_with($this->getPublicMerchantKey(), 'sup_pk_')) {
                return E::ts('A valid SumUp public merchant key is required for wallet checkout.');
            }
            if (!preg_match('/^[A-Z]{2}$/', CRM_SumupPaymentProcessor_CheckoutMode::getMerchantCountryCode())) {
                return E::ts('A valid two-letter merchant country code is required for wallet checkout.');
            }
        }

        return null;
    }

    private function hasConfiguredTestSibling(): bool
    {
        $query = PaymentProcessor::get(false)
            ->addSelect('id', 'user_name', 'password', 'signature')
            ->addWhere('class_name', '=', 'Payment_Sumup')
            ->addWhere('is_test', '=', true);
        if (!empty($this->_paymentProcessor['name'])) {
            $query->addWhere('name', '=', (string) $this->_paymentProcessor['name']);
        }
        if (!empty($this->_paymentProcessor['domain_id'])) {
            $query->addWhere('domain_id', '=', (int) $this->_paymentProcessor['domain_id']);
        }
        $testProcessor = $query->setLimit(1)->execute()->first();
        if (empty($testProcessor['user_name']) || empty($testProcessor['password'])) {
            return false;
        }

        $mode = CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode();
        return !CRM_SumupPaymentProcessor_CheckoutMode::usesWallet($mode)
            || str_starts_with((string) ($testProcessor['signature'] ?? ''), 'sup_pk_');
    }

    public function getPublicMerchantKey(): string
    {
        return trim((string) ($this->_paymentProcessor['signature'] ?? ''));
    }

    public function getProcessorId(): int
    {
        return (int) ($this->_paymentProcessor['id'] ?? 0);
    }

    public function supportsRefund(): bool
    {
        return !empty($this->_paymentProcessor['user_name'])
            && !empty($this->_paymentProcessor['password'])
            && class_exists(\SumUp\SumUp::class);
    }

    public function supportsBackOffice(): bool
    {
        return true;
    }

    /**
     * Replace CiviCRM's raw card fields with the paired Solo selector in back office.
     *
     * @param CRM_Core_Form $form
     */
    public function buildForm(&$form): bool
    {
        if (empty($form->isBackOffice)) {
            return false;
        }

        $this->setBackOffice(true);
        $readerOptions = $this->getSoloReaderOptions();
        $form->add(
            'select',
            'sumup_reader_id',
            E::ts('SumUp terminal'),
            ['' => E::ts('- select -')] + $readerOptions,
            true,
            ['class' => 'crm-select2 huge']
        );
        CRM_Core_Region::instance('billing-block')->add([
            'template' => E::path('templates/CRM/Core/Payment/Sumup/BackOfficeSolo.tpl'),
            'weight' => -10,
        ]);

        if ($readerOptions === []) {
            CRM_Core_Session::setStatus(
                E::ts('No paired and active SumUp Solo terminal is available for this processor.'),
                E::ts('SumUp terminal'),
                'warning'
            );
        }

        return true;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $errors
     */
    public function validatePaymentInstrument($values, &$errors): void
    {
        if (!array_key_exists('sumup_reader_id', $values)) {
            return;
        }

        $readerId = is_numeric($values['sumup_reader_id']) ? (int) $values['sumup_reader_id'] : 0;
        if ($readerId <= 0 || $this->getSoloReader($readerId) === null) {
            $errors['sumup_reader_id'] = E::ts('Please select an available SumUp Solo terminal.');
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{refund_trxn_id: string, refund_status: string, fee_amount: int}
     */
    public function doRefund(&$params): array
    {
        if (!$this->supportsRefund()) {
            throw new PaymentProcessorException(E::ts('SumUp refunds are unavailable for this processor.'));
        }

        $transactionReference = trim((string) ($params['trxn_id'] ?? ''));
        $requestedAmount = (float) ($params['amount'] ?? 0);
        $requestedMinor = (int) round($requestedAmount * 100);
        if (
            !preg_match('/^[A-Za-z0-9_-]{4,100}$/', $transactionReference)
            || $requestedMinor <= 0
            || abs($requestedAmount - ($requestedMinor / 100)) > 0.00001
        ) {
            throw new PaymentProcessorException(
                E::ts('A SumUp refund requires a valid transaction reference and a positive two-decimal amount.')
            );
        }
        $this->assertFullRefundAmount($transactionReference, $requestedAmount);

        $lock = CRM_Core_Lock::createScopedLock('data.sumup.refund.' . $transactionReference);
        if (!$lock->acquire()) {
            throw new PaymentProcessorException(
                E::ts('A refund for this SumUp transaction is already being processed.')
            );
        }

        try {
            $service = $this->service();
            $transaction = $service->getTransaction($transactionReference);
            $transactionId = trim((string) $transaction->id);
            if (!preg_match('/^[A-Za-z0-9_-]{4,100}$/', $transactionId)) {
                throw new PaymentProcessorException(E::ts('SumUp did not return a valid transaction identifier.'));
            }
            if (!hash_equals($this->getMerchantCode(), (string) $transaction->merchantCode)) {
                throw new PaymentProcessorException(E::ts('The SumUp transaction belongs to another merchant.'));
            }
            if (!in_array(strtoupper((string) $transaction->status), ['SUCCESSFUL', 'REFUNDED'], true)) {
                throw new PaymentProcessorException(
                    E::ts('The SumUp transaction is not refundable in its current state.')
                );
            }

            $currency = strtoupper((string) $transaction->currency);
            $requestedCurrency = strtoupper(trim((string) ($params['currency'] ?? '')));
            if ($requestedCurrency !== '' && $requestedCurrency !== $currency) {
                throw new PaymentProcessorException(E::ts('The refund currency does not match the SumUp transaction.'));
            }

            $transactionMinor = (int) round((float) $transaction->amount * 100);
            $refundedMinor = $this->getRefundedMinorUnits($transaction);
            $refundableMinor = max(0, $transactionMinor - $refundedMinor);
            if ($requestedMinor > $refundableMinor) {
                $existingRefundEventId = $this->findUnrecordedRefundEventId(
                    $transaction,
                    $requestedMinor
                );
                if ($existingRefundEventId !== null) {
                    Civi::log()->info(sprintf(
                        'SumUp refund recovered from provider state: '
                        . 'transaction_id=%s refund_event_id=%s amount=%.2f currency=%s',
                        $transactionId,
                        $existingRefundEventId,
                        $requestedMinor / 100,
                        $currency
                    ));
                    return [
                        'refund_trxn_id' => $this->refundTransactionId($transactionId, $existingRefundEventId),
                        'refund_status' => 'Completed',
                        'fee_amount' => 0,
                    ];
                }
                throw new PaymentProcessorException(
                    E::ts('The requested refund exceeds the amount still refundable by SumUp.')
                );
            }

            $existingRefundEventIds = $this->getRefundEventIds($transaction);
            $service->refundTransaction(
                $transactionId,
                $requestedMinor === $refundableMinor ? null : $requestedMinor / 100
            );
            $updatedTransaction = $service->getTransaction($transactionId);
            $refundEventId = $this->findNewRefundEventId(
                $updatedTransaction,
                $existingRefundEventIds,
                $requestedMinor
            );
            if ($refundEventId === null) {
                $refundEventId = 'accepted-' . bin2hex(random_bytes(8));
                Civi::log()->warning(
                    'SumUp accepted refund but its event is not visible yet: transaction_id=' . $transactionId
                );
            }
            $refundTransactionId = $this->refundTransactionId($transactionId, $refundEventId);
            Civi::log()->info(sprintf(
                'SumUp refund completed: transaction_id=%s refund_event_id=%s processor_id=%d amount=%.2f currency=%s',
                $transactionId,
                $refundEventId,
                $this->getProcessorId(),
                $requestedMinor / 100,
                $currency
            ));

            return [
                'refund_trxn_id' => $refundTransactionId,
                'refund_status' => 'Completed',
                'fee_amount' => 0,
            ];
        } catch (PaymentProcessorException $exception) {
            throw $exception;
        } catch (SDKException $exception) {
            $responseBody = $exception->getResponseBody();
            $providerDetail = is_array($responseBody)
                ? (string) ($responseBody['detail'] ?? $responseBody['message'] ?? '')
                : '';
            Civi::log()->error(sprintf(
                'SumUp refund request failed: status=%d detail=%s',
                $exception->getStatusCode(),
                $providerDetail !== '' ? $providerDetail : $exception->getMessage()
            ));
            throw new PaymentProcessorException(
                E::ts('The SumUp refund could not be processed. Please try again later.')
            );
        } catch (Throwable $exception) {
            Civi::log()->error('SumUp refund request failed: ' . $exception->getMessage());
            throw new PaymentProcessorException(
                E::ts('The SumUp refund could not be processed. Please try again later.')
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed>|PropertyBag $params
     * @param string $component
     * @return array<string, mixed>
     */
    public function doPayment(&$params, $component = 'contribute')
    {
        $configurationError = $this->getOperationalConfigurationError();
        if ($configurationError !== null) {
            throw new PaymentProcessorException($configurationError);
        }

        $propertyBag = PropertyBag::cast($params);
        $this->_component = strtolower((string) $component);
        $contributionId = (int) $propertyBag->getContributionID();
        if ($contributionId <= 0) {
            throw new PaymentProcessorException(E::ts('Unable to prepare the SumUp payment reference.'));
        }

        $legacyParams = is_array($params) ? $params : [];
        if ($this->isBackOffice()) {
            return $this->startBackOfficeSoloPayment($propertyBag, $legacyParams);
        }

        $qfKeyValue = self::getPaymentParameter($params, ['qfKey']);
        $qfKey = is_scalar($qfKeyValue) ? (string) $qfKeyValue : null;
        $participantIdValue = self::getPaymentParameter($params, ['participantID', 'participant_id']);
        $participantId = is_numeric($participantIdValue) && (int) $participantIdValue > 0
            ? (int) $participantIdValue
            : null;
        $returnUrlValue = self::getPaymentParameter($params, ['return_url', 'returnURL']);
        $returnUrl = is_string($returnUrlValue) && $returnUrlValue !== ''
            ? $returnUrlValue
            : $this->getReturnSuccessUrl($qfKey);
        $cancelUrlValue = self::getPaymentParameter($params, ['cancel_url', 'cancelURL']);
        $cancelUrl = is_string($cancelUrlValue) && $cancelUrlValue !== ''
            ? $cancelUrlValue
            : $this->getCancelUrl($qfKey, $participantId);
        $checkoutMode = CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode();
        $description = $this->paymentDescription($legacyParams, $contributionId);
        $result = $this->setStatusPaymentPending([]);

        if (CRM_SumupPaymentProcessor_CheckoutMode::usesHosted($checkoutMode)) {
            $paymentUrl = $this->startHostedCheckoutForContribution(
                $contributionId,
                $returnUrl,
                $cancelUrl,
                $description
            );
            $this->redirectToCheckout($paymentUrl);
            return $result;
        }

        $checkoutData = $this->startEmbeddedCheckoutForContribution(
            $contributionId,
            $returnUrl,
            $cancelUrl,
            $description
        );

        if (self::isQuickFormEmbeddedRequest()) {
            CRM_Core_Page_AJAX::returnJsonResponse([
                'sumup_embedded_checkout' => $checkoutData,
            ]);
        }

        $this->redirectToCheckout($checkoutData['browser_return_url']);

        return $result;
    }

    /**
     * @param array<string, mixed> $legacyParams
     * @return array<string, mixed>
     */
    private function startBackOfficeSoloPayment(PropertyBag $propertyBag, array $legacyParams): array
    {
        $contributionId = (int) $propertyBag->getContributionID();
        $localReaderId = is_numeric($legacyParams['sumup_reader_id'] ?? null)
            ? (int) $legacyParams['sumup_reader_id']
            : 0;
        $clientTransactionId = $this->startSoloCheckoutForContribution(
            $contributionId,
            $localReaderId,
            $this->paymentDescription($legacyParams, $contributionId)
        );

        return $this->setStatusPaymentPending([
            'trxn_id' => $clientTransactionId,
        ]);
    }

    /**
     * Start a SumUp Reader Checkout for an existing CiviCRM contribution.
     */
    public function startSoloCheckoutForContribution(
        int $contributionId,
        int $localReaderId,
        ?string $description = null
    ): string {
        if ($contributionId <= 0) {
            throw new PaymentProcessorException(E::ts('Unable to prepare the SumUp terminal payment reference.'));
        }
        $reader = $this->getSoloReader($localReaderId);
        if ($reader === null) {
            throw new PaymentProcessorException(E::ts('The selected SumUp Solo terminal is unavailable.'));
        }

        $readerService = CRM_SumupPaymentProcessor_ReaderService::fromPaymentProcessorId(
            (int) $this->_paymentProcessor['id']
        );
        $remoteStatus = $readerService->getStatus((string) $reader['reader_id']);
        $deviceStatus = $this->enumValue($remoteStatus->data->status);
        $deviceState = $this->enumValue($remoteStatus->data->state);
        if ($deviceStatus !== 'ONLINE' || $deviceState !== 'IDLE') {
            throw new PaymentProcessorException(E::ts(
                'The selected SumUp terminal is not ready (%1 / %2).',
                [1 => $deviceStatus, 2 => $deviceState]
            ));
        }

        $contribution = Contribution::get(false)
            ->addSelect('id', 'contact_id', 'total_amount', 'currency', 'source', 'contribution_recur_id')
            ->addWhere('id', '=', $contributionId)
            ->execute()
            ->single();
        $amount = (float) $contribution['total_amount'];
        $amountMinor = (int) round($amount * 100);
        $currency = strtoupper((string) $contribution['currency']);
        $reference = sprintf('CIVI-%d-%s', $contributionId, bin2hex(random_bytes(8)));
        $response = $readerService->createCheckout(
            (string) $reader['reader_id'],
            $amountMinor,
            $currency,
            $description ?? $this->paymentDescription([], $contributionId),
            CRM_Mjwshared_Webhook::getWebhookPath((int) $this->_paymentProcessor['id']),
            $reference
        );
        $clientTransactionId = trim((string) $response->data->clientTransactionId);
        if (!CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($clientTransactionId)) {
            throw new PaymentProcessorException(E::ts('SumUp did not return a valid terminal transaction identifier.'));
        }

        CRM_SumupPaymentProcessor_CheckoutStore::recordCreated(
            $clientTransactionId,
            $reference,
            $contributionId,
            (int) $this->_paymentProcessor['id'],
            $amount,
            $currency,
            CRM_SumupPaymentProcessor_CheckoutMode::SOLO,
            (string) $reader['reader_id']
        );
        Contribution::update(false)
            ->addWhere('id', '=', $contributionId)
            ->setValues([
                'payment_processor_id' => (int) $this->_paymentProcessor['id'],
                'trxn_id' => $clientTransactionId,
            ])
            ->execute();

        return $clientTransactionId;
    }

    /** @return array<int, string> */
    private function getSoloReaderOptions(): array
    {
        $options = [];
        foreach (
            SumupReader::get(false)
                ->addSelect('id', 'site_code', 'canonical_name', 'device_status', 'device_state')
                ->addWhere('payment_processor_id', '=', (int) $this->_paymentProcessor['id'])
                ->addWhere('pairing_status', '=', 'paired')
                ->addWhere('is_active', '=', true)
                ->addOrderBy('site_code', 'ASC')
                ->addOrderBy('canonical_name', 'ASC')
                ->execute() as $reader
        ) {
            $state = trim(sprintf(
                '%s / %s',
                (string) ($reader['device_status'] ?? ''),
                (string) ($reader['device_state'] ?? '')
            ), ' /');
            $options[(int) $reader['id']] = sprintf(
                '%s - %s%s',
                (string) $reader['site_code'],
                (string) $reader['canonical_name'],
                $state !== '' ? ' (' . $state . ')' : ''
            );
        }

        return $options;
    }

    /** @return array<string, mixed>|null */
    private function getSoloReader(int $readerId): ?array
    {
        if ($readerId <= 0) {
            return null;
        }
        $reader = SumupReader::get(false)
            ->addSelect('id', 'reader_id', 'canonical_name')
            ->addWhere('id', '=', $readerId)
            ->addWhere('payment_processor_id', '=', (int) $this->_paymentProcessor['id'])
            ->addWhere('pairing_status', '=', 'paired')
            ->addWhere('is_active', '=', true)
            ->execute()
            ->first();

        return $reader ?: null;
    }

    public function startHostedCheckoutForContribution(
        int $contributionId,
        string $returnUrl,
        string $cancelUrl,
        ?string $description = null
    ): string {
        $checkoutData = $this->createCheckoutForContribution(
            $contributionId,
            $returnUrl,
            $cancelUrl,
            $description,
            true
        );
        $hostedUrl = (string) ($checkoutData['hosted_checkout_url'] ?? '');
        $parts = parse_url($hostedUrl);
        if (
            ($parts['scheme'] ?? '') !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'checkout.sumup.com'
        ) {
            throw new PaymentProcessorException(E::ts('SumUp did not return a valid Hosted Checkout URL.'));
        }
        return $hostedUrl;
    }

    /**
     * @return array{
     *   checkout_id: string,
     *   amount: string,
     *   currency: string,
     *   locale: string,
     *   mode: string,
     *   public_key: string,
     *   country_code: string,
     *   browser_return_url: string,
     *   cancel_url: string
     * }
     */
    public function startEmbeddedCheckoutForContribution(
        int $contributionId,
        string $returnUrl,
        string $cancelUrl,
        ?string $description = null
    ): array {
        return $this->createCheckoutForContribution(
            $contributionId,
            $returnUrl,
            $cancelUrl,
            $description,
            false
        );
    }

    /**
     * @return array{
     *   checkout_id: string,
     *   amount: string,
     *   currency: string,
     *   locale: string,
     *   mode: string,
     *   public_key: string,
     *   country_code: string,
     *   browser_return_url: string,
     *   cancel_url: string,
     *   hosted_checkout_url: string|null,
     *   saved_payment_methods: list<array{payment_token_id: int, masked_account_number: string}>,
     *   saved_payment_action: array<string, int|string>|null
     * }
     */
    private function createCheckoutForContribution(
        int $contributionId,
        string $returnUrl,
        string $cancelUrl,
        ?string $description,
        bool $hosted
    ): array {
        $configurationError = $this->getOperationalConfigurationError();
        if ($configurationError !== null) {
            throw new PaymentProcessorException($configurationError);
        }
        if ($contributionId <= 0) {
            throw new PaymentProcessorException(E::ts('Unable to prepare the SumUp payment reference.'));
        }

        $contribution = Contribution::get(false)
            ->addSelect(
                'id',
                'contact_id',
                'total_amount',
                'currency',
                'source',
                'contribution_recur_id'
            )
            ->addWhere('id', '=', $contributionId)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute()
            ->single();
        $amount = (float) $contribution['total_amount'];
        $currency = strtoupper((string) $contribution['currency']);
        $browserReturnUrl = $this->buildSignedWidgetUrl($contributionId, $returnUrl, $cancelUrl);
        $checkoutMode = CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode();
        $contributionRecurId = (int) ($contribution['contribution_recur_id'] ?? 0);
        $providerPurpose = 'CHECKOUT';
        $registryPurpose = 'PAYMENT';
        $customerId = null;
        $savedCards = [];
        if ($contributionRecurId > 0) {
            if ($hosted || $checkoutMode !== CRM_SumupPaymentProcessor_CheckoutMode::WIDGET) {
                throw new PaymentProcessorException(E::ts(
                    'Recurring SumUp payments require the Card Widget checkout mode.'
                ));
            }
            $providerPurpose = 'SETUP_RECURRING_PAYMENT';
            $registryPurpose = 'SETUP_RECURRING_PAYMENT';
            $customerId = $this->recurringCustomerId((int) $contribution['contact_id']);
            $this->service()->ensureCustomer($customerId);
        } elseif (!$hosted) {
            $savedCards = $this->getSavedCardsForContact((int) $contribution['contact_id']);
            if ($savedCards !== []) {
                $customerId = $this->recurringCustomerId((int) $contribution['contact_id']);
            }
        }
        $providerDescription = trim((string) $description);
        if ($providerDescription === '') {
            $providerDescription = trim((string) ($contribution['source'] ?? ''));
        }
        if ($providerDescription === '') {
            $providerDescription = E::ts('CiviCRM contribution %1', [1 => $contributionId]);
        }

        $checkout = $this->service()->create(
            contributionId: $contributionId,
            amount: $amount,
            currency: $currency,
            description: $providerDescription,
            webhookUrl: CRM_Mjwshared_Webhook::getWebhookPath((int) $this->_paymentProcessor['id']),
            browserReturnUrl: $browserReturnUrl,
            hosted: $hosted,
            customerId: $customerId,
            purpose: $providerPurpose
        );
        $checkoutId = (string) $checkout->id;
        if (!CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($checkoutId)) {
            throw new PaymentProcessorException(E::ts('SumUp did not return a valid checkout identifier.'));
        }
        $checkoutReference = (string) $checkout->checkoutReference;
        CRM_SumupPaymentProcessor_CheckoutStore::recordCreated(
            $checkoutId,
            $checkoutReference,
            $contributionId,
            (int) $this->_paymentProcessor['id'],
            $amount,
            $currency,
            $checkoutMode,
            null,
            $registryPurpose,
            $customerId
        );

        Contribution::update(false)
            ->addWhere('id', '=', $contributionId)
            ->setValues([
                'payment_processor_id' => (int) $this->_paymentProcessor['id'],
                'trxn_id' => $checkoutId,
            ])
            ->execute();

        $savedPaymentAction = $savedCards !== []
            ? $this->buildSavedCardAction($contributionId, $checkoutId)
            : null;

        return [
            'checkout_id' => $checkoutId,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'locale' => CRM_SumupPaymentProcessor_CheckoutMode::getLocale(),
            'mode' => $checkoutMode,
            'public_key' => $this->getPublicMerchantKey(),
            'country_code' => CRM_SumupPaymentProcessor_CheckoutMode::getMerchantCountryCode(),
            'browser_return_url' => $browserReturnUrl,
            'cancel_url' => $cancelUrl,
            'hosted_checkout_url' => $checkout->hostedCheckoutUrl,
            'saved_payment_methods' => $savedCards,
            'saved_payment_action' => $savedPaymentAction,
        ];
    }

    /**
     * @param array<string, mixed>|PropertyBag $params
     * @param list<string> $names
     */
    private static function getPaymentParameter(array|PropertyBag $params, array $names): mixed
    {
        foreach ($names as $name) {
            if (is_array($params) && array_key_exists($name, $params)) {
                $value = $params[$name];
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
            if ($params instanceof PropertyBag) {
                try {
                    $value = $params->getCustomProperty($name);
                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                } catch (BadMethodCallException | InvalidArgumentException) {
                    // Try the next legacy alias, then fall back to CiviCRM's URL helpers.
                }
            }
        }

        return null;
    }

    private function redirectToCheckout(string $paymentUrl): void
    {
        if (
            self::isDrupalWebformAjaxRequest()
            && class_exists('\\Drupal\\webform\\Ajax\\WebformRefreshCommand')
        ) {
            $command = new \Drupal\webform\Ajax\WebformRefreshCommand($paymentUrl);
            CRM_Core_Page_AJAX::returnJsonResponse([$command->render()]);
        }
        if (self::isDrupalAjaxRequest()) {
            CRM_Utils_JSON::output([[
                'command' => 'sumupRedirect',
                'url' => $paymentUrl,
            ]]);
        }
        CRM_Core_Config::singleton()->userSystem->prePostRedirect();
        CRM_Utils_System::redirect($paymentUrl);
    }

    public static function isDrupalWebformAjaxRequest(): bool
    {
        return !empty($_REQUEST['_drupal_ajax']);
    }

    public static function isDrupalAjaxRequest(): bool
    {
        return self::isDrupalWebformAjaxRequest()
            || !empty($_REQUEST['ajax_form'])
            || (isset($_REQUEST['_wrapper_format']) && $_REQUEST['_wrapper_format'] === 'drupal_ajax');
    }

    public static function isQuickFormEmbeddedRequest(): bool
    {
        return !empty($_REQUEST['sumup_quickform_embed']);
    }

    public function handlePaymentNotification(): void
    {
        http_response_code(204);
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            CRM_Utils_System::civiExit();
        }

        $rawPayload = (string) file_get_contents('php://input');
        $payload = json_decode($rawPayload, true);
        $eventType = is_array($payload) ? (string) ($payload['event_type'] ?? '') : '';
        $eventId = is_array($payload) ? (string) ($payload['id'] ?? '') : '';
        $identifier = $eventType === 'solo.transaction.updated' && is_array($payload['payload'] ?? null)
            ? (string) ($payload['payload']['client_transaction_id'] ?? '')
            : $eventId;
        if (
            !is_array($payload)
            || !CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($eventId)
            || !CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($identifier)
        ) {
            http_response_code(400);
            CRM_Utils_System::civiExit();
        }
        if (!in_array($eventType, ['CHECKOUT_STATUS_CHANGED', 'solo.transaction.updated'], true)) {
            CRM_Utils_System::civiExit();
        }

        try {
            PaymentprocessorWebhook::create(false)
                ->setValues([
                    'payment_processor_id' => (int) $this->_paymentProcessor['id'],
                    'event_id' => $eventId,
                    'trigger' => $eventType,
                    'identifier' => $identifier,
                    'created_date' => 'now',
                    'data' => $rawPayload,
                    'status' => 'new',
                ])
                ->execute();
        } catch (Throwable $exception) {
            Civi::log()->error(sprintf(
                'SumUp webhook %s could not be queued for processor %d: %s',
                $eventId,
                (int) $this->_paymentProcessor['id'],
                $exception->getMessage()
            ));
            http_response_code(500);
        }

        CRM_Utils_System::civiExit();
    }

    /**
     * Process one event previously persisted by MJWShared.
     *
     * @param array<string, mixed> $webhookEvent
     */
    public function processWebhookEvent(array $webhookEvent): bool
    {
        $webhookId = (int) ($webhookEvent['id'] ?? 0);
        try {
            $payload = json_decode((string) ($webhookEvent['data'] ?? ''), true);
            if (!is_array($payload)) {
                throw new PaymentProcessorException(E::ts('Invalid SumUp webhook payload in the queue.'));
            }
            $eventType = (string) ($payload['event_type'] ?? '');
            if ($eventType === 'solo.transaction.updated' && is_array($payload['payload'] ?? null)) {
                $clientTransactionId = (string) ($payload['payload']['client_transaction_id'] ?? '');
                $result = $this->verifyAndApplyReaderCheckout($clientTransactionId);
            } elseif ($eventType === 'CHECKOUT_STATUS_CHANGED') {
                $result = $this->verifyAndApplyCheckout((string) ($payload['id'] ?? ''));
            } else {
                throw new PaymentProcessorException(E::ts('Invalid SumUp webhook payload in the queue.'));
            }
            $this->finishWebhook($webhookId, 'success', E::ts(
                'SumUp checkout status verified: %1.',
                [1 => $result['status']]
            ));
            return true;
        } catch (Throwable $exception) {
            $this->finishWebhook($webhookId, 'error', $exception->getMessage());
            return false;
        }
    }

    /**
     * @return array{status: string, contribution_id: int, transaction_id: string|null}
     */
    public function verifyAndApplyCheckout(string $checkoutId, ?int $expectedContributionId = null): array
    {
        $registry = CRM_SumupPaymentProcessor_CheckoutStore::getByCheckoutId($checkoutId);
        $checkout = $this->service()->get($checkoutId);
        $contributionId = CRM_SumupPaymentProcessor_CheckoutService::getContributionIdFromReference(
            (string) $checkout->checkoutReference
        );
        if ($expectedContributionId !== null && $expectedContributionId !== $contributionId) {
            throw new PaymentProcessorException(E::ts('SumUp checkout does not belong to this contribution.'));
        }
        if (
            $registry['contribution_id'] !== $contributionId
            || $registry['payment_processor_id'] !== (int) $this->_paymentProcessor['id']
            || !hash_equals($registry['checkout_reference'], (string) $checkout->checkoutReference)
        ) {
            throw new PaymentProcessorException(E::ts('SumUp checkout registry does not match the provider checkout.'));
        }

        $contribution = Contribution::get(false)
            ->addSelect(
                'id',
                'total_amount',
                'currency',
                'payment_instrument_id',
                'payment_processor_id',
                'trxn_id',
                'contact_id',
                'contribution_recur_id',
                'contribution_status_id:name'
            )
            ->addWhere('id', '=', $contributionId)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute()
            ->single();
        $processorId = (int) $this->_paymentProcessor['id'];
        $configuredProcessorId = (int) ($contribution['payment_processor_id'] ?? 0);
        if ($configuredProcessorId > 0 && $configuredProcessorId !== $processorId) {
            throw new PaymentProcessorException(
                E::ts('SumUp checkout payment processor does not match the contribution.')
            );
        }

        $this->service()->assertMatchesContribution(
            $checkout,
            $contributionId,
            (float) $contribution['total_amount'],
            (string) $contribution['currency']
        );
        if (
            (int) round($registry['amount'] * 100) !== (int) round((float) $contribution['total_amount'] * 100)
            || strtoupper($registry['currency']) !== strtoupper((string) $contribution['currency'])
        ) {
            throw new PaymentProcessorException(
                E::ts('SumUp checkout registry amount does not match the contribution.')
            );
        }

        $transactionId = $this->transactionId($checkout);
        $status = strtoupper((string) $checkout->status);
        CRM_SumupPaymentProcessor_CheckoutStore::recordVerifiedState(
            $checkoutId,
            $status,
            $transactionId
        );
        if ($status === 'PAID') {
            if ($registry['purpose'] === 'SETUP_RECURRING_PAYMENT') {
                return $this->completeRecurringSetup($checkout, $registry, $contribution);
            }
            if ($transactionId === null) {
                throw new PaymentProcessorException(E::ts('Paid SumUp checkout has no transaction identifier.'));
            }
            $this->completeContribution($contribution, $transactionId);
        } elseif (
            in_array($status, ['FAILED', 'EXPIRED'], true)
            && !in_array($registry['purpose'], ['RECURRING_PAYMENT', 'CARD_REPLACEMENT'], true)
        ) {
            $this->failPendingContribution($contributionId, (string) $contribution['contribution_status_id:name']);
        }

        return [
            'status' => $status,
            'contribution_id' => $contributionId,
            'transaction_id' => $transactionId,
        ];
    }

    /**
     * @param array<string, mixed> $registry
     * @param array<string, mixed> $contribution
     * @return array{status: string, contribution_id: int, transaction_id: string|null}
     */
    private function completeRecurringSetup(
        CheckoutSuccess $setupCheckout,
        array $registry,
        array $contribution
    ): array {
        $setupCheckoutId = (string) $setupCheckout->id;
        $lock = CRM_Core_Lock::createScopedLock('data.sumup.recurring.setup.' . $setupCheckoutId);
        if (!$lock->acquire()) {
            throw new PaymentProcessorException(E::ts('This SumUp recurring setup is already being processed.'));
        }

        try {
            $contributionRecurId = (int) ($contribution['contribution_recur_id'] ?? 0);
            $contactId = (int) ($contribution['contact_id'] ?? 0);
            $customerId = (string) ($registry['customer_id'] ?? '');
            if ($contributionRecurId <= 0 || $contactId <= 0 || $customerId === '') {
                throw new PaymentProcessorException(E::ts('The SumUp recurring setup is not linked to CiviCRM.'));
            }
            if (!hash_equals($this->recurringCustomerId($contactId), $customerId)) {
                throw new PaymentProcessorException(E::ts('The SumUp customer does not match the contribution.'));
            }

            $token = trim((string) ($setupCheckout->paymentInstrument->token ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{8,255}$/', $token)) {
                throw new PaymentProcessorException(E::ts('SumUp did not return a reusable payment token.'));
            }
            $instrument = $this->service()->getPaymentInstrument($customerId, $token);
            $paymentTokenId = $this->persistPaymentToken($token, $instrument, $contactId);
            CRM_SumupPaymentProcessor_CheckoutStore::attachPaymentToken($setupCheckoutId, $paymentTokenId);
            ContributionRecur::update(false)
                ->addWhere('id', '=', $contributionRecurId)
                ->setValues([
                    'payment_token_id' => $paymentTokenId,
                    'payment_processor_id' => (int) $this->_paymentProcessor['id'],
                ])
                ->execute();

            $charge = CRM_SumupPaymentProcessor_CheckoutStore::getBySetupCheckoutId($setupCheckoutId);
            if ($charge === null) {
                $charge = $this->createInitialRecurringCharge(
                    $setupCheckoutId,
                    $customerId,
                    $contribution
                );
            }
            if ($charge['state'] === 'PENDING') {
                $this->service()->processWithToken(
                    (string) $charge['checkout_id'],
                    $customerId,
                    $token
                );
            }

            return $this->verifyAndApplyCheckout((string) $charge['checkout_id'], (int) $contribution['id']);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed> $contribution
     * @return array<string, mixed>
     */
    private function createInitialRecurringCharge(
        string $setupCheckoutId,
        string $customerId,
        array $contribution
    ): array {
        $contributionId = (int) $contribution['id'];
        $amount = (float) $contribution['total_amount'];
        $currency = strtoupper((string) $contribution['currency']);
        $reference = CRM_SumupPaymentProcessor_CheckoutService::recurringChargeReference(
            $contributionId,
            $setupCheckoutId
        );
        $service = $this->service();
        $checkout = $service->findByReference($reference);
        if ($checkout === null) {
            $checkout = $service->create(
                contributionId: $contributionId,
                amount: $amount,
                currency: $currency,
                description: E::ts('CiviCRM recurring contribution %1', [1 => $contributionId]),
                webhookUrl: CRM_Mjwshared_Webhook::getWebhookPath((int) $this->_paymentProcessor['id']),
                browserReturnUrl: null,
                customerId: $customerId,
                purpose: 'CHECKOUT',
                checkoutReference: $reference
            );
        }
        $checkoutId = (string) $checkout->id;
        CRM_SumupPaymentProcessor_CheckoutStore::recordCreated(
            $checkoutId,
            $reference,
            $contributionId,
            (int) $this->_paymentProcessor['id'],
            $amount,
            $currency,
            CRM_SumupPaymentProcessor_CheckoutMode::WIDGET,
            null,
            'PAYMENT',
            $customerId,
            $setupCheckoutId
        );
        Contribution::update(false)
            ->addWhere('id', '=', $contributionId)
            ->setValues([
                'payment_processor_id' => (int) $this->_paymentProcessor['id'],
                'trxn_id' => $checkoutId,
            ])
            ->execute();
        return CRM_SumupPaymentProcessor_CheckoutStore::getByCheckoutId($checkoutId);
    }

    private function persistPaymentToken(
        string $token,
        \SumUp\Types\PaymentInstrumentResponse $instrument,
        int $contactId
    ): int {
        $maskedAccountNumber = $this->maskedAccountNumber($instrument);
        $existing = PaymentToken::get(false)
            ->addSelect('id')
            ->addWhere('contact_id', '=', $contactId)
            ->addWhere('payment_processor_id', '=', (int) $this->_paymentProcessor['id'])
            ->addWhere('token', '=', $token)
            ->setLimit(1)
            ->execute()
            ->first();
        if ($existing) {
            if ($maskedAccountNumber !== null) {
                PaymentToken::update(false)
                    ->addWhere('id', '=', (int) $existing['id'])
                    ->setValues([
                        'masked_account_number' => $maskedAccountNumber,
                    ])
                    ->execute();
            }
            return (int) $existing['id'];
        }
        $result = PaymentToken::create(false)
            ->setValues([
                'contact_id' => $contactId,
                'payment_processor_id' => (int) $this->_paymentProcessor['id'],
                'token' => $token,
                'masked_account_number' => $maskedAccountNumber,
            ])
            ->execute()
            ->single();
        return (int) $result['id'];
    }

    private function maskedAccountNumber(\SumUp\Types\PaymentInstrumentResponse $instrument): ?string
    {
        $last4 = $this->cardLast4($instrument->card->last4Digits ?? null);
        if ($last4 === null) {
            return null;
        }
        $brand = $this->cardBrandLabel($this->enumValue($instrument->card->type ?? ''));
        return trim(($brand !== null ? $brand . ' ' : '') . '**** ' . $last4);
    }

    /**
     * Return active SumUp cards which also belong to this contact in CiviCRM.
     *
     * @return list<array{payment_token_id: int, masked_account_number: string}>
     */
    public function getSavedCardsForContact(int $contactId): array
    {
        if ($contactId <= 0) {
            return [];
        }
        $localTokens = PaymentToken::get(false)
            ->addSelect('id', 'token', 'masked_account_number')
            ->addWhere('contact_id', '=', $contactId)
            ->addWhere('payment_processor_id', '=', $this->getProcessorId())
            ->addOrderBy('id', 'DESC')
            ->execute();
        if ($localTokens->count() === 0) {
            return [];
        }

        try {
            $instruments = $this->service()->listPaymentInstruments($this->recurringCustomerId($contactId));
        } catch (Throwable $exception) {
            Civi::log()->warning(sprintf(
                'Unable to list SumUp saved cards for contact %d and processor %d: %s',
                $contactId,
                $this->getProcessorId(),
                $exception->getMessage()
            ));
            return [];
        }

        $remoteByToken = [];
        foreach ($instruments as $instrument) {
            $token = trim((string) $instrument->token);
            if ($token !== '') {
                $remoteByToken[$token] = $instrument;
            }
        }
        $cards = [];
        foreach ($localTokens as $localToken) {
            $token = trim((string) ($localToken['token'] ?? ''));
            if ($token === '' || !isset($remoteByToken[$token])) {
                continue;
            }
            $maskedAccountNumber = $this->maskedAccountNumber($remoteByToken[$token]);
            if ($maskedAccountNumber === null) {
                $maskedAccountNumber = trim((string) ($localToken['masked_account_number'] ?? ''));
            }
            if ($maskedAccountNumber === '') {
                $maskedAccountNumber = E::ts('Saved card');
            }
            if ($maskedAccountNumber !== (string) ($localToken['masked_account_number'] ?? '')) {
                PaymentToken::update(false)
                    ->addWhere('id', '=', (int) $localToken['id'])
                    ->addValue('masked_account_number', $maskedAccountNumber)
                    ->execute();
            }
            $cards[] = [
                'payment_token_id' => (int) $localToken['id'],
                'masked_account_number' => $maskedAccountNumber,
            ];
        }
        return $cards;
    }

    private function recurringCustomerId(int $contactId): string
    {
        if ($contactId <= 0) {
            throw new PaymentProcessorException(E::ts('A contact is required for recurring SumUp payments.'));
        }
        return sprintf(
            'civi_%d_%d_%d',
            (int) CRM_Core_Config::domainID(),
            (int) $this->_paymentProcessor['id'],
            $contactId
        );
    }

    /**
     * Create, recover and verify one scheduled card charge.
     *
     * @return array{status: string, contribution_id: int, transaction_id: string|null}
     */
    public function chargeRecurringContribution(
        int $contributionId,
        int $contributionRecurId,
        int $paymentTokenId,
        string $occurrenceKey
    ): array {
        if (
            $contributionId <= 0
            || $contributionRecurId <= 0
            || $paymentTokenId <= 0
            || !preg_match('/^[0-9]{8}$/', $occurrenceKey)
        ) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp recurring charge identifiers.'));
        }

        $contribution = Contribution::get(false)
            ->addSelect(
                'id',
                'contact_id',
                'total_amount',
                'currency',
                'payment_instrument_id',
                'payment_processor_id',
                'trxn_id',
                'contribution_recur_id',
                'contribution_status_id:name'
            )
            ->addWhere('id', '=', $contributionId)
            ->addWhere('contribution_recur_id', '=', $contributionRecurId)
            ->execute()
            ->single();
        $processorId = $this->getProcessorId();
        if ((int) ($contribution['payment_processor_id'] ?? 0) !== $processorId) {
            throw new PaymentProcessorException(E::ts('The recurring contribution uses another payment processor.'));
        }

        $paymentToken = PaymentToken::get(false)
            ->addSelect('id', 'contact_id', 'payment_processor_id', 'token')
            ->addWhere('id', '=', $paymentTokenId)
            ->execute()
            ->single();
        $contactId = (int) $contribution['contact_id'];
        $token = trim((string) $paymentToken['token']);
        if (
            (int) $paymentToken['contact_id'] !== $contactId
            || (int) $paymentToken['payment_processor_id'] !== $processorId
            || !preg_match('/^[A-Za-z0-9_-]{8,255}$/', $token)
        ) {
            throw new PaymentProcessorException(E::ts('The recurring contribution has no valid SumUp card token.'));
        }

        $customerId = $this->recurringCustomerId($contactId);
        $reference = sprintf(
            'CIVI-%d-%s',
            $contributionId,
            substr(
                hash(
                    'sha256',
                    'recur|' . $contributionRecurId . '|' . $occurrenceKey . '|' . $paymentTokenId
                ),
                0,
                16
            )
        );
        $registry = CRM_SumupPaymentProcessor_CheckoutStore::getByCheckoutReference($reference);
        if ($registry === null) {
            $checkout = $this->service()->findByReference($reference);
            if ($checkout === null) {
                $checkout = $this->service()->create(
                    contributionId: $contributionId,
                    amount: (float) $contribution['total_amount'],
                    currency: (string) $contribution['currency'],
                    description: E::ts('CiviCRM recurring contribution %1', [1 => $contributionRecurId]),
                    webhookUrl: CRM_Mjwshared_Webhook::getWebhookPath($processorId),
                    browserReturnUrl: null,
                    customerId: $customerId,
                    purpose: 'CHECKOUT',
                    checkoutReference: $reference
                );
            }
            $checkoutId = (string) $checkout->id;
            CRM_SumupPaymentProcessor_CheckoutStore::recordCreated(
                $checkoutId,
                $reference,
                $contributionId,
                $processorId,
                (float) $contribution['total_amount'],
                (string) $contribution['currency'],
                CRM_SumupPaymentProcessor_CheckoutMode::WIDGET,
                null,
                'RECURRING_PAYMENT',
                $customerId
            );
            Contribution::update(false)
                ->addWhere('id', '=', $contributionId)
                ->setValues(['trxn_id' => $checkoutId])
                ->execute();
            $registry = CRM_SumupPaymentProcessor_CheckoutStore::getByCheckoutId($checkoutId);
        }

        if (
            $registry['contribution_id'] !== $contributionId
            || $registry['payment_processor_id'] !== $processorId
            || !hash_equals((string) $registry['customer_id'], $customerId)
        ) {
            throw new PaymentProcessorException(E::ts('The SumUp recurring checkout registry is inconsistent.'));
        }

        $checkoutId = (string) $registry['checkout_id'];
        $verified = $this->verifyAndApplyCheckout($checkoutId, $contributionId);
        if ($verified['status'] !== 'PENDING') {
            if (in_array($verified['status'], ['FAILED', 'EXPIRED'], true)) {
                $this->openRecurringRemediation(
                    $contributionRecurId,
                    $contributionId,
                    $checkoutId,
                    $paymentTokenId,
                    'payment_method_failed'
                );
                $verified['status'] = 'CUSTOMER_ACTION_REQUIRED';
            }
            return $verified;
        }

        $processed = $this->service()->processWithToken($checkoutId, $customerId, $token);
        if ($processed instanceof CheckoutAccepted) {
            $this->openRecurringRemediation(
                $contributionRecurId,
                $contributionId,
                $checkoutId,
                $paymentTokenId,
                'sca_required'
            );
            return [
                'status' => 'CUSTOMER_ACTION_REQUIRED',
                'contribution_id' => $contributionId,
                'transaction_id' => null,
            ];
        }

        $verified = $this->verifyAndApplyCheckout($checkoutId, $contributionId);
        if (in_array($verified['status'], ['FAILED', 'EXPIRED'], true)) {
            $this->openRecurringRemediation(
                $contributionRecurId,
                $contributionId,
                $checkoutId,
                $paymentTokenId,
                'payment_method_failed',
                $this->checkoutFailureCode($processed)
            );
            $verified['status'] = 'CUSTOMER_ACTION_REQUIRED';
        }
        return $verified;
    }

    private function openRecurringRemediation(
        int $contributionRecurId,
        int $contributionId,
        string $checkoutId,
        int $paymentTokenId,
        string $reason,
        ?string $providerErrorCode = null
    ): void {
        CRM_SumupPaymentProcessor_RemediationStore::open(
            $contributionRecurId,
            $contributionId,
            $this->getProcessorId(),
            $checkoutId,
            $paymentTokenId,
            $reason,
            $providerErrorCode
        );
    }

    private function checkoutFailureCode(CheckoutSuccess $checkout): ?string
    {
        foreach ($checkout->transactions ?? [] as $transaction) {
            $code = is_array($transaction)
                ? ($transaction['error_code'] ?? null)
                : (is_object($transaction) ? ($transaction->errorCode ?? null) : null);
            if (is_string($code) && $code !== '') {
                return mb_substr($code, 0, 100);
            }
        }
        return null;
    }

    /**
     * Start a customer-present replacement of a recurring card.
     *
     * @return array<string, int|string|null>
     */
    public function startPaymentMethodReplacement(int $contributionRecurId, int $contactId): array
    {
        $lock = CRM_Core_Lock::createScopedLock('data.sumup.remediation.start.' . $contributionRecurId);
        if (!$lock->acquire()) {
            throw new PaymentProcessorException(E::ts('This SumUp card replacement is already being prepared.'));
        }
        try {
            $schedule = $this->getOwnedRecurringSchedule($contributionRecurId, $contactId);
            $contribution = Contribution::get(false)
                ->addSelect('id', 'contribution_status_id:name')
                ->addWhere('contribution_recur_id', '=', $contributionRecurId)
                ->addWhere('is_test', 'IN', [true, false])
                ->addOrderBy('id', 'DESC')
                ->setLimit(1)
                ->execute()
                ->single();
            $remediation = CRM_SumupPaymentProcessor_RemediationStore::getOpen($contributionRecurId);
            if ($remediation === null) {
                $remediation = CRM_SumupPaymentProcessor_RemediationStore::open(
                    $contributionRecurId,
                    (int) $contribution['id'],
                    $this->getProcessorId(),
                    null,
                    (int) $schedule['payment_token_id'],
                    'customer_requested'
                );
            }

            $replacementCheckoutId = trim((string) ($remediation['replacement_checkout_id'] ?? ''));
            if ($replacementCheckoutId !== '') {
                $existing = $this->service()->get($replacementCheckoutId);
                $status = strtoupper((string) $existing->status);
                if ($status === 'PENDING') {
                    return $this->replacementCheckoutConfig($existing, $schedule);
                }
                if ($status === 'PAID') {
                    return $this->completePaymentMethodReplacement(
                        $contributionRecurId,
                        $contactId,
                        $replacementCheckoutId
                    );
                }
                CRM_SumupPaymentProcessor_RemediationStore::resetReplacement((int) $remediation['id']);
            }

            $customerId = $this->recurringCustomerId($contactId);
            $this->service()->ensureCustomer($customerId);
            $reference = sprintf(
                'CIVI-%d-%s',
                (int) $contribution['id'],
                substr(hash('sha256', 'replace|' . $contributionRecurId . '|' . random_bytes(16)), 0, 16)
            );
            $checkout = $this->service()->create(
                contributionId: (int) $contribution['id'],
                amount: (float) $schedule['amount'],
                currency: (string) $schedule['currency'],
                description: E::ts('Replace the card for recurring contribution %1', [1 => $contributionRecurId]),
                webhookUrl: CRM_Mjwshared_Webhook::getWebhookPath($this->getProcessorId()),
                browserReturnUrl: null,
                customerId: $customerId,
                purpose: 'SETUP_RECURRING_PAYMENT',
                checkoutReference: $reference
            );
            CRM_SumupPaymentProcessor_CheckoutStore::recordCreated(
                (string) $checkout->id,
                $reference,
                (int) $contribution['id'],
                $this->getProcessorId(),
                (float) $schedule['amount'],
                (string) $schedule['currency'],
                CRM_SumupPaymentProcessor_CheckoutMode::WIDGET,
                null,
                'CARD_REPLACEMENT',
                $customerId
            );
            CRM_SumupPaymentProcessor_RemediationStore::attachReplacementCheckout(
                (int) $remediation['id'],
                (string) $checkout->id
            );

            return $this->replacementCheckoutConfig($checkout, $schedule);
        } finally {
            $lock->release();
        }
    }

    /**
     * Verify a replacement setup and atomically switch the recurring schedule.
     *
     * @return array<string, int|string|null>
     */
    public function completePaymentMethodReplacement(
        int $contributionRecurId,
        int $contactId,
        string $checkoutId
    ): array {
        $schedule = $this->getOwnedRecurringSchedule($contributionRecurId, $contactId);
        $remediation = CRM_SumupPaymentProcessor_RemediationStore::getOpen($contributionRecurId);
        if (
            $remediation === null
            || !hash_equals((string) ($remediation['replacement_checkout_id'] ?? ''), $checkoutId)
        ) {
            throw new PaymentProcessorException(E::ts('The SumUp replacement session is invalid.'));
        }
        $registry = CRM_SumupPaymentProcessor_CheckoutStore::getByCheckoutId($checkoutId);
        $customerId = $this->recurringCustomerId($contactId);
        if (
            $registry['purpose'] !== 'CARD_REPLACEMENT'
            || $registry['payment_processor_id'] !== $this->getProcessorId()
            || !hash_equals((string) $registry['customer_id'], $customerId)
        ) {
            throw new PaymentProcessorException(E::ts('The SumUp replacement checkout is inconsistent.'));
        }

        $checkout = $this->service()->get($checkoutId);
        $this->service()->assertMatchesContribution(
            $checkout,
            (int) $registry['contribution_id'],
            (float) $schedule['amount'],
            (string) $schedule['currency']
        );
        $status = strtoupper((string) $checkout->status);
        CRM_SumupPaymentProcessor_CheckoutStore::recordVerifiedState(
            $checkoutId,
            $status,
            $this->transactionId($checkout)
        );
        if ($status === 'PENDING') {
            return ['status' => 'PENDING', 'checkout_id' => $checkoutId];
        }
        if (in_array($status, ['FAILED', 'EXPIRED'], true)) {
            CRM_SumupPaymentProcessor_RemediationStore::resetReplacement((int) $remediation['id']);
            return ['status' => $status, 'checkout_id' => $checkoutId];
        }
        if ($status !== 'PAID') {
            throw new PaymentProcessorException(E::ts('Unexpected SumUp replacement checkout state.'));
        }

        $token = trim((string) ($checkout->paymentInstrument->token ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{8,255}$/', $token)) {
            throw new PaymentProcessorException(E::ts('SumUp did not return a reusable replacement card.'));
        }
        $instrument = $this->service()->getPaymentInstrument($customerId, $token);
        $newPaymentTokenId = $this->persistPaymentToken($token, $instrument, $contactId);
        $oldPaymentTokenId = (int) $schedule['payment_token_id'];
        $switchLock = CRM_Core_Lock::createScopedLock('data.sumup.remediation.switch.' . $contributionRecurId);
        if (!$switchLock->acquire()) {
            throw new PaymentProcessorException(E::ts('This SumUp card replacement is already being completed.'));
        }
        try {
            $updated = ContributionRecur::update(false)
                ->addWhere('id', '=', $contributionRecurId)
                ->addWhere('payment_token_id', '=', $oldPaymentTokenId)
                ->setValues([
                    'payment_token_id' => $newPaymentTokenId,
                    'payment_processor_id' => $this->getProcessorId(),
                    'contribution_status_id:name' => 'In Progress',
                    'failure_count' => 0,
                    'failure_retry_date' => null,
                ])
                ->execute();
            if ($updated->count() !== 1) {
                throw new PaymentProcessorException(E::ts(
                    'The recurring contribution payment method changed during replacement.'
                ));
            }
            Contribution::update(false)
                ->addWhere('id', '=', (int) $remediation['contribution_id'])
                ->addWhere('contribution_status_id:name', '=', 'Failed')
                ->addValue('contribution_status_id:name', 'Pending')
                ->execute();
            CRM_SumupPaymentProcessor_RemediationStore::resolve(
                (int) $remediation['id'],
                $newPaymentTokenId
            );
        } finally {
            $switchLock->release();
        }

        if ($oldPaymentTokenId !== $newPaymentTokenId) {
            $this->deactivateUnusedPaymentToken($oldPaymentTokenId, $customerId);
        }
        $last4 = trim((string) ($instrument->card->last4Digits ?? ''));
        return [
            'status' => 'RESOLVED',
            'checkout_id' => $checkoutId,
            'payment_token_id' => $newPaymentTokenId,
            'masked_account_number' => $last4 !== '' ? '**** ' . $last4 : null,
        ];
    }

    /** @return array<string, mixed> */
    private function getOwnedRecurringSchedule(int $contributionRecurId, int $contactId): array
    {
        $schedule = ContributionRecur::get(false)
            ->addSelect(
                'id',
                'contact_id',
                'amount',
                'currency',
                'payment_processor_id',
                'payment_token_id',
                'contribution_status_id:name'
            )
            ->addWhere('id', '=', $contributionRecurId)
            ->execute()
            ->single();
        if (
            (int) $schedule['contact_id'] !== $contactId
            || (int) $schedule['payment_processor_id'] !== $this->getProcessorId()
            || (int) $schedule['payment_token_id'] <= 0
            || (string) $schedule['contribution_status_id:name'] !== 'In Progress'
        ) {
            throw new PaymentProcessorException(E::ts('This SumUp recurring contribution cannot be managed.'));
        }
        return $schedule;
    }

    /**
     * @param array<string, mixed> $schedule
     * @return array<string, int|string|null>
     */
    private function replacementCheckoutConfig(
        \SumUp\Types\Checkout|CheckoutSuccess $checkout,
        array $schedule
    ): array {
        return [
            'status' => $this->enumValue($checkout->status ?? 'PENDING'),
            'checkout_id' => (string) $checkout->id,
            'amount' => number_format((float) $schedule['amount'], 2, '.', ''),
            'currency' => strtoupper((string) $schedule['currency']),
            'locale' => CRM_SumupPaymentProcessor_CheckoutMode::getLocale(),
            'mode' => CRM_SumupPaymentProcessor_CheckoutMode::WIDGET,
            'public_key' => '',
            'country_code' => CRM_SumupPaymentProcessor_CheckoutMode::getMerchantCountryCode(),
            'browser_return_url' => '',
            'cancel_url' => '',
        ];
    }

    private function deactivateUnusedPaymentToken(int $paymentTokenId, string $customerId): void
    {
        $inUse = ContributionRecur::get(false)
            ->addSelect('id')
            ->addWhere('payment_token_id', '=', $paymentTokenId)
            ->addWhere('contribution_status_id:name', '=', 'In Progress')
            ->setLimit(1)
            ->execute()
            ->first();
        if ($inUse) {
            return;
        }
        $paymentToken = PaymentToken::get(false)
            ->addSelect('token')
            ->addWhere('id', '=', $paymentTokenId)
            ->addWhere('payment_processor_id', '=', $this->getProcessorId())
            ->execute()
            ->first();
        if (empty($paymentToken['token'])) {
            return;
        }
        try {
            $this->service()->deactivatePaymentInstrument($customerId, (string) $paymentToken['token']);
        } catch (Throwable $exception) {
            Civi::log()->warning(sprintf(
                'SumUp old payment token %d could not be deactivated: %s',
                $paymentTokenId,
                $exception->getMessage()
            ));
        }
    }

    /**
     * Verify a Solo transaction from SumUp's authoritative Transactions API.
     *
     * @return array{status: string, contribution_id: int, transaction_id: string|null}
     */
    public function verifyAndApplyReaderCheckout(string $clientTransactionId): array
    {
        if (!CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($clientTransactionId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp terminal transaction identifier.'));
        }
        $registry = CRM_SumupPaymentProcessor_CheckoutStore::getByCheckoutId($clientTransactionId);
        if (
            $registry['checkout_mode'] !== CRM_SumupPaymentProcessor_CheckoutMode::SOLO
            || $registry['payment_processor_id'] !== (int) $this->_paymentProcessor['id']
        ) {
            throw new PaymentProcessorException(E::ts(
                'SumUp terminal checkout registry does not match the processor.'
            ));
        }

        $transaction = $this->service()->getTransactionByClientTransactionId($clientTransactionId);
        if (
            !hash_equals($this->getMerchantCode(), (string) $transaction->merchantCode)
            || !hash_equals($clientTransactionId, (string) $transaction->clientTransactionId)
            || (int) round($registry['amount'] * 100) !== (int) round((float) $transaction->amount * 100)
            || strtoupper($registry['currency']) !== strtoupper((string) $transaction->currency)
        ) {
            throw new PaymentProcessorException(E::ts(
                'SumUp terminal transaction does not match its CiviCRM contribution.'
            ));
        }

        $contribution = Contribution::get(false)
            ->addSelect(
                'id',
                'total_amount',
                'currency',
                'payment_instrument_id',
                'payment_processor_id',
                'trxn_id',
                'contribution_status_id:name'
            )
            ->addWhere('id', '=', $registry['contribution_id'])
            ->execute()
            ->single();
        if (
            (int) round((float) $contribution['total_amount'] * 100) !== (int) round($registry['amount'] * 100)
            || strtoupper((string) $contribution['currency']) !== strtoupper($registry['currency'])
        ) {
            throw new PaymentProcessorException(E::ts('SumUp terminal amount does not match the contribution.'));
        }

        $providerStatus = strtoupper((string) $transaction->status);
        $status = match ($providerStatus) {
            'SUCCESSFUL' => 'PAID',
            'PENDING' => 'PENDING',
            'FAILED', 'CANCELLED' => 'FAILED',
            default => throw new PaymentProcessorException(E::ts(
                'Unsupported SumUp terminal transaction status: %1.',
                [1 => $providerStatus]
            )),
        };
        $transactionId = is_string($transaction->id) && $transaction->id !== ''
            ? $transaction->id
            : $clientTransactionId;
        CRM_SumupPaymentProcessor_CheckoutStore::recordVerifiedState(
            $clientTransactionId,
            $status,
            $transactionId
        );
        if ($status === 'PAID') {
            $this->completeContribution($contribution, $transactionId);
        } elseif ($status === 'FAILED') {
            $this->failPendingContribution(
                (int) $contribution['id'],
                (string) $contribution['contribution_status_id:name']
            );
        }

        return [
            'status' => $status,
            'contribution_id' => (int) $contribution['id'],
            'transaction_id' => $transactionId,
        ];
    }

    public function buildSignedWidgetUrl(int $contributionId, string $returnUrl, string $cancelUrl): string
    {
        $params = [
            'contribution_id' => $contributionId,
            'processor_id' => (int) $this->_paymentProcessor['id'],
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'expires' => time() + 7200,
        ];
        $signer = new CRM_Utils_Signer(self::getBrowserReturnSigningKey(), self::WIDGET_SIGNED_FIELDS);
        $params['_sgn'] = $signer->sign($params);

        return CRM_Utils_System::url('civicrm/sumup/widget', $params, true, null, false, true);
    }

    /**
     * Build browser-safe saved-card options for an existing checkout.
     *
     * @return array{
     *   saved_payment_methods: list<array{payment_token_id: int, masked_account_number: string}>,
     *   saved_payment_action: array<string, int|string>|null
     * }
     */
    public function getSavedCardCheckoutConfig(string $checkoutId, int $contributionId): array
    {
        $registry = CRM_SumupPaymentProcessor_CheckoutStore::getByCheckoutId($checkoutId);
        if (
            $registry['contribution_id'] !== $contributionId
            || $registry['payment_processor_id'] !== $this->getProcessorId()
            || $registry['purpose'] !== 'PAYMENT'
            || $registry['checkout_mode'] === CRM_SumupPaymentProcessor_CheckoutMode::HOSTED
        ) {
            return [
                'saved_payment_methods' => [],
                'saved_payment_action' => null,
            ];
        }
        $contribution = Contribution::get(false)
            ->addSelect('contact_id')
            ->addWhere('id', '=', $contributionId)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute()
            ->single();
        $cards = $this->getSavedCardsForContact((int) $contribution['contact_id']);
        return [
            'saved_payment_methods' => $cards,
            'saved_payment_action' => $cards !== []
                ? $this->buildSavedCardAction($contributionId, $checkoutId)
                : null,
        ];
    }

    /** @return array<string, int|string> */
    private function buildSavedCardAction(int $contributionId, string $checkoutId): array
    {
        $params = [
            'checkout_id' => $checkoutId,
            'contribution_id' => $contributionId,
            'processor_id' => $this->getProcessorId(),
            'expires' => time() + 7200,
        ];
        $signer = new CRM_Utils_Signer(self::getBrowserReturnSigningKey(), self::SAVED_CARD_SIGNED_FIELDS);
        return [
            'checkoutId' => $checkoutId,
            'contributionId' => $contributionId,
            'processorId' => $this->getProcessorId(),
            'expires' => $params['expires'],
            'signature' => $signer->sign($params),
        ];
    }

    /**
     * Process a new contribution with one existing card selected by the payer.
     *
     * @return array<string, mixed>
     */
    public function payContributionWithSavedCard(
        int $contributionId,
        string $checkoutId,
        int $paymentTokenId,
        int $expires,
        string $signature
    ): array {
        $signedParams = [
            'checkout_id' => $checkoutId,
            'contribution_id' => $contributionId,
            'processor_id' => $this->getProcessorId(),
            'expires' => $expires,
        ];
        $signer = new CRM_Utils_Signer(self::getBrowserReturnSigningKey(), self::SAVED_CARD_SIGNED_FIELDS);
        if (
            $expires < time()
            || !preg_match('/^[A-Za-z0-9]{4}_[a-f0-9]{32}$/', $signature)
            || !$signer->validate($signature, $signedParams)
        ) {
            throw new PaymentProcessorException(E::ts('The saved-card payment authorisation is invalid or expired.'));
        }

        $registry = CRM_SumupPaymentProcessor_CheckoutStore::getByCheckoutId($checkoutId);
        if (
            $registry['contribution_id'] !== $contributionId
            || $registry['payment_processor_id'] !== $this->getProcessorId()
            || $registry['purpose'] !== 'PAYMENT'
        ) {
            throw new PaymentProcessorException(E::ts('The saved-card checkout is inconsistent.'));
        }
        $contribution = Contribution::get(false)
            ->addSelect(
                'id',
                'contact_id',
                'total_amount',
                'currency',
                'payment_processor_id',
                'contribution_status_id:name'
            )
            ->addWhere('id', '=', $contributionId)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute()
            ->single();
        if ((int) ($contribution['payment_processor_id'] ?? 0) !== $this->getProcessorId()) {
            throw new PaymentProcessorException(E::ts('The contribution uses another payment processor.'));
        }
        $paymentToken = PaymentToken::get(false)
            ->addSelect('id', 'contact_id', 'payment_processor_id', 'token')
            ->addWhere('id', '=', $paymentTokenId)
            ->execute()
            ->single();
        $contactId = (int) $contribution['contact_id'];
        $providerToken = trim((string) $paymentToken['token']);
        $customerId = $this->recurringCustomerId($contactId);
        if (
            (int) $paymentToken['contact_id'] !== $contactId
            || (int) $paymentToken['payment_processor_id'] !== $this->getProcessorId()
            || !hash_equals((string) ($registry['customer_id'] ?? ''), $customerId)
            || !preg_match('/^[A-Za-z0-9_-]{8,255}$/', $providerToken)
        ) {
            throw new PaymentProcessorException(E::ts('The selected SumUp card does not belong to this contribution.'));
        }
        $this->service()->getPaymentInstrument($customerId, $providerToken);
        CRM_SumupPaymentProcessor_CheckoutStore::attachPaymentToken($checkoutId, $paymentTokenId);

        $verified = $this->verifyAndApplyCheckout($checkoutId, $contributionId);
        if ($verified['status'] !== 'PENDING') {
            return $verified;
        }
        $processed = $this->service()->processWithToken($checkoutId, $customerId, $providerToken);
        if ($processed instanceof CheckoutAccepted) {
            return [
                'status' => 'CUSTOMER_ACTION_REQUIRED',
                'contribution_id' => $contributionId,
                'transaction_id' => null,
                'next_step' => $this->normaliseNextStep($processed),
            ];
        }
        return $this->verifyAndApplyCheckout($checkoutId, $contributionId);
    }

    /** @return array{url: string, method: string, payload: array<string, scalar|null>} */
    private function normaliseNextStep(CheckoutAccepted $accepted): array
    {
        $url = trim((string) ($accepted->nextStep->url ?? ''));
        $method = strtoupper(trim((string) ($accepted->nextStep->method ?? 'GET')));
        if (!str_starts_with($url, 'https://') || !in_array($method, ['GET', 'POST'], true)) {
            throw new PaymentProcessorException(E::ts('SumUp returned an invalid authentication step.'));
        }
        $payload = [];
        foreach ((array) ($accepted->nextStep->payload ?? []) as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $payload[$name] = $value;
            }
        }
        return ['url' => $url, 'method' => $method, 'payload' => $payload];
    }

    public static function getBrowserReturnSigningKey(): string
    {
        $siteKey = defined('CIVICRM_SITE_KEY') ? (string) constant('CIVICRM_SITE_KEY') : '';
        if ($siteKey === '') {
            throw new PaymentProcessorException(E::ts('The CiviCRM site key is not configured.'));
        }

        return hash_hmac('sha256', 'sumup-browser-return-v1', $siteKey);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function paymentDescription(array $params, int $contributionId): string
    {
        $description = trim((string) ($params['description'] ?? ''));
        return $description !== '' ? $description : E::ts('CiviCRM contribution %1', [1 => $contributionId]);
    }

    private function getMerchantCode(): string
    {
        return trim((string) ($this->_paymentProcessor['user_name'] ?? ''));
    }

    private function assertFullRefundAmount(string $transactionReference, float $refundAmount): void
    {
        $payments = Payment::get(false)
            ->addSelect('total_amount')
            ->addWhere('trxn_id', '=', $transactionReference)
            ->addWhere('payment_processor_id', '=', $this->getProcessorId())
            ->addWhere('total_amount', '>', 0)
            ->setLimit(2)
            ->execute();
        if (count($payments) !== 1) {
            throw new PaymentProcessorException(
                E::ts('The SumUp refund cannot be requested because the original CiviCRM payment could not be found.')
            );
        }

        $originalAmount = (float) $payments->first()['total_amount'];
        if (abs($originalAmount - $refundAmount) > 0.0001) {
            throw new PaymentProcessorException(
                E::ts('SumUp supports full refunds only through this integration. Partial refunds are unavailable.')
            );
        }
    }

    private function getRefundedMinorUnits(TransactionFull $transaction): int
    {
        $refundedMinor = 0;
        foreach ($transaction->events ?? [] as $event) {
            if (
                $this->enumValue($event->type) === 'REFUND'
                && in_array(
                    $this->enumValue($event->status),
                    ['PENDING', 'REFUNDED', 'SUCCESSFUL'],
                    true
                )
            ) {
                $refundedMinor += (int) round((float) $event->amount * 100);
            }
        }
        return $refundedMinor;
    }

    /** @return list<string> */
    private function getRefundEventIds(TransactionFull $transaction): array
    {
        $ids = [];
        foreach ($transaction->events ?? [] as $event) {
            if ($this->enumValue($event->type) === 'REFUND' && $event->id !== null) {
                $ids[] = (string) $event->id;
            }
        }
        return $ids;
    }

    /**
     * @param list<string> $existingEventIds
     */
    private function findNewRefundEventId(
        TransactionFull $transaction,
        array $existingEventIds,
        int $requestedMinor
    ): ?string {
        foreach ($transaction->events ?? [] as $event) {
            $eventId = $event->id === null ? '' : (string) $event->id;
            if (
                $eventId !== ''
                && !in_array($eventId, $existingEventIds, true)
                && $this->enumValue($event->type) === 'REFUND'
                && in_array($this->enumValue($event->status), ['PENDING', 'REFUNDED', 'SUCCESSFUL'], true)
                && (int) round((float) $event->amount * 100) === $requestedMinor
            ) {
                return $eventId;
            }
        }
        return null;
    }

    private function findUnrecordedRefundEventId(TransactionFull $transaction, int $requestedMinor): ?string
    {
        $candidates = [];
        $transactionId = trim((string) $transaction->id);
        foreach ($transaction->events ?? [] as $event) {
            $eventId = $event->id === null ? '' : (string) $event->id;
            if (
                $eventId === ''
                || $this->enumValue($event->type) !== 'REFUND'
                || !in_array($this->enumValue($event->status), ['PENDING', 'REFUNDED', 'SUCCESSFUL'], true)
                || (int) round((float) $event->amount * 100) !== $requestedMinor
            ) {
                continue;
            }

            $refundTransactionId = $this->refundTransactionId($transactionId, $eventId);
            $isRecorded = Payment::get(false)
                ->addSelect('id')
                ->addWhere('trxn_id', '=', $refundTransactionId)
                ->setLimit(1)
                ->execute()
                ->count() > 0;
            if (!$isRecorded) {
                $candidates[] = $eventId;
            }
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function refundTransactionId(string $transactionId, string $eventId): string
    {
        return 'sumup-refund-' . $transactionId . '-' . $eventId;
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? strtoupper((string) $value->value) : strtoupper((string) $value);
    }

    private function service(): CRM_SumupPaymentProcessor_CheckoutService
    {
        return new CRM_SumupPaymentProcessor_CheckoutService(
            (string) ($this->_paymentProcessor['password'] ?? ''),
            (string) ($this->_paymentProcessor['user_name'] ?? '')
        );
    }

    private function transactionId(CheckoutSuccess $checkout): ?string
    {
        foreach ([$checkout->transactionId, $checkout->transactionCode] as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z0-9_-]{4,100}$/', $candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $contribution
     */
    private function completeContribution(array $contribution, string $transactionId): void
    {
        $contributionId = (int) $contribution['id'];
        $processorId = (int) $this->_paymentProcessor['id'];
        $lock = CRM_Core_Lock::createScopedLock('data.sumup.contribution.' . $contributionId);
        if (!$lock->acquire()) {
            throw new PaymentProcessorException(E::ts('This SumUp payment is already being processed.'));
        }

        try {
            $existing = Payment::get(false)
                ->addSelect('id')
                ->addWhere('contribution_id', '=', $contributionId)
                ->addWhere('payment_processor_id', '=', $processorId)
                ->addWhere('trxn_id', '=', $transactionId)
                ->addWhere('total_amount', '>', 0)
                ->setLimit(1)
                ->execute()
                ->first();
            if (!empty($existing['id'])) {
                return;
            }

            $statusName = (string) ($contribution['contribution_status_id:name'] ?? '');
            if (in_array($statusName, ['Completed', 'Refunded'], true)) {
                throw new PaymentProcessorException(E::ts(
                    'Contribution %1 is already %2 without the matching SumUp payment.',
                    [1 => $contributionId, 2 => $statusName]
                ));
            }

            $values = [
                'contribution_id' => $contributionId,
                'total_amount' => (float) $contribution['total_amount'],
                'payment_processor_id' => $processorId,
                'payment_instrument_id' => $this->getPaymentInstrumentID(),
                'trxn_id' => $transactionId,
                'trxn_date' => date('Y-m-d H:i:s'),
            ];
            $values += $this->getFinancialCardMetadata($transactionId);
            Payment::create(false)
                ->setValues($values)
                ->setNotificationForCompleteOrder(true)
                ->execute();
        } finally {
            $lock->release();
        }
    }

    /**
     * Retrieve optional card details without weakening payment completion.
     *
     * @return array{card_type_id?: int, pan_truncation?: string}
     */
    private function getFinancialCardMetadata(string $transactionId): array
    {
        try {
            $transaction = $this->service()->getTransaction($transactionId);
        } catch (Throwable $exception) {
            Civi::log()->warning(sprintf(
                'Unable to retrieve SumUp card metadata for transaction %s: %s',
                $transactionId,
                $exception->getMessage()
            ));
            return [];
        }

        $metadata = [];
        $last4 = $this->cardLast4($transaction->card->last4Digits ?? null);
        if ($last4 !== null) {
            $metadata['pan_truncation'] = $last4;
        }
        $cardName = $this->civiCardName($this->enumValue($transaction->card->type ?? ''));
        if ($cardName !== null) {
            $cardTypeId = CRM_Core_PseudoConstant::getKey(
                'CRM_Financial_DAO_FinancialTrxn',
                'card_type_id',
                $cardName
            );
            if ($cardTypeId !== null && $cardTypeId !== false) {
                $metadata['card_type_id'] = (int) $cardTypeId;
            }
        }
        return $metadata;
    }

    private function cardLast4(mixed $last4): ?string
    {
        $value = trim((string) $last4);
        return preg_match('/^[0-9]{4}$/', $value) === 1 ? $value : null;
    }

    private function civiCardName(string $sumupCardType): ?string
    {
        return match ($sumupCardType) {
            'VISA', 'VISA_ELECTRON', 'VISA_VPAY', 'VPAY' => 'Visa',
            'MASTERCARD', 'MAESTRO' => 'MasterCard',
            'AMEX' => 'Amex',
            'DISCOVER' => 'Discover',
            default => null,
        };
    }

    private function cardBrandLabel(string $sumupCardType): ?string
    {
        return match ($sumupCardType) {
            'VISA' => 'Visa',
            'VISA_ELECTRON' => 'Visa Electron',
            'VISA_VPAY', 'VPAY' => 'V Pay',
            'MASTERCARD' => 'MasterCard',
            'MAESTRO' => 'Maestro',
            'AMEX' => 'Amex',
            'DISCOVER' => 'Discover',
            default => null,
        };
    }

    private function failPendingContribution(int $contributionId, string $currentStatus): void
    {
        if ($currentStatus !== 'Pending') {
            return;
        }
        Contribution::update(false)
            ->addWhere('id', '=', $contributionId)
            ->addWhere('contribution_status_id:name', '=', 'Pending')
            ->addValue('contribution_status_id:name', 'Failed')
            ->execute();
    }

    private function finishWebhook(int $webhookId, string $status, string $message): void
    {
        if ($webhookId <= 0) {
            return;
        }
        PaymentprocessorWebhook::update(false)
            ->addWhere('id', '=', $webhookId)
            ->setValues([
                'status' => $status,
                'message' => mb_substr($message, 0, 1024),
                'processed_date' => 'now',
            ])
            ->execute();
    }
}
