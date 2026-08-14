<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupReader;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\SumupReader;

/**
 * List all readers present on the SumUp merchant account with CiviCRM pairing status.
 *
 * @method $this setPaymentProcessorId(int $paymentProcessorId)
 */
final class ListDiscovered extends AbstractAction
{
    protected int $paymentProcessorId = 0;

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $service = \CRM_SumupPaymentProcessor_ReaderService::fromPaymentProcessorId(
            $this->paymentProcessorId
        );

        $localReaders = SumupReader::get(false)
            ->addWhere('payment_processor_id', '=', $this->paymentProcessorId)
            ->execute();
        $localByReaderId = [];
        $localByDevice = [];
        foreach ($localReaders as $row) {
            $localByReaderId[(string) $row['reader_id']] = $row;
            $localByDevice[(string) $row['device_identifier']] = $row;
        }

        foreach ($service->list()->items as $reader) {
            $deviceIdentifier = trim($reader->device->identifier);
            $local = $localByReaderId[$reader->id] ?? $localByDevice[$deviceIdentifier] ?? null;
            $siteCode = trim((string) ($reader->metadata['civi_site_code'] ?? ''));

            $result[] = [
                'reader_id' => $reader->id,
                'name' => $reader->name,
                'device_identifier' => $deviceIdentifier,
                'model' => $reader->device->model->value,
                'status' => $reader->status->value,
                'site_code' => $siteCode !== '' ? $siteCode : ($local['site_code'] ?? null),
                'is_paired_in_civi' => !empty($local['id']) && (bool) $local['is_active'],
                'civi_reader_id' => !empty($local['id']) ? (int) $local['id'] : null,
                'canonical_name' => $local['canonical_name'] ?? null,
                'created_at' => $reader->createdAt ?? null,
            ];
        }
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
