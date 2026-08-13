<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupReader;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Attach one already paired SumUp Solo to an explicit CiviCRM site code.
 *
 * @method $this setPaymentProcessorId(int $paymentProcessorId)
 * @method $this setReaderId(string $readerId)
 * @method $this setSiteCode(string $siteCode)
 */
final class Adopt extends AbstractAction
{
    protected int $paymentProcessorId = 0;

    protected string $readerId = '';

    protected string $siteCode = '';

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $siteCode = \CRM_SumupPaymentProcessor_ReaderService::normaliseSiteCode($this->siteCode);
        $service = \CRM_SumupPaymentProcessor_ReaderService::fromPaymentProcessorId(
            $this->paymentProcessorId
        );
        $reader = $service->get(trim($this->readerId));
        $canonicalName = \CRM_SumupPaymentProcessor_ReaderService::canonicalName(
            $siteCode,
            $reader->device->identifier
        );
        if (
            $reader->name !== $canonicalName
            || (string) ($reader->metadata['civi_site_code'] ?? '') !== $siteCode
        ) {
            $reader = $service->rename($reader, $canonicalName, $siteCode);
        }

        $status = null;
        if ($reader->status->value === 'paired') {
            $status = $service->getStatus($reader->id);
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
