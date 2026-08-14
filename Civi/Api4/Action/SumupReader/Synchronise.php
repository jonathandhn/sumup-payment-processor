<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupReader;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\SumupReader;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * Synchronise SumUp readers already paired with a merchant account.
 *
 * @method $this setPaymentProcessorId(int $paymentProcessorId)
 */
final class Synchronise extends AbstractAction
{
    protected int $paymentProcessorId = 0;

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $service = \CRM_SumupPaymentProcessor_ReaderService::fromPaymentProcessorId(
            $this->paymentProcessorId
        );

        $cloudReaderIds = [];
        $cloudDeviceIds = [];

        foreach ($service->list()->items as $reader) {
            $cloudReaderIds[] = $reader->id;
            $cloudDeviceIds[] = trim($reader->device->identifier);

            $siteCode = strtoupper(trim((string) ($reader->metadata['civi_site_code'] ?? '')));
            if ($siteCode === '') {
                $result[] = [
                    'reader_id' => $reader->id,
                    'status' => 'unassigned',
                    'message' => E::ts('The SumUp reader has no CiviCRM site code.'),
                ];
                continue;
            }
            $siteCode = \CRM_SumupPaymentProcessor_ReaderService::normaliseSiteCode($siteCode);
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
                    \Civi::log()->warning('Unable to read SumUp reader status: ' . $exception->getMessage());
                }
            }
            $result[] = \CRM_SumupPaymentProcessor_ReaderStore::upsert(
                $service->getPaymentProcessorId(),
                $reader,
                $siteCode,
                $status
            );
        }

        // Deactivate local readers that no longer exist in SumUp Cloud
        $localReaders = SumupReader::get(false)
            ->addWhere('payment_processor_id', '=', $this->paymentProcessorId)
            ->addWhere('is_active', '=', true)
            ->execute();

        foreach ($localReaders as $local) {
            $readerId = (string) $local['reader_id'];
            $deviceId = (string) $local['device_identifier'];
            if (!in_array($readerId, $cloudReaderIds, true) && !in_array($deviceId, $cloudDeviceIds, true)) {
                SumupReader::update(false)
                    ->addWhere('id', '=', (int) $local['id'])
                    ->setValues([
                        'is_active' => false,
                        'pairing_status' => 'unpaired',
                        'modified_date' => date('Y-m-d H:i:s'),
                        'last_sync_date' => date('Y-m-d H:i:s'),
                    ])
                    ->execute();
                \Civi::log()->info(sprintf(
                    'SumUp reader %s (%s) marked inactive: removed from SumUp Cloud API.',
                    $local['canonical_name'],
                    $readerId
                ));
            }
        }
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
