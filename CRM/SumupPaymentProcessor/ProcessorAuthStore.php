<?php

declare(strict_types = 1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_ProcessorAuthStore {
  private const TABLE = 'civicrm_sumup_processor_auth';

  /**
   * @return array<string,mixed>|null */
  public function get(int $paymentProcessorId): ?array {
    $dao = CRM_Core_DAO::executeQuery(
        'SELECT * FROM ' . self::TABLE . ' WHERE payment_processor_id = %1',
        [1 => [$paymentProcessorId, 'Integer']]
    );
    assert($dao instanceof CRM_Core_DAO);
    $rows = $dao->fetchAll();
    if ($rows === []) {
      return NULL;
    }
    $row = $rows[0];

    return [
      'payment_processor_id' => (int) ($row['payment_processor_id'] ?? 0),
      'merchant_code' => (string) ($row['merchant_code'] ?? ''),
      'access_token' => (string) ($row['access_token'] ?? ''),
      'refresh_token' => (string) ($row['refresh_token'] ?? ''),
      'scope' => (string) ($row['scope'] ?? ''),
      'expires_at' => (int) ($row['expires_at'] ?? 0),
      'status' => (string) ($row['status'] ?? ''),
      'linked_at' => (string) ($row['linked_at'] ?? ''),
      'last_refresh_at' => isset($row['last_refresh_at']) ? (string) $row['last_refresh_at'] : NULL,
      'last_error' => isset($row['last_error']) ? (string) $row['last_error'] : NULL,
    ];
  }

  /**
   * @param int $paymentProcessorId
   * @param array<string,mixed> $link */
  public function save(int $paymentProcessorId, array $link): void {
    CRM_Core_DAO::executeQuery(
        'INSERT INTO ' . self::TABLE . ' (
                payment_processor_id, merchant_code, access_token, refresh_token, scope,
                expires_at, status, linked_at, last_refresh_at, last_error, modified_date
            ) VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, %10, NOW())
            ON DUPLICATE KEY UPDATE
                merchant_code = VALUES(merchant_code),
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                scope = VALUES(scope),
                expires_at = VALUES(expires_at),
                status = VALUES(status),
                linked_at = VALUES(linked_at),
                last_refresh_at = VALUES(last_refresh_at),
                last_error = VALUES(last_error),
                modified_date = NOW()',
        [
          1 => [$paymentProcessorId, 'Integer'],
          2 => [(string) ($link['merchant_code'] ?? ''), 'String'],
          3 => [(string) ($link['access_token'] ?? ''), 'String'],
          4 => [(string) ($link['refresh_token'] ?? ''), 'String'],
          5 => [(string) ($link['scope'] ?? ''), 'String'],
          6 => [(int) ($link['expires_at'] ?? 0), 'Integer'],
          7 => [(string) ($link['status'] ?? 'connected'), 'String'],
          8 => [(string) ($link['linked_at'] ?? date('Y-m-d H:i:s')), 'String'],
          9 => [
            isset($link['last_refresh_at'])
            ? CRM_Utils_Date::isoToMysql((string) $link['last_refresh_at'])
            : NULL,
            'Timestamp',
          ],
          10 => isset($link['last_error'])
          ? [(string) $link['last_error'], 'String']
          : ['NULL', 'String', CRM_Core_DAO::QUERY_FORMAT_NO_QUOTES],
        ]
    );
  }

  public function markReconnectRequired(int $paymentProcessorId, string $message): void {
    CRM_Core_DAO::executeQuery(
        'UPDATE ' . self::TABLE . '
             SET status = %1, last_error = %2, modified_date = NOW()
             WHERE payment_processor_id = %3',
        [
          1 => ['reconnect_required', 'String'],
          2 => [mb_substr($message, 0, 1000), 'String'],
          3 => [$paymentProcessorId, 'Integer'],
        ]
    );
  }

  public function delete(int $paymentProcessorId): void {
    CRM_Core_DAO::executeQuery(
        'DELETE FROM ' . self::TABLE . ' WHERE payment_processor_id = %1',
        [1 => [$paymentProcessorId, 'Integer']]
    );
  }

  public function isConnected(int $paymentProcessorId): bool {
    $link = $this->get($paymentProcessorId);
    return $link !== NULL && $link['status'] === 'connected' && $link['refresh_token'] !== '';
  }

}
