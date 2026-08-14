<?php

declare(strict_types=1);

use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;
use SumUp\HttpClient\RequestOptions;
use SumUp\ResponseDecoder;
use SumUp\SumUp;
use SumUp\Types\Checkout;
use SumUp\Types\CheckoutAccepted;
use SumUp\Types\CheckoutCreateRequest;
use SumUp\Types\CheckoutSuccess;
use SumUp\Types\CheckoutCreateRequestPurpose;
use SumUp\Types\HostedCheckout;
use SumUp\Types\Customer;
use SumUp\Types\PaymentInstrumentResponse;
use SumUp\Types\ProcessCheckout;
use SumUp\Services\CheckoutsListParams;
use SumUp\Services\TransactionsGetParams;
use SumUp\Types\TransactionFull;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_CheckoutService
{
    private const REFERENCE_PATTERN = '/^CIVI-([1-9][0-9]*)-([a-f0-9]{16})$/';

    private SumUp $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $merchantCode
    ) {
        if ($apiKey === '' || $merchantCode === '') {
            throw new PaymentProcessorException(E::ts('The SumUp payment processor is not configured.'));
        }

        if (!class_exists(SumUp::class)) {
            throw new PaymentProcessorException(E::ts('The SumUp PHP SDK is not installed.'));
        }

        $this->client = new SumUp($apiKey);
    }

    public function create(
        int $contributionId,
        float $amount,
        string $currency,
        string $description,
        string $webhookUrl,
        ?string $browserReturnUrl,
        bool $hosted = false,
        ?string $customerId = null,
        ?string $purpose = null,
        ?string $checkoutReference = null
    ): Checkout {
        if ($contributionId <= 0 || $amount <= 0.0) {
            throw new PaymentProcessorException(E::ts('Unable to create a SumUp checkout for this contribution.'));
        }

        $reference = $checkoutReference ?? sprintf('CIVI-%d-%s', $contributionId, bin2hex(random_bytes(8)));
        if (self::getContributionIdFromReference($reference) !== $contributionId) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp checkout reference.'));
        }
        $hostedCheckout = null;
        if ($hosted) {
            $hostedCheckout = new HostedCheckout();
            $hostedCheckout->enabled = true;
        }
        $request = new CheckoutCreateRequest(
            checkoutReference: $reference,
            amount: round($amount, 2),
            currency: strtoupper($currency),
            merchantCode: $this->merchantCode,
            description: mb_substr($description, 0, 255),
            returnUrl: $webhookUrl,
            customerId: $customerId,
            purpose: $purpose,
            redirectUrl: $browserReturnUrl,
            hostedCheckout: $hostedCheckout
        );

        return $this->client->checkouts()->create($request, $this->requestOptions());
    }

    public function ensureCustomer(string $customerId): Customer
    {
        if (!preg_match('/^[A-Za-z0-9_-]{4,100}$/', $customerId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp customer identifier.'));
        }
        try {
            return $this->client->customers()->get($customerId, $this->requestOptions());
        } catch (\SumUp\Exception\SDKException $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw $exception;
            }
        }

        try {
            return $this->client->customers()->create(
                new Customer(customerId: $customerId),
                $this->requestOptions()
            );
        } catch (\SumUp\Exception\SDKException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            return $this->client->customers()->get($customerId, $this->requestOptions());
        }
    }

    public function getPaymentInstrument(string $customerId, string $token): PaymentInstrumentResponse
    {
        $instruments = $this->listPaymentInstruments($customerId);
        foreach ($instruments as $instrument) {
            if ($instrument->token === $token && $instrument->active === true) {
                return $instrument;
            }
        }
        throw new PaymentProcessorException(E::ts('SumUp did not return an active reusable card.'));
    }

    /** @return list<PaymentInstrumentResponse> */
    public function listPaymentInstruments(string $customerId): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{4,100}$/', $customerId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp customer identifier.'));
        }
        $instruments = $this->client->customers()->listPaymentInstruments(
            $customerId,
            $this->requestOptions()
        );
        return array_values(array_filter(
            $instruments,
            static fn(PaymentInstrumentResponse $instrument): bool => $instrument->active === true
        ));
    }

    public function processWithToken(
        string $checkoutId,
        string $customerId,
        string $token
    ): CheckoutSuccess|CheckoutAccepted {
        if (!self::isValidCheckoutId($checkoutId) || $customerId === '' || $token === '') {
            throw new PaymentProcessorException(E::ts('Invalid SumUp recurring payment identifiers.'));
        }
        try {
            return $this->client->checkouts()->process(
                $checkoutId,
                new ProcessCheckout(paymentType: 'card', token: $token, customerId: $customerId),
                $this->requestOptions()
            );
        } catch (\SumUp\Exception\SDKException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            // An interrupted local request may have reached SumUp. A 409 is
            // safe only if the authoritative checkout still exists.
            return $this->get($checkoutId);
        }
    }

    public function deactivatePaymentInstrument(string $customerId, string $token): void
    {
        if ($customerId === '' || $token === '') {
            throw new PaymentProcessorException(E::ts('Invalid SumUp payment instrument identifiers.'));
        }
        $this->client->customers()->deactivatePaymentInstrument(
            $customerId,
            $token,
            $this->requestOptions()
        );
    }

    public function findByReference(string $reference): ?CheckoutSuccess
    {
        $params = new CheckoutsListParams();
        $params->checkoutReference = $reference;
        $matches = $this->client->checkouts()->list($params, $this->requestOptions());
        if (count($matches) > 1) {
            throw new PaymentProcessorException(E::ts('SumUp returned several checkouts for one reference.'));
        }
        return $matches[0] ?? null;
    }

    public static function recurringChargeReference(int $contributionId, string $setupCheckoutId): string
    {
        return sprintf('CIVI-%d-%s', $contributionId, substr(hash('sha256', $setupCheckoutId), 0, 16));
    }

    public function get(string $checkoutId): CheckoutSuccess
    {
        if (!self::isValidCheckoutId($checkoutId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp checkout identifier.'));
        }

        return $this->client->checkouts()->get($checkoutId, $this->requestOptions());
    }

    public function getTransaction(string $transactionReference): TransactionFull
    {
        if (!preg_match('/^[A-Za-z0-9_-]{4,100}$/', $transactionReference)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp transaction reference.'));
        }

        $params = new TransactionsGetParams();
        if (preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $transactionReference)) {
            $params->id = $transactionReference;
        } else {
            $params->transactionCode = $transactionReference;
        }

        return $this->client->transactions()->get($this->merchantCode, $params, $this->requestOptions());
    }

    public function getTransactionByClientTransactionId(string $clientTransactionId): TransactionFull
    {
        if (!preg_match('/^[A-Za-z0-9_-]{8,100}$/', $clientTransactionId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp client transaction identifier.'));
        }

        $params = new TransactionsGetParams();
        $params->clientTransactionId = $clientTransactionId;

        return $this->client->transactions()->get($this->merchantCode, $params, $this->requestOptions());
    }

    public function refundTransaction(string $transactionId, ?float $amount): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{4,100}$/', $transactionId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp transaction identifier.'));
        }

        $path = sprintf(
            '/v1.0/merchants/%s/payments/%s/refunds',
            rawurlencode($this->merchantCode),
            rawurlencode($transactionId)
        );
        $body = $amount === null ? [] : ['amount' => $amount];
        $response = $this->client->request('POST', $path, $body, $this->requestOptions());

        // SumUp currently documents an empty JSON object for a successful
        // refund, while sumup-php 0.1.4 only accepts a 204 response. Decode
        // both observed and documented successful variants without weakening
        // non-2xx error handling.
        ResponseDecoder::decodeOrThrow(
            $response,
            [
                '200' => ['type' => 'void'],
                '201' => ['type' => 'void'],
                '202' => ['type' => 'void'],
                '204' => ['type' => 'void'],
            ],
            null,
            'POST',
            $path
        );
    }

    public function assertMatchesContribution(
        CheckoutSuccess $checkout,
        int $contributionId,
        float $amount,
        string $currency
    ): void {
        $referenceContributionId = self::getContributionIdFromReference(
            (string) $checkout->checkoutReference
        );
        if ($referenceContributionId !== $contributionId) {
            throw new PaymentProcessorException(
                E::ts('SumUp checkout reference does not match the contribution.')
            );
        }

        if (!hash_equals($this->merchantCode, (string) $checkout->merchantCode)) {
            throw new PaymentProcessorException(
                E::ts('SumUp checkout merchant does not match the configured merchant.')
            );
        }

        if (self::toMinorUnits((float) $checkout->amount) !== self::toMinorUnits($amount)) {
            throw new PaymentProcessorException(E::ts('SumUp checkout amount does not match the contribution.'));
        }

        if (strtoupper((string) $checkout->currency) !== strtoupper($currency)) {
            throw new PaymentProcessorException(E::ts('SumUp checkout currency does not match the contribution.'));
        }
    }

    public static function getContributionIdFromReference(string $reference): int
    {
        if (!preg_match(self::REFERENCE_PATTERN, $reference, $matches)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp checkout reference.'));
        }

        return (int) $matches[1];
    }

    public static function isValidCheckoutId(string $checkoutId): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{8,100}$/', $checkoutId);
    }

    /**
     * Retrieve the merchant's profile, commercial name (Doing Business As), and currency.
     *
     * @return array{
     *   merchant_code: string,
     *   business_name: string,
     *   company_name: string,
     *   country: string,
     *   currency: string
     * }
     */
    public function getMerchantProfile(?int $processorId = null): array
    {
        $cacheKey = $processorId !== null && $processorId > 0
            ? 'sumup.merchant_profile.' . $processorId
            : 'sumup.merchant_profile.' . md5($this->apiKey . ':' . $this->merchantCode);

        try {
            $cached = Civi::cache('long')->get($cacheKey);
            if (is_array($cached) && !empty($cached['merchant_code'])) {
                /** @var array{merchant_code: string, business_name: string, company_name: string, country: string, currency: string} $cached */
                return $cached;
            }
        } catch (\Throwable) {
            // Non-blocking cache lookup failure
        }

        try {
            $path = '/v0.1/me/merchant-profile';
            $response = $this->client->request('GET', $path, [], $this->requestOptions());
            $body = (string) $response->getBody();
            $data = json_decode($body, true);
            if (!is_array($data)) {
                throw new PaymentProcessorException(E::ts('Unable to retrieve SumUp merchant profile.'));
            }

            $merchantCode = (string) ($data['merchant_code'] ?? $this->merchantCode);
            $businessName = (string) (
                $data['doing_business_as']['business_name']
                ?? $data['business_name']
                ?? $data['company_name']
                ?? $merchantCode
            );
            $companyName = (string) ($data['company_name'] ?? $businessName);
            $country = (string) ($data['country'] ?? '');
            $currency = (string) ($data['currency'] ?? '');

            $profile = [
                'merchant_code' => $merchantCode,
                'business_name' => $businessName,
                'company_name' => $companyName,
                'country' => $country,
                'currency' => $currency,
            ];

            try {
                Civi::cache('long')->set($cacheKey, $profile, 86400);
            } catch (\Throwable) {
                // Non-blocking cache store failure
            }

            return $profile;
        } catch (\Throwable) {
            return [
                'merchant_code' => $this->merchantCode,
                'business_name' => $this->merchantCode,
                'company_name' => $this->merchantCode,
                'country' => '',
                'currency' => 'EUR',
            ];
        }
    }

    private static function toMinorUnits(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function requestOptions(): RequestOptions
    {
        return new RequestOptions(
            timeout: 15,
            connectTimeout: 5,
            retries: 2,
            retryBackoffMs: 300
        );
    }
}
