<?php

declare(strict_types=1);

use Civi\Api4\PaymentProcessor;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;
use SumUp\HttpClient\RequestOptions;
use SumUp\Services\ReadersCreateRequest;
use SumUp\Services\ReadersListResponse;
use SumUp\Services\ReadersUpdateRequest;
use SumUp\SumUp;
use SumUp\Types\CreateReaderCheckoutResponse;
use SumUp\Types\Reader;
use SumUp\Types\StatusResponse;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_ReaderService
{
    private SumUp $client;

    public function __construct(
        private readonly int $paymentProcessorId,
        private readonly string $merchantCode,
        string $apiKey
    ) {
        if ($paymentProcessorId <= 0 || $merchantCode === '' || $apiKey === '') {
            throw new PaymentProcessorException(E::ts('The SumUp Cloud API processor is not configured.'));
        }
        $this->client = new SumUp($apiKey);
    }

    public static function fromPaymentProcessorId(int $paymentProcessorId): self
    {
        $processor = PaymentProcessor::get(false)
            ->addSelect('id', 'class_name', 'user_name', 'password')
            ->addWhere('id', '=', $paymentProcessorId)
            ->execute()
            ->single();
        if (($processor['class_name'] ?? '') !== 'Payment_Sumup') {
            throw new PaymentProcessorException(E::ts('The selected payment processor is not a SumUp processor.'));
        }

        return new self(
            (int) $processor['id'],
            trim((string) ($processor['user_name'] ?? '')),
            trim((string) ($processor['password'] ?? ''))
        );
    }

    public function pair(string $pairingCode, string $siteCode, string $pairingReference): Reader
    {
        $temporaryName = sprintf('TPE-%s-PAIR-%s', $siteCode, strtoupper(substr($pairingReference, -6)));
        return $this->client->readers()->create(
            $this->merchantCode,
            new ReadersCreateRequest(
                $pairingCode,
                $temporaryName,
                [
                    'civi_site_code' => $siteCode,
                    'civi_pairing_reference' => $pairingReference,
                ]
            ),
            $this->requestOptions()
        );
    }

    public function findByPairingReference(string $pairingReference): ?Reader
    {
        foreach ($this->list()->items as $reader) {
            if (($reader->metadata['civi_pairing_reference'] ?? null) === $pairingReference) {
                return $reader;
            }
        }
        return null;
    }

    public function rename(Reader $reader, string $canonicalName, string $siteCode): Reader
    {
        return $this->client->readers()->update(
            $this->merchantCode,
            $reader->id,
            new ReadersUpdateRequest($canonicalName, ['civi_site_code' => $siteCode]),
            $this->requestOptions()
        );
    }

    public function list(): ReadersListResponse
    {
        return $this->client->readers()->list($this->merchantCode, $this->requestOptions());
    }

    public function getStatus(string $readerId): StatusResponse
    {
        return $this->client->readers()->getStatus(
            $this->merchantCode,
            $readerId,
            $this->requestOptions()
        );
    }

    public function get(string $readerId): Reader
    {
        if (!preg_match('/^rdr_[A-Za-z0-9]{20,}$/', $readerId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp reader identifier.'));
        }
        return $this->client->readers()->get(
            $this->merchantCode,
            $readerId,
            $this->requestOptions()
        );
    }

    public function delete(string $readerId): void
    {
        if (!preg_match('/^rdr_[A-Za-z0-9]{20,}$/', $readerId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp reader identifier.'));
        }
        $this->client->readers()->delete(
            $this->merchantCode,
            $readerId,
            $this->requestOptions()
        );
    }

    public function createCheckout(
        string $readerId,
        int $amountMinor,
        string $currency,
        string $description,
        string $returnUrl,
        string $foreignTransactionId
    ): CreateReaderCheckoutResponse {
        $affiliateAppId = trim((string) Civi::settings()->get('sumup_affiliate_app_id'));
        $affiliateKey = trim((string) Civi::settings()->get('sumup_affiliate_key'));
        if (
            !preg_match('/^rdr_[A-Za-z0-9]{20,}$/', $readerId)
            || $amountMinor <= 0
            || !preg_match('/^[A-Z]{3}$/', $currency)
            || !str_starts_with($returnUrl, 'https://')
            || !preg_match('/^[A-Za-z0-9._-]{3,100}$/', $affiliateAppId)
            || $affiliateKey === ''
            || strlen($affiliateKey) > 255
            || !preg_match('/^CIVI-[1-9][0-9]*-[a-f0-9]{16}$/', $foreignTransactionId)
        ) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp terminal checkout request.'));
        }

        return $this->client->readers()->createCheckout(
            $this->merchantCode,
            $readerId,
            [
                'total_amount' => [
                    'currency' => $currency,
                    'minor_unit' => 2,
                    'value' => $amountMinor,
                ],
                'affiliate' => [
                    'app_id' => $affiliateAppId,
                    'key' => $affiliateKey,
                    'foreign_transaction_id' => $foreignTransactionId,
                ],
                'description' => mb_substr($description, 0, 255),
                'return_url' => $returnUrl,
            ],
            $this->requestOptions()
        );
    }

    public function getPaymentProcessorId(): int
    {
        return $this->paymentProcessorId;
    }

    public static function normaliseSiteCode(string $siteCode): string
    {
        $normalised = strtoupper(trim($siteCode));
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $normalised)) {
            throw new PaymentProcessorException(
                E::ts('The terminal site code must contain 2 to 12 uppercase letters or numbers.')
            );
        }
        return $normalised;
    }

    public static function canonicalName(string $siteCode, string $deviceIdentifier): string
    {
        $suffix = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $deviceIdentifier));
        $suffix = substr($suffix, -8);
        if (strlen($suffix) < 4) {
            throw new PaymentProcessorException(E::ts('SumUp did not return a usable device identifier.'));
        }
        return sprintf('TPE-%s-%s', self::normaliseSiteCode($siteCode), $suffix);
    }

    private function requestOptions(): RequestOptions
    {
        return new RequestOptions(
            timeout: 15,
            connectTimeout: 5,
            retries: 1,
            retryBackoffMs: 300
        );
    }
}
