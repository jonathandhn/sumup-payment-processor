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

    /**
     * @return list<int>
     */
    public function getRelatedProcessorIds(): array
    {
        $currentId = $this->getProcessorId();
        if ($currentId <= 0) {
            return [];
        }

        try {
            $current = PaymentProcessor::get(false)
                ->addSelect('name')
                ->addWhere('id', '=', $currentId)
                ->addWhere('is_test', 'IN', [true, false])
                ->execute()
                ->first();
            if (!$current || empty($current['name'])) {
                return [$currentId];
            }

            $matches = PaymentProcessor::get(false)
                ->addSelect('id')
                ->addWhere('class_name', '=', 'Payment_Sumup')
                ->addWhere('name', '=', $current['name'])
                ->addWhere('is_test', 'IN', [true, false])
                ->execute();

            $ids = [];
            foreach ($matches as $match) {
                $ids[] = (int) $match['id'];
            }
            return !empty($ids) ? $ids : [$currentId];
        } catch (\Throwable) {
            return [$currentId];
        }
    }

    /**
     * Return the SumUp-authoritative profile for this processor's merchant account.
     *
     * @return array{
     *   merchant_code: string,
     *   business_name: string,
     *   company_name: string,
     *   country: string,
     *   currency: string
     * }
     */
    public function getVerifiedMerchantProfile(): array
    {
        return $this->service()->getMerchantProfile($this->getProcessorId());
    }

    public function supportsRefund(): bool
    {
        return !empty($this->_paymentProcessor['user_name'])
            && !empty($this->_paymentProcessor['password'])
            && class_exists(\SumUp\SumUp::class);
    }

    /**
     * CiviCRM owns the SumUp recurring schedule and can stop future charges.
     */
    protected function supportsCancelRecurring(): bool
    {
        return true;
    }

    /**
     * A SumUp plan cannot remain active remotely when it is cancelled locally.
     */
    protected function supportsCancelRecurringNotifyOptional(): bool
    {
        return false;
    }

    /**
     * The saved card is managed by the extension rather than by SumUp's site.
     */
    protected function supportsUpdateSubscriptionBillingInfo(): bool
    {
        return true;
    }

    /**
     * Route CiviCRM's native billing-details action to the SumUp card page.
     *
     * @param int|null $entityID
     * @param string|null $entity
     * @param string $action
     */
    public function subscriptionURL($entityID = null, $entity = null, $action = 'cancel'): ?string
    {
        if ($action !== 'billing' || $entity !== 'recur' || !$entityID) {
            return parent::subscriptionURL($entityID, $entity, $action);
        }

        $contactId = (int) CRM_Core_DAO::getFieldValue(
            'CRM_Contribute_DAO_ContributionRecur',
            (int) $entityID,
            'contact_id'
        );
        if ($contactId <= 0) {
            return null;
        }

        $query = [
            'recur_id' => (int) $entityID,
            'cid' => $contactId,
        ];
        if ((int) CRM_Core_Session::singleton()->get('userID') !== $contactId) {
            $query['cs'] = CRM_Contact_BAO_Contact_Utils::generateChecksum($contactId, null, 'inf');
        }

        return CRM_Utils_System::url(
            'civicrm/sumup/payment-method/replace',
            $query,
            true,
            null,
            false,
            true
        );
    }

    /**
     * Validate a native CiviCRM cancellation before core updates the schedule.
     *
     * SumUp stores a reusable payment instrument, not a remote subscription,
     * so there is no provider cancellation request to send.
     *
     * @return array{message: string}
     */
    public function doCancelRecurring(PropertyBag $propertyBag): array
    {
        if (!$propertyBag->has('contributionRecurID')) {
            throw new PaymentProcessorException(E::ts('The SumUp recurring contribution ID is missing.'));
        }

        $recurId = (int) $propertyBag->getContributionRecurID();
        $lock = new CRM_Core_Lock('civicrm.job.SumupRecurringCard');
        if (!$lock->acquire()) {
            throw new PaymentProcessorException(
                E::ts('A SumUp recurring payment is currently being processed. Please retry the cancellation shortly.')
            );
        }

        try {
            $this->getOwnedActiveRecurringSchedule($recurId);
        } finally {
            $lock->release();
        }

        return [
            'message' => E::ts(
                'Future SumUp charges were stopped in CiviCRM. Payments already collected were not refunded.'
            ),
        ];
    }

    /** @return list<string> */
    public function getEditableRecurringScheduleFields(): array
    {
        return ['amount'];
    }

    /**
     * Validate an amount change before CiviCRM updates the recurring schedule.
     *
     * @param string $message
     * @param array<string, mixed> $params
     */
    public function changeSubscriptionAmount(&$message = '', $params = []): bool
    {
        $recurId = (int) ($params['contributionRecurID'] ?? $params['id'] ?? 0);
        $schedule = $this->getOwnedActiveRecurringSchedule($recurId);
        $amount = (float) ($params['amount'] ?? 0);
        $currency = (string) $schedule['currency'];
        $precision = CRM_Utils_Money::getCurrencyPrecision($currency);
        $roundedAmount = round($amount, $precision);
        if ($amount <= 0 || abs($amount - $roundedAmount) > 0.0000001) {
            throw new PaymentProcessorException(
                E::ts('The new recurring amount is invalid for currency %1.', [1 => $currency])
            );
        }
        if (round((float) $schedule['amount'], $precision) === $roundedAmount) {
            throw new PaymentProcessorException(E::ts('The recurring amount is unchanged.'));
        }

        $message = E::ts(
            'The SumUp schedule is managed by CiviCRM. The new amount applies to the next payment not yet created.'
        );
        return true;
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
            CRM_Core_Region::instance('billing-block')->add([
                'scriptUrl' => CRM_Core_Resources::singleton()->getUrl(
                    E::LONG_NAME,
                    'js/civicrmSumUp.js'
                ),
                'weight' => 90,
            ]);
            $configuredMode = CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode();
            if (!CRM_SumupPaymentProcessor_CheckoutMode::usesHosted($configuredMode)) {
                CRM_Core_Region::instance('billing-block')->add([
                    'styleUrl' => CRM_Core_Resources::singleton()->getUrl(
                        E::LONG_NAME,
                        'ang/afSumUp/sumUp.css'
                    ),
                    'weight' => -10,
                ]);
                CRM_Core_Region::instance('billing-block')->add([
                    'scriptUrl' => 'https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js',
                    'weight' => 70,
                ]);
                if (CRM_SumupPaymentProcessor_CheckoutMode::usesWallet($configuredMode)) {
                    CRM_Core_Region::instance('billing-block')->add([
                        'scriptUrl' => 'https://js.sumup.com/swift-checkout/v1/sdk.js',
                        'weight' => 75,
                    ]);
                }
                CRM_Core_Region::instance('billing-block')->add([
                    'scriptUrl' => CRM_Core_Resources::singleton()->getUrl(
                        E::LONG_NAME,
                        'js/checkout.js'
                    ),
                    'weight' => 80,
                ]);
            }
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
                E::ts('No paired and active SumUp card reader is available for this processor.'),
                E::ts('SumUp card reader'),
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
            $errors['sumup_reader_id'] = E::ts('Please select an available SumUp card reader.');
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
            $minRefundableAmount = null;
            if (is_array($responseBody) && !empty($responseBody['errors']) && is_array($responseBody['errors'])) {
                foreach ($responseBody['errors'] as $error) {
                    if (
                        is_array($error)
                        && ($error['code'] ?? '') === 'min_amount'
                        && isset($error['min_refundable_amount'])
                    ) {
                        $minRefundableAmount = (float) $error['min_refundable_amount'];
                        break;
                    }
                }
            }

            Civi::log()->error(sprintf(
                'SumUp refund request failed: status=%d detail=%s',
                $exception->getStatusCode(),
                $providerDetail !== '' ? $providerDetail : $exception->getMessage()
            ));

            if ($minRefundableAmount !== null) {
                throw new PaymentProcessorException(E::ts(
                    'SumUp does not allow a partial refund for this transaction (minimum required: %1 %2).',
                    [
                        1 => sprintf('%.2f', $minRefundableAmount),
                        2 => $currency ?? 'EUR',
                    ]
                ));
            }
            if ($providerDetail !== '' && $providerDetail !== 'Refund failed.') {
                throw new PaymentProcessorException(
                    E::ts('SumUp refund failed: %1', [1 => $providerDetail])
                );
            }
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

        if (self::isDrupalWebformAjaxRequest()) {
            CRM_Core_Page_AJAX::returnJsonResponse([[
                'command' => 'sumupMountCheckout',
                'checkout' => $checkoutData,
                'fallback_url' => $checkoutData['browser_return_url'],
            ]]);
        }

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
            throw new PaymentProcessorException(E::ts('The selected SumUp card reader is unavailable.'));
        }

        $readerService = CRM_SumupPaymentProcessor_ReaderService::fromPaymentProcessorId(
            (int) $this->_paymentProcessor['id']
        );
        $remoteStatus = $readerService->getStatus((string) $reader['reader_id']);
        $deviceStatus = $this->enumValue($remoteStatus->data->status);
        $deviceState = $this->enumValue($remoteStatus->data->state);
        if ($deviceStatus !== 'ONLINE' || $deviceState !== 'IDLE') {
            throw new PaymentProcessorException(E::ts(
                'The selected SumUp card reader is not ready (%1 / %2).',
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
     *   merchant_code: string,
     *   business_name: string,
     *   country_code: string,
     *   wallets_allowed: bool,
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
     *   merchant_code: string,
     *   business_name: string,
     *   country_code: string,
     *   wallets_allowed: bool,
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
                'contribution_recur_id',
                'is_test'
            )
            ->addWhere('id', '=', $contributionId)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute()
            ->single();
        $amount = (float) $contribution['total_amount'];
        $currency = strtoupper((string) $contribution['currency']);
        $merchantProfile = $this->getVerifiedMerchantProfile();
        if ($merchantProfile['currency'] !== $currency) {
            throw new PaymentProcessorException(E::ts(
                'Currency %1 is not supported by this SumUp merchant account (%2).',
                [1 => $currency, 2 => $merchantProfile['currency']]
            ));
        }

        $browserReturnUrl = $this->buildSignedWidgetUrl($contributionId, $returnUrl, $cancelUrl);
        $checkoutMode = CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode();
        $contributionRecurId = (int) ($contribution['contribution_recur_id'] ?? 0);
        $providerPurpose = 'CHECKOUT';
        $registryPurpose = 'PAYMENT';
        $customerId = null;
        $savedCards = [];
        if ($contributionRecurId > 0) {
            $this->assertRecurringPlanPolicy(
                (int) $contribution['contact_id'],
                $contributionRecurId,
                (bool) $contribution['is_test']
            );
            if ($hosted || $checkoutMode !== CRM_SumupPaymentProcessor_CheckoutMode::WIDGET) {
                throw new PaymentProcessorException(E::ts(
                    'Recurring SumUp payments require the Card Widget checkout mode.'
                ));
            }
            $providerPurpose = 'SETUP_RECURRING_PAYMENT';
            $registryPurpose = 'SETUP_RECURRING_PAYMENT';
            $customerId = $this->recurringCustomerId((int) $contribution['contact_id']);
            $this->service()->ensureCustomer($customerId);
            $savedCards = $this->getSavedCardsForContact((int) $contribution['contact_id']);
        } elseif (!$hosted) {
            $savedCards = $this->getSavedCardsForContact((int) $contribution['contact_id']);
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
            'public_key' => CRM_SumupPaymentProcessor_CheckoutMode::usesWallet($checkoutMode)
                ? $this->getPublicMerchantKey()
                : '',
            'merchant_code' => $this->getMerchantCode(),
            'business_name' => $merchantProfile['business_name'],
            'country_code' => CRM_SumupPaymentProcessor_CheckoutMode::getMerchantCountryCode(
                $merchantProfile['country']
            ),
            'wallets_allowed' => ($contributionRecurId === 0)
                && CRM_SumupPaymentProcessor_CheckoutMode::usesWallet($checkoutMode),
            'browser_return_url' => $returnUrl !== '' ? $returnUrl : $browserReturnUrl,
            'cancel_url' => $cancelUrl,
            'hosted_checkout_url' => $checkout->hostedCheckoutUrl,
            'saved_payment_methods' => $savedCards,
            'saved_payment_action' => $savedPaymentAction,
        ];
    }

    private function assertRecurringPlanPolicy(int $contactId, int $currentRecurId, bool $isTest): void
    {
        if (!Civi::settings()->get('sumup_single_active_recurring_plan')) {
            return;
        }
        $processorIds = [];
        foreach (
            PaymentProcessor::get(false)
                ->addSelect('id')
                ->addWhere('class_name', '=', 'Payment_Sumup')
                ->addWhere('is_test', '=', $isTest)
                ->execute() as $processor
        ) {
            $processorIds[] = (int) $processor['id'];
        }
        if ($processorIds === []) {
            return;
        }
        $existing = ContributionRecur::get(false)
            ->addSelect('id')
            ->addWhere('contact_id', '=', $contactId)
            ->addWhere('id', '!=', $currentRecurId)
            ->addWhere('payment_processor_id', 'IN', $processorIds)
            ->addWhere('contribution_status_id:name', '=', 'In Progress')
            ->addWhere('is_test', '=', $isTest)
            ->setLimit(1)
            ->execute()
            ->first();
        if (!$existing) {
            return;
        }

        $managementUrl = CRM_Utils_System::url(
            'civicrm/sumup/payment-methods',
            [],
            true,
            null,
            false,
            true
        );
        throw new PaymentProcessorException(E::ts(
            'You already have an active recurring contribution. Sign in to view, update or stop it here: %1',
            [1 => $managementUrl]
        ));
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
        if (self::isDrupalAjaxRequest()) {
            if (class_exists('\\Drupal\\webform\\Ajax\\WebformRefreshCommand')) {
                $command = (new \Drupal\webform\Ajax\WebformRefreshCommand($paymentUrl))->render();
                $command['paymentRedirect'] = true;
                $command['paymentProvider'] = 'sumup';
                CRM_Core_Page_AJAX::returnJsonResponse([$command]);
            }

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

        $acceptedEvents = [
            'CHECKOUT_STATUS_CHANGED',
            'solo.transaction.updated',
            'TRANSACTION_SUCCESSFUL',
            'TRANSACTION_FAILED',
            'TRANSACTION_REFUNDED',
            'REFUND_SUCCESSFUL',
            'REFUND',
            'CHARGEBACK',
            'DISPUTE',
        ];

        $identifier = $eventId;
        if ($eventType === 'solo.transaction.updated' && is_array($payload['payload'] ?? null)) {
            $identifier = (string) ($payload['payload']['client_transaction_id'] ?? $eventId);
        } elseif (isset($payload['transaction_id'])) {
            $identifier = (string) $payload['transaction_id'];
        } elseif (isset($payload['payload']['transaction_id'])) {
            $identifier = (string) $payload['payload']['transaction_id'];
        }

        if (
            !is_array($payload)
            || !CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($eventId)
            || !CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($identifier)
            || !in_array($eventType, $acceptedEvents, true)
        ) {
            if (!is_array($payload) || !in_array($eventType, $acceptedEvents, true)) {
                CRM_Utils_System::civiExit();
            }
            http_response_code(400);
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
            } elseif (in_array($eventType, ['TRANSACTION_REFUNDED', 'REFUND_SUCCESSFUL', 'REFUND'], true)) {
                $txnRef = (string) (
                    $payload['transaction_id']
                    ?? $payload['id']
                    ?? $payload['payload']['transaction_id']
                    ?? ''
                );
                $result = $this->applyExternalRefund($txnRef, $payload);
            } elseif (in_array($eventType, ['CHARGEBACK', 'DISPUTE'], true)) {
                $txnRef = (string) (
                    $payload['transaction_id']
                    ?? $payload['id']
                    ?? $payload['payload']['transaction_id']
                    ?? ''
                );
                $result = $this->applyChargeback($txnRef, $payload);
            } else {
                throw new PaymentProcessorException(E::ts('Unsupported SumUp webhook event: %1.', [1 => $eventType]));
            }
            $this->finishWebhook($webhookId, 'success', E::ts(
                'SumUp event %1 processed: %2.',
                [1 => $eventType, 2 => $result['status']]
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
        } elseif ($status === 'CANCELLED') {
            $this->cancelPendingContribution($contributionId, (string) $contribution['contribution_status_id:name']);
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
            if (!CRM_SumupPaymentProcessor_TokenCustomerStore::isValidCustomerId($customerId)) {
                throw new PaymentProcessorException(E::ts('The SumUp customer is invalid.'));
            }

            $token = trim((string) ($setupCheckout->paymentInstrument->token ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{8,255}$/', $token)) {
                throw new PaymentProcessorException(E::ts('SumUp did not return a reusable payment token.'));
            }
            $instrument = $this->service()->getPaymentInstrument($customerId, $token);
            $paymentTokenId = $this->persistPaymentToken($token, $instrument, $contactId, $customerId);
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
        int $contactId,
        string $customerId
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
            $paymentTokenId = (int) $existing['id'];
            if ($maskedAccountNumber !== null) {
                PaymentToken::update(false)
                    ->addWhere('id', '=', $paymentTokenId)
                    ->setValues([
                        'masked_account_number' => $maskedAccountNumber,
                    ])
                    ->execute();
            }
            CRM_SumupPaymentProcessor_TokenCustomerStore::remember($paymentTokenId, $customerId);
            return $paymentTokenId;
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
        $paymentTokenId = (int) $result['id'];
        CRM_SumupPaymentProcessor_TokenCustomerStore::remember($paymentTokenId, $customerId);
        return $paymentTokenId;
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

        $tokensByCustomer = [];
        foreach ($localTokens as $localToken) {
            $paymentTokenId = (int) $localToken['id'];
            $customerId = CRM_SumupPaymentProcessor_TokenCustomerStore::get($paymentTokenId);
            if ($customerId === null) {
                Civi::log()->warning(sprintf(
                    'SumUp saved card %d has no durable remote customer mapping.',
                    $paymentTokenId
                ));
                continue;
            }
            $tokensByCustomer[$customerId][] = $localToken;
        }

        $remoteByPaymentTokenId = [];
        foreach ($tokensByCustomer as $customerId => $customerTokens) {
            try {
                $instruments = $this->service()->listPaymentInstruments($customerId);
            } catch (Throwable $exception) {
                Civi::log()->warning(sprintf(
                    'Unable to list SumUp saved cards for contact %d, customer %s and processor %d: %s',
                    $contactId,
                    $customerId,
                    $this->getProcessorId(),
                    $exception->getMessage()
                ));
                continue;
            }
            $remoteByToken = [];
            foreach ($instruments as $instrument) {
                if ($instrument->active === false) {
                    continue;
                }
                $token = trim((string) $instrument->token);
                if ($token !== '') {
                    $remoteByToken[$token] = $instrument;
                }
            }
            foreach ($customerTokens as $customerToken) {
                $providerToken = trim((string) ($customerToken['token'] ?? ''));
                if ($providerToken !== '' && isset($remoteByToken[$providerToken])) {
                    $remoteByPaymentTokenId[(int) $customerToken['id']] = $remoteByToken[$providerToken];
                }
            }
        }
        $cards = [];
        foreach ($localTokens as $localToken) {
            $paymentTokenId = (int) $localToken['id'];
            $token = trim((string) ($localToken['token'] ?? ''));
            if ($token === '' || !isset($remoteByPaymentTokenId[$paymentTokenId])) {
                continue;
            }
            $maskedAccountNumber = $this->maskedAccountNumber($remoteByPaymentTokenId[$paymentTokenId]);
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
                'payment_token_id' => $paymentTokenId,
                'masked_account_number' => $maskedAccountNumber,
            ];
        }
        return $cards;
    }

    /**
     * Deactivate a reusable SumUp card which is not used by an active schedule.
     *
     * @return array{payment_token_id: int, masked_account_number: string, deactivated: bool}
     */
    public function deactivateSavedCard(int $paymentTokenId, int $contactId): array
    {
        if ($paymentTokenId <= 0 || $contactId <= 0) {
            throw new PaymentProcessorException(E::ts('Invalid saved card identifier.'));
        }
        $paymentToken = PaymentToken::get(false)
            ->addSelect('id', 'token', 'masked_account_number')
            ->addWhere('id', '=', $paymentTokenId)
            ->addWhere('contact_id', '=', $contactId)
            ->addWhere('payment_processor_id', '=', $this->getProcessorId())
            ->execute()
            ->first();
        if (!$paymentToken) {
            throw new PaymentProcessorException(E::ts('This saved SumUp card does not exist.'));
        }

        $activeSchedule = ContributionRecur::get(false)
            ->addSelect('id')
            ->addWhere('payment_token_id', '=', $paymentTokenId)
            ->addWhere('contribution_status_id:name', '=', 'In Progress')
            ->setLimit(1)
            ->execute()
            ->first();
        if ($activeSchedule) {
            throw new PaymentProcessorException(E::ts(
                'This card is used by an active recurring payment. '
                . 'Replace the card or stop the recurring payment first.'
            ));
        }

        $providerToken = trim((string) $paymentToken['token']);
        if ($providerToken === '') {
            throw new PaymentProcessorException(E::ts('This saved SumUp card has no provider token.'));
        }
        $customerId = $this->customerIdForPaymentToken($paymentTokenId);
        $remoteInstrument = null;
        foreach ($this->service()->listPaymentInstruments($customerId) as $instrument) {
            if (hash_equals($providerToken, trim((string) $instrument->token))) {
                $remoteInstrument = $instrument;
                break;
            }
        }
        if ($remoteInstrument !== null && $remoteInstrument->active !== false) {
            $this->service()->deactivatePaymentInstrument($customerId, $providerToken);
        }

        PaymentToken::delete(false)
            ->addWhere('id', '=', $paymentTokenId)
            ->execute();

        return [
            'payment_token_id' => $paymentTokenId,
            'masked_account_number' => (string) ($paymentToken['masked_account_number'] ?? ''),
            'deactivated' => true,
        ];
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

    private function customerIdForPaymentToken(int $paymentTokenId): string
    {
        $customerId = CRM_SumupPaymentProcessor_TokenCustomerStore::get($paymentTokenId);
        if ($customerId === null) {
            throw new PaymentProcessorException(E::ts(
                'The saved SumUp card is missing its remote customer association.'
            ));
        }
        return $customerId;
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
            ->addWhere('is_test', 'IN', [true, false])
            ->execute()
            ->single();
        $processorId = $this->getProcessorId();
        $relatedProcessorIds = $this->getRelatedProcessorIds();
        $contributionProcessorId = (int) ($contribution['payment_processor_id'] ?? 0);
        if ($contributionProcessorId <= 0 || in_array($contributionProcessorId, $relatedProcessorIds, true)) {
            if ($contributionProcessorId !== $processorId) {
                Contribution::update(false)
                    ->addWhere('id', '=', $contributionId)
                    ->addValue('payment_processor_id', $processorId)
                    ->execute();
            }
        } else {
            $isSumup = PaymentProcessor::get(false)
                ->addWhere('id', '=', $contributionProcessorId)
                ->addWhere('class_name', '=', 'Payment_Sumup')
                ->addWhere('is_test', 'IN', [true, false])
                ->execute()
                ->first();
            if ($isSumup) {
                Contribution::update(false)
                    ->addWhere('id', '=', $contributionId)
                    ->addValue('payment_processor_id', $processorId)
                    ->execute();
            } else {
                throw new PaymentProcessorException(
                    E::ts('The recurring contribution uses another payment processor.')
                );
            }
        }

        $paymentToken = PaymentToken::get(false)
            ->addSelect('id', 'contact_id', 'payment_processor_id', 'token')
            ->addWhere('id', '=', $paymentTokenId)
            ->execute()
            ->single();
        $contactId = (int) $contribution['contact_id'];
        $token = trim((string) $paymentToken['token']);
        $paymentTokenProcessorId = (int) ($paymentToken['payment_processor_id'] ?? 0);
        $tokenProcessor = PaymentProcessor::get(false)
            ->addSelect('class_name')
            ->addWhere('id', '=', $paymentTokenProcessorId)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute()
            ->first();
        if (
            (int) $paymentToken['contact_id'] !== $contactId
            || ($tokenProcessor['class_name'] ?? '') !== 'Payment_Sumup'
            || !preg_match('/^[A-Za-z0-9_-]{8,255}$/', $token)
        ) {
            throw new PaymentProcessorException(E::ts('The recurring contribution has no valid SumUp card token.'));
        }

        $customerId = $this->customerIdForPaymentToken($paymentTokenId);
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
     * @param list<int> $additionalRecurIds
     * @return array<string, mixed>
     */
    public function startPaymentMethodReplacement(
        int $contributionRecurId,
        int $contactId,
        array $additionalRecurIds = []
    ): array {
        $recurIds = array_values(array_unique(array_map(
            'intval',
            array_merge([$contributionRecurId], $additionalRecurIds)
        )));
        sort($recurIds, SORT_NUMERIC);
        $schedules = [];
        foreach ($recurIds as $recurId) {
            $schedules[$recurId] = $this->getOwnedRecurringSchedule($recurId, $contactId);
        }
        $primarySchedule = $schedules[$contributionRecurId];
        $lock = CRM_Core_Lock::createScopedLock(
            'data.sumup.remediation.start.' . hash('sha256', implode(',', $recurIds))
        );
        if (!$lock->acquire()) {
            throw new PaymentProcessorException(E::ts('This SumUp card replacement is already being prepared.'));
        }
        try {
            $contribution = $this->latestRecurringContribution($contributionRecurId);
            $remediations = [];
            foreach ($recurIds as $recurId) {
                $remediation = CRM_SumupPaymentProcessor_RemediationStore::getOpen($recurId);
                if ($remediation === null) {
                    $scheduleContribution = $this->latestRecurringContribution($recurId);
                    $remediation = CRM_SumupPaymentProcessor_RemediationStore::open(
                        $recurId,
                        (int) $scheduleContribution['id'],
                        $this->getProcessorId(),
                        null,
                        (int) $schedules[$recurId]['payment_token_id'],
                        'customer_requested'
                    );
                }
                $remediations[$recurId] = $remediation;
            }

            $replacementCheckoutId = trim((string) (
                $remediations[$contributionRecurId]['replacement_checkout_id'] ?? ''
            ));
            foreach ($remediations as $remediation) {
                $otherCheckoutId = trim((string) ($remediation['replacement_checkout_id'] ?? ''));
                if (
                    $otherCheckoutId !== ''
                    && ($replacementCheckoutId === '' || !hash_equals($replacementCheckoutId, $otherCheckoutId))
                ) {
                    throw new PaymentProcessorException(E::ts(
                        'Another SumUp card replacement is already in progress for a selected recurring contribution.'
                    ));
                }
            }
            if ($replacementCheckoutId !== '') {
                $storedRemediations = CRM_SumupPaymentProcessor_RemediationStore::getByReplacementCheckoutId(
                    $replacementCheckoutId
                );
                $storedIds = array_map(
                    static fn(array $record): int => (int) $record['contribution_recur_id'],
                    $storedRemediations
                );
                sort($storedIds, SORT_NUMERIC);
                if ($storedIds !== $recurIds) {
                    if ($recurIds !== [$contributionRecurId]) {
                        throw new PaymentProcessorException(E::ts(
                            'Another SumUp card replacement is already in progress for this recurring contribution.'
                        ));
                    }
                    $recurIds = $storedIds;
                    $remediations = [];
                    foreach ($storedRemediations as $storedRemediation) {
                        $storedRecurId = (int) $storedRemediation['contribution_recur_id'];
                        $this->getOwnedRecurringSchedule($storedRecurId, $contactId);
                        $remediations[$storedRecurId] = $storedRemediation;
                    }
                }
                $existing = $this->service()->get($replacementCheckoutId);
                $status = strtoupper((string) $existing->status);
                if ($status === 'PENDING') {
                    return $this->replacementCheckoutConfig($existing, $primarySchedule, $recurIds);
                }
                if ($status === 'PAID') {
                    return $this->completePaymentMethodReplacement(
                        $contributionRecurId,
                        $contactId,
                        $replacementCheckoutId
                    );
                }
                foreach ($remediations as $remediation) {
                    CRM_SumupPaymentProcessor_RemediationStore::resetReplacement((int) $remediation['id']);
                }
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
                amount: (float) $primarySchedule['amount'],
                currency: (string) $primarySchedule['currency'],
                description: E::ts('Replace the card for %1 recurring contribution(s)', [1 => count($recurIds)]),
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
                (float) $primarySchedule['amount'],
                (string) $primarySchedule['currency'],
                CRM_SumupPaymentProcessor_CheckoutMode::WIDGET,
                null,
                'CARD_REPLACEMENT',
                $customerId
            );
            foreach ($remediations as $remediation) {
                CRM_SumupPaymentProcessor_RemediationStore::attachReplacementCheckout(
                    (int) $remediation['id'],
                    (string) $checkout->id
                );
            }

            return $this->replacementCheckoutConfig($checkout, $primarySchedule, $recurIds);
        } finally {
            $lock->release();
        }
    }

    /**
     * Verify a replacement setup and atomically switch the recurring schedule.
     *
     * @return array<string, mixed>
     */
    public function completePaymentMethodReplacement(
        int $contributionRecurId,
        int $contactId,
        string $checkoutId
    ): array {
        $remediations = CRM_SumupPaymentProcessor_RemediationStore::getByReplacementCheckoutId($checkoutId);
        if ($remediations === []) {
            throw new PaymentProcessorException(E::ts('The SumUp replacement session is invalid.'));
        }
        $schedules = [];
        foreach ($remediations as $remediation) {
            $recurId = (int) $remediation['contribution_recur_id'];
            $schedules[$recurId] = $this->getOwnedRecurringSchedule($recurId, $contactId);
        }
        if (!isset($schedules[$contributionRecurId])) {
            throw new PaymentProcessorException(E::ts('The SumUp replacement session is invalid.'));
        }
        $schedule = $schedules[$contributionRecurId];
        $registry = CRM_SumupPaymentProcessor_CheckoutStore::getByCheckoutId($checkoutId);
        $customerId = trim((string) ($registry['customer_id'] ?? ''));
        if (
            $registry['purpose'] !== 'CARD_REPLACEMENT'
            || $registry['payment_processor_id'] !== $this->getProcessorId()
            || !CRM_SumupPaymentProcessor_TokenCustomerStore::isValidCustomerId($customerId)
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
            foreach ($remediations as $remediation) {
                CRM_SumupPaymentProcessor_RemediationStore::resetReplacement((int) $remediation['id']);
            }
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
        $newPaymentTokenId = $this->persistPaymentToken($token, $instrument, $contactId, $customerId);
        $recurIds = array_keys($schedules);
        sort($recurIds, SORT_NUMERIC);
        $switchLock = CRM_Core_Lock::createScopedLock(
            'data.sumup.remediation.switch.' . hash('sha256', implode(',', $recurIds))
        );
        if (!$switchLock->acquire()) {
            throw new PaymentProcessorException(E::ts('This SumUp card replacement is already being completed.'));
        }
        try {
            $transaction = new CRM_Core_Transaction();
            $oldPaymentTokenIds = [];
            try {
                foreach ($remediations as $remediation) {
                    $recurId = (int) $remediation['contribution_recur_id'];
                    $oldPaymentTokenId = (int) $schedules[$recurId]['payment_token_id'];
                    $oldPaymentTokenIds[] = $oldPaymentTokenId;
                    $updated = ContributionRecur::update(false)
                        ->addWhere('id', '=', $recurId)
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
                            'A recurring contribution payment method changed during replacement.'
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
                }
                $transaction->commit();
            } catch (Throwable $exception) {
                $transaction->rollback();
                throw $exception;
            }
        } finally {
            $switchLock->release();
        }

        foreach (array_unique($oldPaymentTokenIds) as $oldPaymentTokenId) {
            if ($oldPaymentTokenId !== $newPaymentTokenId) {
                $this->deactivateUnusedPaymentToken($oldPaymentTokenId);
            }
        }
        $last4 = trim((string) ($instrument->card->last4Digits ?? ''));
        return [
            'status' => 'RESOLVED',
            'checkout_id' => $checkoutId,
            'payment_token_id' => $newPaymentTokenId,
            'masked_account_number' => $last4 !== '' ? '**** ' . $last4 : null,
            'recur_ids' => $recurIds,
        ];
    }

    /** @return array<string, mixed> */
    private function getOwnedRecurringSchedule(int $contributionRecurId, int $contactId): array
    {
        $schedule = $this->getOwnedActiveRecurringSchedule($contributionRecurId);
        if (
            (int) $schedule['contact_id'] !== $contactId
            || (int) $schedule['payment_token_id'] <= 0
        ) {
            throw new PaymentProcessorException(E::ts('This SumUp recurring contribution cannot be managed.'));
        }
        return $schedule;
    }

    /** @return array<string, mixed> */
    private function getOwnedActiveRecurringSchedule(int $contributionRecurId): array
    {
        if ($contributionRecurId <= 0) {
            throw new PaymentProcessorException(E::ts('The SumUp recurring contribution ID is invalid.'));
        }

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
            (int) $schedule['payment_processor_id'] !== $this->getProcessorId()
            || (string) $schedule['contribution_status_id:name'] !== 'In Progress'
        ) {
            throw new PaymentProcessorException(E::ts('This SumUp recurring contribution cannot be managed.'));
        }
        return $schedule;
    }

    /** @return array<string, mixed> */
    private function latestRecurringContribution(int $contributionRecurId): array
    {
        return Contribution::get(false)
            ->addSelect('id', 'contribution_status_id:name')
            ->addWhere('contribution_recur_id', '=', $contributionRecurId)
            ->addWhere('is_test', 'IN', [true, false])
            ->addOrderBy('id', 'DESC')
            ->setLimit(1)
            ->execute()
            ->single();
    }

    /**
     * @param array<string, mixed> $schedule
     * @param list<int> $recurIds
     * @return array<string, mixed>
     */
    private function replacementCheckoutConfig(
        \SumUp\Types\Checkout|CheckoutSuccess $checkout,
        array $schedule,
        array $recurIds = []
    ): array {
        $merchantProfile = $this->getVerifiedMerchantProfile();
        return [
            'status' => $this->enumValue($checkout->status ?? 'PENDING'),
            'checkout_id' => (string) $checkout->id,
            'amount' => number_format((float) $schedule['amount'], 2, '.', ''),
            'currency' => strtoupper((string) $schedule['currency']),
            'locale' => CRM_SumupPaymentProcessor_CheckoutMode::getLocale(),
            'mode' => CRM_SumupPaymentProcessor_CheckoutMode::WIDGET,
            'public_key' => '',
            'merchant_code' => $this->getMerchantCode(),
            'business_name' => $merchantProfile['business_name'],
            'country_code' => CRM_SumupPaymentProcessor_CheckoutMode::getMerchantCountryCode(
                $merchantProfile['country']
            ),
            'wallets_allowed' => false,
            'browser_return_url' => '',
            'cancel_url' => '',
            'recur_ids' => $recurIds,
        ];
    }

    private function deactivateUnusedPaymentToken(int $paymentTokenId): void
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
            $customerId = $this->customerIdForPaymentToken($paymentTokenId);
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
            'CANCELLED' => 'CANCELLED',
            'FAILED' => 'FAILED',
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
        } elseif ($status === 'CANCELLED') {
            $this->cancelPendingContribution(
                (int) $contribution['id'],
                (string) $contribution['contribution_status_id:name']
            );
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
            || !in_array($registry['purpose'], ['PAYMENT', 'SETUP_RECURRING_PAYMENT'], true)
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
            || !in_array($registry['purpose'], ['PAYMENT', 'SETUP_RECURRING_PAYMENT'], true)
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
                'contribution_recur_id',
                'contribution_status_id:name'
            )
            ->addWhere('id', '=', $contributionId)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute()
            ->single();
        $configuredProcessorId = (int) ($contribution['payment_processor_id'] ?? 0);
        if ($configuredProcessorId > 0 && $configuredProcessorId !== $this->getProcessorId()) {
            throw new PaymentProcessorException(E::ts('The contribution uses another payment processor.'));
        }
        $paymentToken = PaymentToken::get(false)
            ->addSelect('id', 'contact_id', 'payment_processor_id', 'token')
            ->addWhere('id', '=', $paymentTokenId)
            ->execute()
            ->single();
        $contactId = (int) $contribution['contact_id'];
        $providerToken = trim((string) $paymentToken['token']);
        $customerId = $this->customerIdForPaymentToken($paymentTokenId);
        $registryCustomerId = trim((string) ($registry['customer_id'] ?? ''));
        if (
            (int) $paymentToken['contact_id'] !== $contactId
            || (int) $paymentToken['payment_processor_id'] !== $this->getProcessorId()
            || (
                $registry['purpose'] === 'PAYMENT'
                && $registryCustomerId !== ''
                && !hash_equals($registryCustomerId, $customerId)
            )
            || !preg_match('/^[A-Za-z0-9_-]{8,255}$/', $providerToken)
        ) {
            throw new PaymentProcessorException(E::ts('The selected SumUp card does not belong to this contribution.'));
        }
        $this->service()->getPaymentInstrument($customerId, $providerToken);
        CRM_SumupPaymentProcessor_CheckoutStore::attachPaymentToken($checkoutId, $paymentTokenId);

        if ($registry['purpose'] === 'SETUP_RECURRING_PAYMENT') {
            return $this->completeRecurringSetupWithSavedCard(
                $checkoutId,
                $paymentTokenId,
                $customerId,
                $providerToken,
                $contribution
            );
        }

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

    /**
     * Complete a recurring contribution using an already-authorised SumUp card.
     *
     * @param array<string, mixed> $contribution
     * @return array<string, mixed>
     */
    private function completeRecurringSetupWithSavedCard(
        string $setupCheckoutId,
        int $paymentTokenId,
        string $customerId,
        string $providerToken,
        array $contribution
    ): array {
        $lock = CRM_Core_Lock::createScopedLock('data.sumup.recurring.setup.' . $setupCheckoutId);
        if (!$lock->acquire()) {
            throw new PaymentProcessorException(E::ts('This SumUp recurring setup is already being processed.'));
        }
        try {
            $contributionRecurId = (int) ($contribution['contribution_recur_id'] ?? 0);
            if ($contributionRecurId <= 0) {
                throw new PaymentProcessorException(E::ts('The SumUp recurring setup is not linked to CiviCRM.'));
            }
            ContributionRecur::update(false)
                ->addWhere('id', '=', $contributionRecurId)
                ->setValues([
                    'payment_token_id' => $paymentTokenId,
                    'payment_processor_id' => $this->getProcessorId(),
                ])
                ->execute();

            $charge = CRM_SumupPaymentProcessor_CheckoutStore::getBySetupCheckoutId($setupCheckoutId);
            if ($charge === null) {
                $charge = $this->createInitialRecurringCharge($setupCheckoutId, $customerId, $contribution);
            }
            $chargeCheckoutId = (string) $charge['checkout_id'];
            CRM_SumupPaymentProcessor_CheckoutStore::attachPaymentToken($chargeCheckoutId, $paymentTokenId);
            $verified = $this->verifyAndApplyCheckout($chargeCheckoutId, (int) $contribution['id']);
            if ($verified['status'] !== 'PENDING') {
                return $verified;
            }

            $processed = $this->service()->processWithToken($chargeCheckoutId, $customerId, $providerToken);
            if ($processed instanceof CheckoutAccepted) {
                return [
                    'status' => 'CUSTOMER_ACTION_REQUIRED',
                    'contribution_id' => (int) $contribution['id'],
                    'transaction_id' => null,
                    'next_step' => $this->normaliseNextStep($processed),
                ];
            }
            return $this->verifyAndApplyCheckout($chargeCheckoutId, (int) $contribution['id']);
        } finally {
            $lock->release();
        }
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

            $financialDetails = $this->getFinancialDetails($transactionId);
            $values = [
                'contribution_id' => $contributionId,
                'total_amount' => (float) $contribution['total_amount'],
                'payment_processor_id' => $processorId,
                'payment_instrument_id' => $this->getPaymentInstrumentID(),
                'trxn_id' => $transactionId,
                'trxn_date' => date('Y-m-d H:i:s'),
            ];
            if (isset($financialDetails['fee_amount'])) {
                $values['fee_amount'] = $financialDetails['fee_amount'];
            }
            if (isset($financialDetails['pan_truncation'])) {
                $values['pan_truncation'] = $financialDetails['pan_truncation'];
            }
            if (isset($financialDetails['card_type_id'])) {
                $values['card_type_id'] = $financialDetails['card_type_id'];
            }

            $contribUpdate = [
                'receive_date' => date('Y-m-d H:i:s'),
                'trxn_id' => $transactionId,
            ];
            if (isset($financialDetails['fee_amount']) && $financialDetails['fee_amount'] > 0) {
                $contribUpdate['fee_amount'] = $financialDetails['fee_amount'];
                $contribUpdate['net_amount'] = max(
                    0.0,
                    (float) $contribution['total_amount'] - $financialDetails['fee_amount']
                );
            }
            if (isset($financialDetails['revenue_recognition_date'])) {
                $contribUpdate['revenue_recognition_date'] = $financialDetails['revenue_recognition_date'];
            }
            Contribution::update(false)
                ->addWhere('id', '=', $contributionId)
                ->setValues($contribUpdate)
                ->execute();

            Payment::create(false)
                ->setValues($values)
                ->setNotificationForCompleteOrder(true)
                ->execute();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, contribution_id: int, transaction_id: string}
     */
    private function applyExternalRefund(string $transactionReference, array $payload): array
    {
        if ($transactionReference === '') {
            throw new PaymentProcessorException(E::ts('Missing transaction reference in SumUp refund webhook.'));
        }

        $lock = CRM_Core_Lock::createScopedLock('data.sumup.external-refund.' . $transactionReference);
        if (!$lock->acquire()) {
            throw new PaymentProcessorException(E::ts(
                'This SumUp refund notification is already being processed.'
            ));
        }

        try {
            $transaction = $this->service()->getTransaction($transactionReference);
            $transactionId = trim((string) $transaction->id);
            if (!preg_match('/^[A-Za-z0-9_-]{4,100}$/', $transactionId)) {
                throw new PaymentProcessorException(E::ts('SumUp did not return a valid transaction identifier.'));
            }

            $exactPayments = Payment::get(false)
                ->addSelect('id', 'contribution_id', 'total_amount', 'trxn_id')
                ->addWhere('trxn_id', '=', $transactionId)
                ->addWhere('payment_processor_id', '=', $this->getProcessorId())
                ->addWhere('total_amount', '>', 0)
                ->execute();
            $paymentMatches = [];
            foreach ($exactPayments as $exactPayment) {
                $paymentMatches[] = $exactPayment;
            }
            if ($paymentMatches === []) {
                $legacyCandidates = Payment::get(false)
                    ->addSelect('id', 'contribution_id', 'total_amount', 'trxn_id')
                    ->addWhere('trxn_id', 'LIKE', '%' . $transactionId . '%')
                    ->addWhere('payment_processor_id', '=', $this->getProcessorId())
                    ->addWhere('total_amount', '>', 0)
                    ->execute();
                foreach ($legacyCandidates as $candidate) {
                    $references = preg_split('/\s*,\s*/', (string) ($candidate['trxn_id'] ?? '')) ?: [];
                    if (in_array($transactionId, $references, true)) {
                        $paymentMatches[] = $candidate;
                    }
                }
            }
            if (count($paymentMatches) !== 1) {
                throw new PaymentProcessorException(E::ts(
                    'Expected one CiviCRM payment for SumUp transaction %1; found %2.',
                    [1 => $transactionId, 2 => count($paymentMatches)]
                ));
            }

            $payment = $paymentMatches[0];
            $contributionId = (int) $payment['contribution_id'];
            $providerRefundedMinor = $this->getRefundedMinorUnits($transaction);
            if ($providerRefundedMinor <= 0) {
                throw new PaymentProcessorException(E::ts(
                    'SumUp has not exposed a refundable event for transaction %1 yet.',
                    [1 => $transactionId]
                ));
            }
            $transactionMinor = (int) round((float) $transaction->amount * 100);
            if ($providerRefundedMinor > $transactionMinor) {
                throw new PaymentProcessorException(E::ts(
                    'The SumUp refunded amount exceeds the original transaction amount.'
                ));
            }

            $existingRefunds = Payment::get(false)
                ->addSelect('id', 'total_amount', 'trxn_id')
                ->addWhere('contribution_id', '=', $contributionId)
                ->addWhere('payment_processor_id', '=', $this->getProcessorId())
                ->addWhere('total_amount', '<', 0)
                ->execute();
            $recordedRefundedMinor = 0;
            foreach ($existingRefunds as $existingRefund) {
                $recordedRefundedMinor += abs((int) round((float) $existingRefund['total_amount'] * 100));
            }

            $unrecordedMinor = $providerRefundedMinor - $recordedRefundedMinor;
            if ($unrecordedMinor > 0) {
                $unrecordedId = $this->findUnrecordedRefundEventId($transaction, $unrecordedMinor);
                $refundEventId = $unrecordedId ?? 'external-' . substr(
                    hash('sha256', $transactionId . ':' . $providerRefundedMinor),
                    0,
                    16
                );
                $refundTrxnId = $this->refundTransactionId($transactionId, $refundEventId);
                Payment::create(false)
                    ->setValues([
                        'contribution_id' => $contributionId,
                        'total_amount' => -($unrecordedMinor / 100),
                        'payment_processor_id' => $this->getProcessorId(),
                        'payment_instrument_id' => $this->getPaymentInstrumentID(),
                        'trxn_id' => $refundTrxnId,
                        'trxn_date' => date('Y-m-d H:i:s'),
                    ])
                    ->execute();

                Civi::log()->info(sprintf(
                    'Recorded external SumUp refund delta for contribution %d: amount=%.2f transaction_id=%s',
                    $contributionId,
                    $unrecordedMinor / 100,
                    $transactionId
                ));
            }

            return [
                'status' => 'REFUNDED',
                'contribution_id' => $contributionId,
                'transaction_id' => $transactionId,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, contribution_id: int, transaction_id: string}
     */
    private function applyChargeback(string $transactionReference, array $payload): array
    {
        if ($transactionReference === '') {
            throw new PaymentProcessorException(E::ts('Missing transaction reference in SumUp chargeback webhook.'));
        }

        $service = $this->service();
        $transaction = $service->getTransaction($transactionReference);
        $transactionId = trim((string) $transaction->id);

        $payments = Payment::get(false)
            ->addSelect('id', 'contribution_id')
            ->addWhere('trxn_id', 'LIKE', '%' . $transactionId . '%')
            ->addWhere('payment_processor_id', '=', $this->getProcessorId())
            ->execute();

        $payment = $payments->first();
        if (!$payment) {
            throw new PaymentProcessorException(E::ts(
                'No matching CiviCRM payment found for SumUp chargeback transaction %1.',
                [1 => $transactionId]
            ));
        }

        $contributionId = (int) $payment['contribution_id'];
        $chargebackStatusId = CRM_Core_PseudoConstant::getKey(
            'CRM_Contribute_BAO_Contribution',
            'contribution_status_id',
            'Chargeback'
        );
        if ($chargebackStatusId === null || $chargebackStatusId === false) {
            $chargebackStatusId = CRM_Core_PseudoConstant::getKey(
                'CRM_Contribute_BAO_Contribution',
                'contribution_status_id',
                'Cancelled'
            );
        }

        if ($chargebackStatusId !== null && $chargebackStatusId !== false) {
            Contribution::update(false)
                ->addWhere('id', '=', $contributionId)
                ->setValues([
                    'contribution_status_id' => (int) $chargebackStatusId,
                    'cancel_date' => date('Y-m-d H:i:s'),
                    'cancel_reason' => E::ts('SumUp chargeback / cardholder dispute'),
                ])
                ->execute();
        }

        Civi::log()->warning(sprintf(
            'SumUp chargeback applied to contribution %d: transaction_id=%s',
            $contributionId,
            $transactionId
        ));

        return [
            'status' => 'CHARGEBACK',
            'contribution_id' => $contributionId,
            'transaction_id' => $transactionId,
        ];
    }

    /**
     * Retrieve financial details (fees, payout date, card details) without weakening payment completion.
     *
     * @return array{card_type_id?: int, pan_truncation?: string, fee_amount?: float, revenue_recognition_date?: string}
     */
    private function getFinancialDetails(string $transactionId): array
    {
        try {
            $transaction = $this->service()->getTransaction($transactionId);
        } catch (Throwable $exception) {
            Civi::log()->warning(sprintf(
                'Unable to retrieve SumUp financial details for transaction %s: %s',
                $transactionId,
                $exception->getMessage()
            ));
            return [];
        }

        $details = [];
        $last4 = $this->cardLast4($transaction->card->last4Digits ?? null);
        if ($last4 !== null) {
            $details['pan_truncation'] = $last4;
        }
        $cardName = $this->civiCardName($this->enumValue($transaction->card->type ?? ''));
        if ($cardName !== null) {
            $cardTypeId = CRM_Core_PseudoConstant::getKey(
                'CRM_Financial_DAO_FinancialTrxn',
                'card_type_id',
                $cardName
            );
            if ($cardTypeId !== null && $cardTypeId !== false) {
                $details['card_type_id'] = (int) $cardTypeId;
            }
        }

        // Fee extraction
        $feeAmount = null;
        if ($transaction->feeAmount !== null && (float) $transaction->feeAmount > 0) {
            $feeAmount = (float) $transaction->feeAmount;
        } else {
            foreach ($transaction->events ?? [] as $event) {
                if (isset($event->feeAmount) && (float) $event->feeAmount > 0) {
                    $feeAmount = (float) $event->feeAmount;
                    break;
                }
            }
        }
        if ($feeAmount !== null) {
            $details['fee_amount'] = $feeAmount;
        }

        // Payout date / Revenue recognition date
        $payoutDate = null;
        if (!empty($transaction->payoutDate)) {
            $payoutDate = trim((string) $transaction->payoutDate);
        } else {
            foreach ($transaction->transactionEvents ?? [] as $te) {
                if (!empty($te->dueDate)) {
                    $payoutDate = trim((string) $te->dueDate);
                    break;
                }
                if (!empty($te->timestamp)) {
                    $payoutDate = substr(trim((string) $te->timestamp), 0, 10);
                    break;
                }
            }
        }
        if ($payoutDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $payoutDate)) {
            $details['revenue_recognition_date'] = substr($payoutDate, 0, 10) . ' 00:00:00';
        }

        return $details;
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

    private function cancelPendingContribution(int $contributionId, string $currentStatus): void
    {
        if ($currentStatus !== 'Pending') {
            return;
        }
        Contribution::update(false)
            ->addWhere('id', '=', $contributionId)
            ->addWhere('contribution_status_id:name', '=', 'Pending')
            ->addValue('contribution_status_id:name', 'Cancelled')
            ->execute();
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
