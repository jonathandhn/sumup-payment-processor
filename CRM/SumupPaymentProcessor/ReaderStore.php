<?php

declare(strict_types = 1);

use Civi\Api4\SumupReader;
use SumUp\Types\Reader;
use SumUp\Types\StatusResponse;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_ReaderStore {

  /**
   * Return the paired readers that may be selected by an Afform checkout.
   *
   * The local record ID is the only browser-submitted value. The checkout
   * action revalidates processor ownership and availability before use.
   *
   * @return list<array{id: string, label: string}>
   */
  public static function getCheckoutOptions(int $paymentProcessorId): array {
    if ($paymentProcessorId <= 0) {
      return [];
    }

    $readers = SumupReader::get(FALSE)
      ->addSelect('id', 'canonical_name', 'site_code')
      ->addWhere('payment_processor_id', '=', $paymentProcessorId)
      ->addWhere('pairing_status', '=', 'paired')
      ->addWhere('is_active', '=', TRUE)
      ->addOrderBy('site_code', 'ASC')
      ->addOrderBy('canonical_name', 'ASC')
      ->execute();

    $options = [];
    foreach ($readers as $reader) {
      $siteCode = trim((string) ($reader['site_code'] ?? ''));
      $readerName = trim((string) ($reader['canonical_name'] ?? ''));
      $label = $siteCode !== ''
        ? sprintf('%s - %s', $siteCode, $readerName)
        : $readerName;
      $options[] = [
        'id' => (string) $reader['id'],
        'label' => $label,
      ];
    }

    return $options;
  }

  /**
   * @return array<string,mixed>
   */
  public static function upsert(
        int $paymentProcessorId,
        Reader $reader,
        string $siteCode,
        ?StatusResponse $deviceStatus = NULL
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

    $existing = SumupReader::get(FALSE)
      ->addSelect('id')
      ->addWhere('payment_processor_id', '=', $paymentProcessorId)
      ->addWhere('reader_id', '=', $reader->id)
      ->setLimit(1)
      ->execute()
      ->first();
    if (!$existing) {
      $existing = SumupReader::get(FALSE)
        ->addSelect('id')
        ->addWhere('payment_processor_id', '=', $paymentProcessorId)
        ->addWhere('device_identifier', '=', $deviceIdentifier)
        ->setLimit(1)
        ->execute()
        ->first();
    }
    if ($existing) {
      return SumupReader::update(FALSE)
        ->addWhere('id', '=', (int) $existing['id'])
        ->setValues($values)
        ->execute()
        ->single();
    }

    return SumupReader::create(FALSE)
      ->setValues($values)
      ->execute()
      ->single();
  }

}
