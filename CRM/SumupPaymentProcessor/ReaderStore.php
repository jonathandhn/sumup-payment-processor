<?php

declare(strict_types=1);

use Civi\Api4\SumupReader;
use SumUp\Types\Reader;
use SumUp\Types\StatusResponse;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_ReaderStore
{
    /**
     * @return array<string, mixed>
     */
    public static function upsert(
        int $paymentProcessorId,
        Reader $reader,
        string $siteCode,
        ?StatusResponse $deviceStatus = null
    ): array {
        $deviceIdentifier = trim($reader->device->identifier);
        $canonicalName = CRM_SumupPaymentProcessor_ReaderService::canonicalName(
            $siteCode,
            $deviceIdentifier
        );
        $values = [
            'payment_processor_id' => $paymentProcessorId,
            'reader_id' => $reader->id,
            'device_identifier' => $deviceIdentifier,
            'site_code' => $siteCode,
            'canonical_name' => $canonicalName,
            'model' => $reader->device->model->value,
            'pairing_status' => $reader->status->value,
            'device_status' => $deviceStatus?->data->status->value,
            'device_state' => $deviceStatus?->data->state?->value,
            'is_active' => $reader->status->value === 'paired',
            'modified_date' => date('Y-m-d H:i:s'),
            'last_sync_date' => date('Y-m-d H:i:s'),
        ];

        $existing = SumupReader::get(false)
            ->addSelect('id')
            ->addWhere('payment_processor_id', '=', $paymentProcessorId)
            ->addWhere('reader_id', '=', $reader->id)
            ->setLimit(1)
            ->execute()
            ->first();
        if (!$existing) {
            $existing = SumupReader::get(false)
                ->addSelect('id')
                ->addWhere('payment_processor_id', '=', $paymentProcessorId)
                ->addWhere('device_identifier', '=', $deviceIdentifier)
                ->setLimit(1)
                ->execute()
                ->first();
        }
        if ($existing) {
            return SumupReader::update(false)
                ->addWhere('id', '=', (int) $existing['id'])
                ->setValues($values)
                ->execute()
                ->single();
        }

        return SumupReader::create(false)
            ->setValues($values)
            ->execute()
            ->single();
    }
}
