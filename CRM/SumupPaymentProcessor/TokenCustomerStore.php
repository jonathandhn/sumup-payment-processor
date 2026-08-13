<?php

declare(strict_types=1);

use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_TokenCustomerStore
{
    public static function remember(int $paymentTokenId, string $customerId): void
    {
        self::assertIdentifiers($paymentTokenId, $customerId);
        $existing = self::get($paymentTokenId);
        if ($existing !== null) {
            if (!hash_equals($existing, $customerId)) {
                throw new PaymentProcessorException(E::ts(
                    'The saved SumUp card is already linked to another SumUp customer.'
                ));
            }
            return;
        }

        CRM_Core_DAO::executeQuery(
            'INSERT INTO civicrm_sumup_payment_token_customer
                (payment_token_id, customer_id, modified_date)
             VALUES (%1, %2, NOW())',
            [
                1 => [$paymentTokenId, 'Integer'],
                2 => [$customerId, 'String'],
            ]
        );
    }

    public static function get(int $paymentTokenId): ?string
    {
        if ($paymentTokenId <= 0) {
            return null;
        }
        $dao = CRM_Core_DAO::executeQuery(
            'SELECT customer_id
             FROM civicrm_sumup_payment_token_customer
             WHERE payment_token_id = %1',
            [1 => [$paymentTokenId, 'Integer']]
        );
        $customerId = trim((string) $dao->fetchValue());
        return self::isValidCustomerId($customerId) ? $customerId : null;
    }

    public static function isValidCustomerId(string $customerId): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{4,100}$/', $customerId) === 1;
    }

    private static function assertIdentifiers(int $paymentTokenId, string $customerId): void
    {
        if ($paymentTokenId <= 0 || !self::isValidCustomerId($customerId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp saved-card customer mapping.'));
        }
    }
}
