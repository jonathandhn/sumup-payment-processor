<?php

declare(strict_types=1);

use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;
use SumUp\HttpClient\RequestOptions;
use SumUp\SumUp;
use SumUp\Types\Checkout;
use SumUp\Types\CheckoutCreateRequest;
use SumUp\Types\CheckoutSuccess;
use SumUp\Types\HostedCheckout;
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
        string $browserReturnUrl,
        bool $hosted = false
    ): Checkout {
        if ($contributionId <= 0 || $amount <= 0.0) {
            throw new PaymentProcessorException(E::ts('Unable to create a SumUp checkout for this contribution.'));
        }

        $reference = sprintf('CIVI-%d-%s', $contributionId, bin2hex(random_bytes(8)));
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
            redirectUrl: $browserReturnUrl,
            hostedCheckout: $hostedCheckout
        );

        return $this->client->checkouts()->create($request, $this->requestOptions());
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

    public function refundTransaction(string $transactionId, ?float $amount): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{4,100}$/', $transactionId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp transaction identifier.'));
        }

        $body = $amount === null ? null : ['amount' => $amount];
        $this->client->transactions()->refund(
            $this->merchantCode,
            $transactionId,
            $body,
            $this->requestOptions()
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
