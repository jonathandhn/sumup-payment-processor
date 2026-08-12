<?php

declare(strict_types=1);

use Civi\Api4\Contribution;
use Civi\Api4\Payment;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentprocessorWebhook;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;
use SumUp\Types\CheckoutSuccess;
use SumUp\Types\TransactionFull;

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
        return $this->getOperationalConfigurationError() === null;
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
            $refundTransactionId = 'sumup-refund-' . $transactionId . '-' . $refundEventId;
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
     *   hosted_checkout_url: string|null
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
            ->addSelect('id', 'total_amount', 'currency', 'source')
            ->addWhere('id', '=', $contributionId)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute()
            ->single();
        $amount = (float) $contribution['total_amount'];
        $currency = strtoupper((string) $contribution['currency']);
        $browserReturnUrl = $this->buildSignedWidgetUrl($contributionId, $returnUrl, $cancelUrl);
        $checkoutMode = CRM_SumupPaymentProcessor_CheckoutMode::getConfiguredMode();
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
            hosted: $hosted
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
            $checkoutMode
        );

        Contribution::update(false)
            ->addWhere('id', '=', $contributionId)
            ->setValues([
                'payment_processor_id' => (int) $this->_paymentProcessor['id'],
                'trxn_id' => $checkoutId,
            ])
            ->execute();

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
        $checkoutId = is_array($payload) ? (string) ($payload['id'] ?? '') : '';
        if (!is_array($payload) || !CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($checkoutId)) {
            http_response_code(400);
            CRM_Utils_System::civiExit();
        }
        if ($eventType !== 'CHECKOUT_STATUS_CHANGED') {
            CRM_Utils_System::civiExit();
        }

        try {
            PaymentprocessorWebhook::create(false)
                ->setValues([
                    'payment_processor_id' => (int) $this->_paymentProcessor['id'],
                    'event_id' => $checkoutId,
                    'trigger' => $eventType,
                    'identifier' => $checkoutId,
                    'created_date' => 'now',
                    'data' => $rawPayload,
                    'status' => 'new',
                ])
                ->execute();
        } catch (Throwable $exception) {
            Civi::log()->error(sprintf(
                'SumUp webhook %s could not be queued for processor %d: %s',
                $checkoutId,
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
            $checkoutId = is_array($payload) ? (string) ($payload['id'] ?? '') : '';
            if (
                !is_array($payload)
                || (string) ($payload['event_type'] ?? '') !== 'CHECKOUT_STATUS_CHANGED'
                || !CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($checkoutId)
            ) {
                throw new PaymentProcessorException(E::ts('Invalid SumUp webhook payload in the queue.'));
            }

            $result = $this->verifyAndApplyCheckout($checkoutId);
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
            if ($transactionId === null) {
                throw new PaymentProcessorException(E::ts('Paid SumUp checkout has no transaction identifier.'));
            }
            $this->completeContribution($contribution, $transactionId);
        } elseif (in_array($status, ['FAILED', 'EXPIRED'], true)) {
            $this->failPendingContribution($contributionId, (string) $contribution['contribution_status_id:name']);
        }

        return [
            'status' => $status,
            'contribution_id' => $contributionId,
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
                'trxn_id' => $transactionId,
                'trxn_date' => 'now',
            ];
            if (!empty($contribution['payment_instrument_id'])) {
                $values['payment_instrument_id'] = (int) $contribution['payment_instrument_id'];
            }
            Payment::create(false)
                ->setValues($values)
                ->setNotificationForCompleteOrder(true)
                ->execute();
        } finally {
            $lock->release();
        }
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
