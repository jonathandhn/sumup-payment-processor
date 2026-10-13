<?php

declare(strict_types = 1);

namespace Civi\Api4\Action\SumupReader;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\SumupReader;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * Unpair and delete a SumUp Solo reader from SumUp Cloud API and CiviCRM.
 *
 * @method $this setId(int $id)
 * @method $this setPaymentProcessorId(int $paymentProcessorId)
 * @method $this setReaderId(string $readerId)
 * @method $this setDeleteLocal(bool $deleteLocal)
 */
final class Unpair extends AbstractAction {
  protected int $id = 0;

  protected int $paymentProcessorId = 0;

  protected string $readerId = '';

  protected bool $deleteLocal = FALSE;

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
  public function _run(Result $result): void {
    $localRecord = NULL;
    if ($this->id > 0) {
      $localRecord = SumupReader::get(FALSE)
        ->addWhere('id', '=', $this->id)
        ->execute()
        ->first();
      if ($localRecord) {
        $this->paymentProcessorId = (int) $localRecord['payment_processor_id'];
        $this->readerId = (string) $localRecord['reader_id'];
      }
    }

    if ($this->paymentProcessorId <= 0 || $this->readerId === '') {
      throw new \CRM_Core_Exception(E::ts('A valid payment processor and reader identifier are required.'));
    }

    $service = \CRM_SumupPaymentProcessor_ReaderService::fromPaymentProcessorId(
        $this->paymentProcessorId
    );

    $cloudDeleted = FALSE;
    try {
      $service->delete(trim($this->readerId));
      $cloudDeleted = TRUE;
    }
    catch (\SumUp\Exception\ApiException $exception) {
      // If already deleted from SumUp (404), proceed with local cleanup
      if ($exception->getStatusCode() !== 404) {
        throw $exception;
      }
    }

    if ($this->deleteLocal) {
      if ($localRecord) {
        SumupReader::delete(FALSE)
          ->addWhere('id', '=', (int) $localRecord['id'])
          ->execute();
      }
      else {
        SumupReader::delete(FALSE)
          ->addWhere('payment_processor_id', '=', $this->paymentProcessorId)
          ->addWhere('reader_id', '=', $this->readerId)
          ->execute();
      }
      $result[] = [
        'reader_id' => $this->readerId,
        'status' => 'deleted',
        'cloud_deleted' => $cloudDeleted,
      ];
      return;
    }

    $updated = [
      'pairing_status' => 'unpaired',
      'is_active' => FALSE,
      'modified_date' => date('Y-m-d H:i:s'),
      'last_sync_date' => date('Y-m-d H:i:s'),
    ];

    $query = SumupReader::update(FALSE)->setValues($updated);
    if ($localRecord) {
      $query->addWhere('id', '=', (int) $localRecord['id']);
    }
    else {
      $query->addWhere('payment_processor_id', '=', $this->paymentProcessorId)
        ->addWhere('reader_id', '=', $this->readerId);
    }
    $record = $query->execute()->first();

    $result[] = $record !== [] ? $record : [
      'reader_id' => $this->readerId,
      'status' => 'unpaired',
      'cloud_deleted' => $cloudDeleted,
    ];
  }

    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore
}
