<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupReader;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * Pair a SumUp Solo or Virtual Solo with one CiviCRM site.
 *
 * @method $this setPaymentProcessorId(int $paymentProcessorId)
 * @method $this setSiteCode(string $siteCode)
 * @method $this setPairingCode(string $pairingCode)
 */
final class Pair extends AbstractAction
{
    protected int $paymentProcessorId = 0;

    protected string $siteCode = '';

    protected string $pairingCode = '';

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $pairingCode = strtoupper(trim($this->pairingCode));
        if (!preg_match('/^[A-Z0-9]{8,9}$/', $pairingCode)) {
            throw new \CRM_Core_Exception(E::ts('The SumUp pairing code must contain 8 or 9 letters or numbers.'));
        }
        $siteCode = \CRM_SumupPaymentProcessor_ReaderService::normaliseSiteCode($this->siteCode);
        $service = \CRM_SumupPaymentProcessor_ReaderService::fromPaymentProcessorId(
            $this->paymentProcessorId
        );
        $pairingReference = bin2hex(random_bytes(8));
        try {
            $reader = $service->pair($pairingCode, $siteCode, $pairingReference);
        } catch (\SumUp\Exception\ApiException $exception) {
            // Virtual Solo can complete pairing while the create request reports
            // a transient 404. Recover only the reader tagged by this exact call.
            $reader = $service->findByPairingReference($pairingReference);
            if ($reader === null) {
                throw $exception;
            }
        }
        $canonicalName = \CRM_SumupPaymentProcessor_ReaderService::canonicalName(
            $siteCode,
            $reader->device->identifier
        );
        if ($reader->name !== $canonicalName) {
            $reader = $service->rename($reader, $canonicalName, $siteCode);
        }
        $status = null;
        if ($reader->status->value === 'paired') {
            try {
                $status = $service->getStatus($reader->id);
            } catch (\Throwable $exception) {
                \Civi::log()->warning('Unable to read newly paired SumUp reader status: ' . $exception->getMessage());
            }
        }
        $result[] = \CRM_SumupPaymentProcessor_ReaderStore::upsert(
            $service->getPaymentProcessorId(),
            $reader,
            $siteCode,
            $status
        );
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
