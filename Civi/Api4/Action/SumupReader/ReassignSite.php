<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupReader;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\SumupReader;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * Reassign one paired SumUp Solo to another site code / pool.
 *
 * @method $this setId(int $id)
 * @method $this setPaymentProcessorId(int $paymentProcessorId)
 * @method $this setReaderId(string $readerId)
 * @method $this setSiteCode(string $siteCode)
 */
final class ReassignSite extends AbstractAction
{
    protected int $id = 0;

    protected int $paymentProcessorId = 0;

    protected string $readerId = '';

    protected string $siteCode = '';

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $processorId = $this->paymentProcessorId;
        $readerId = trim($this->readerId);

        if ($this->id > 0) {
            $local = SumupReader::get(false)
                ->addSelect('id', 'payment_processor_id', 'reader_id')
                ->addWhere('id', '=', $this->id)
                ->execute()
                ->first();
            if ($local) {
                $processorId = (int) $local['payment_processor_id'];
                $readerId = (string) $local['reader_id'];
            }
        }

        if ($processorId <= 0 || $readerId === '') {
            throw new PaymentProcessorException(
                E::ts('Missing reader identifier or payment processor for reassignment.')
            );
        }

        $siteCode = \CRM_SumupPaymentProcessor_ReaderService::normaliseSiteCode($this->siteCode);
        $service = \CRM_SumupPaymentProcessor_ReaderService::fromPaymentProcessorId($processorId);
        $reader = $service->get($readerId);
        $canonicalName = \CRM_SumupPaymentProcessor_ReaderService::canonicalName(
            $siteCode,
            $reader->device->identifier
        );

        $reader = $service->rename($reader, $canonicalName, $siteCode);

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
