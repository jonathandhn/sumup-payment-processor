<?php

declare(strict_types=1);

use Civi\Api4\SumupCheckout;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_CheckoutStore
{
    public static function recordCreated(
        string $checkoutId,
        string $checkoutReference,
        int $contributionId,
        int $paymentProcessorId,
        float $amount,
        string $currency,
        string $checkoutMode,
        ?string $readerId = null,
        string $purpose = 'PAYMENT',
        ?string $customerId = null,
        ?string $setupCheckoutId = null
    ): void {
        self::assertIdentifiers($checkoutId, $checkoutReference, $contributionId, $paymentProcessorId);
        if (!CRM_SumupPaymentProcessor_CheckoutMode::isValidAttemptMode($checkoutMode)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp checkout mode.'));
        }

        SumupCheckout::create(false)
            ->setValues([
                'checkout_id' => $checkoutId,
                'checkout_reference' => $checkoutReference,
                'contribution_id' => $contributionId,
                'payment_processor_id' => $paymentProcessorId,
                'state' => 'PENDING',
                'amount' => round($amount, 2),
                'currency' => strtoupper($currency),
                'checkout_mode' => $checkoutMode,
                'reader_id' => $readerId,
                'purpose' => $purpose,
                'customer_id' => $customerId,
                'setup_checkout_id' => $setupCheckoutId,
                'modified_date' => date('Y-m-d H:i:s'),
            ])
            ->execute();
    }

    /**
     * @return array{
     *   id: int,
     *   checkout_id: string,
     *   checkout_reference: string,
     *   contribution_id: int,
     *   payment_processor_id: int,
     *   state: string,
     *   amount: float,
     *   currency: string,
     *   checkout_mode: string,
     *   transaction_id: string|null,
     *   reader_id: string|null,
     *   purpose: string,
     *   customer_id: string|null,
     *   payment_token_id: int|null,
     *   setup_checkout_id: string|null
     * }
     */
    public static function getByCheckoutId(string $checkoutId): array
    {
        if (!CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($checkoutId)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp checkout identifier.'));
        }

        $record = SumupCheckout::get(false)
            ->addSelect(
                'id',
                'checkout_id',
                'checkout_reference',
                'contribution_id',
                'payment_processor_id',
                'state',
                'amount',
                'currency',
                'checkout_mode',
                'transaction_id',
                'reader_id',
                'purpose',
                'customer_id',
                'payment_token_id',
                'setup_checkout_id'
            )
            ->addWhere('checkout_id', '=', $checkoutId)
            ->execute()
            ->single();

        return self::normaliseRecord($record);
    }

    /**
     * Resolve the latest SumUp attempt without relying on Contribution.trxn_id,
     * which CiviCRM may append the final transaction identifier to.
     *
     * @return array{
     *   id: int,
     *   checkout_id: string,
     *   checkout_reference: string,
     *   contribution_id: int,
     *   payment_processor_id: int,
     *   state: string,
     *   amount: float,
     *   currency: string,
     *   checkout_mode: string,
     *   transaction_id: string|null,
     *   reader_id: string|null,
     *   purpose: string,
     *   customer_id: string|null,
     *   payment_token_id: int|null,
     *   setup_checkout_id: string|null
     * }
     */
    public static function getLatestByContributionId(int $contributionId, int $paymentProcessorId): array
    {
        if ($contributionId <= 0 || $paymentProcessorId <= 0) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp contribution or processor identifier.'));
        }
        $record = SumupCheckout::get(false)
            ->addSelect(
                'id',
                'checkout_id',
                'checkout_reference',
                'contribution_id',
                'payment_processor_id',
                'state',
                'amount',
                'currency',
                'checkout_mode',
                'transaction_id',
                'reader_id',
                'purpose',
                'customer_id',
                'payment_token_id',
                'setup_checkout_id'
            )
            ->addWhere('contribution_id', '=', $contributionId)
            ->addWhere('payment_processor_id', '=', $paymentProcessorId)
            ->addOrderBy('id', 'DESC')
            ->setLimit(1)
            ->execute()
            ->single();

        return self::normaliseRecord($record);
    }

    /**
     * @return array{
     *   id: int,
     *   checkout_id: string,
     *   checkout_reference: string,
     *   contribution_id: int,
     *   payment_processor_id: int,
     *   state: string,
     *   amount: float,
     *   currency: string,
     *   checkout_mode: string,
     *   transaction_id: string|null,
     *   reader_id: string|null,
     *   purpose: string,
     *   customer_id: string|null,
     *   payment_token_id: int|null,
     *   setup_checkout_id: string|null
     * }|null
     */
    public static function findLatestOnlineByContributionId(int $contributionId, int $paymentProcessorId): ?array
    {
        if ($contributionId <= 0 || $paymentProcessorId <= 0) {
            return null;
        }
        $record = SumupCheckout::get(false)
            ->addSelect(
                'id',
                'checkout_id',
                'checkout_reference',
                'contribution_id',
                'payment_processor_id',
                'state',
                'amount',
                'currency',
                'checkout_mode',
                'transaction_id',
                'reader_id',
                'purpose',
                'customer_id',
                'payment_token_id',
                'setup_checkout_id'
            )
            ->addWhere('contribution_id', '=', $contributionId)
            ->addWhere('payment_processor_id', '=', $paymentProcessorId)
            ->addWhere('checkout_mode', '!=', CRM_SumupPaymentProcessor_CheckoutMode::SOLO)
            ->addOrderBy('id', 'DESC')
            ->setLimit(1)
            ->execute()
            ->first();

        return $record ? self::normaliseRecord($record) : null;
    }

    /**
     * @return array{
     *   id: int,
     *   checkout_id: string,
     *   checkout_reference: string,
     *   contribution_id: int,
     *   payment_processor_id: int,
     *   state: string,
     *   amount: float,
     *   currency: string,
     *   checkout_mode: string,
     *   transaction_id: string|null,
     *   reader_id: string|null,
     *   purpose: string,
     *   customer_id: string|null,
     *   payment_token_id: int|null,
     *   setup_checkout_id: string|null
     * }
     */
    public static function getLatestOnlineByContributionId(int $contributionId, int $paymentProcessorId): array
    {
        if ($contributionId <= 0 || $paymentProcessorId <= 0) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp contribution or processor identifier.'));
        }
        $record = SumupCheckout::get(false)
            ->addSelect(
                'id',
                'checkout_id',
                'checkout_reference',
                'contribution_id',
                'payment_processor_id',
                'state',
                'amount',
                'currency',
                'checkout_mode',
                'transaction_id',
                'reader_id',
                'purpose',
                'customer_id',
                'payment_token_id',
                'setup_checkout_id'
            )
            ->addWhere('contribution_id', '=', $contributionId)
            ->addWhere('payment_processor_id', '=', $paymentProcessorId)
            ->addWhere('checkout_mode', '!=', CRM_SumupPaymentProcessor_CheckoutMode::SOLO)
            ->addOrderBy('id', 'DESC')
            ->setLimit(1)
            ->execute()
            ->first();

        if (!$record) {
            return self::getLatestByContributionId($contributionId, $paymentProcessorId);
        }

        return self::normaliseRecord($record);
    }

    /**
     * @param array<string, mixed> $record
     * @return array{
     *   id: int,
     *   checkout_id: string,
     *   checkout_reference: string,
     *   contribution_id: int,
     *   payment_processor_id: int,
     *   state: string,
     *   amount: float,
     *   currency: string,
     *   checkout_mode: string,
     *   transaction_id: string|null,
     *   reader_id: string|null,
     *   purpose: string,
     *   customer_id: string|null,
     *   payment_token_id: int|null,
     *   setup_checkout_id: string|null
     * }
     */
    private static function normaliseRecord(array $record): array
    {
        return [
            'id' => (int) $record['id'],
            'checkout_id' => (string) $record['checkout_id'],
            'checkout_reference' => (string) $record['checkout_reference'],
            'contribution_id' => (int) $record['contribution_id'],
            'payment_processor_id' => (int) $record['payment_processor_id'],
            'state' => (string) $record['state'],
            'amount' => (float) $record['amount'],
            'currency' => (string) $record['currency'],
            'checkout_mode' => (string) $record['checkout_mode'],
            'transaction_id' => isset($record['transaction_id'])
                ? (string) $record['transaction_id']
                : null,
            'reader_id' => isset($record['reader_id']) && $record['reader_id'] !== ''
                ? (string) $record['reader_id']
                : null,
            'purpose' => (string) ($record['purpose'] ?? 'PAYMENT'),
            'customer_id' => isset($record['customer_id']) && $record['customer_id'] !== ''
                ? (string) $record['customer_id']
                : null,
            'payment_token_id' => !empty($record['payment_token_id']) ? (int) $record['payment_token_id'] : null,
            'setup_checkout_id' => isset($record['setup_checkout_id']) && $record['setup_checkout_id'] !== ''
                ? (string) $record['setup_checkout_id']
                : null,
        ];
    }

    /**
     * @return array<string, int|float|string|null>|null
     */
    public static function getBySetupCheckoutId(string $setupCheckoutId): ?array
    {
        $record = SumupCheckout::get(false)
            ->addSelect('*')
            ->addWhere('setup_checkout_id', '=', $setupCheckoutId)
            ->setLimit(1)
            ->execute()
            ->first();
        return $record ? self::normaliseRecord($record) : null;
    }

    /**
     * @return array<string, int|float|string|null>|null
     */
    public static function getByCheckoutReference(string $checkoutReference): ?array
    {
        if (!preg_match('/^CIVI-[1-9][0-9]*-[a-f0-9]{16}$/', $checkoutReference)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp checkout reference.'));
        }
        $record = SumupCheckout::get(false)
            ->addSelect('*')
            ->addWhere('checkout_reference', '=', $checkoutReference)
            ->setLimit(1)
            ->execute()
            ->first();

        return $record ? self::normaliseRecord($record) : null;
    }

    public static function attachPaymentToken(string $checkoutId, int $paymentTokenId): void
    {
        SumupCheckout::update(false)
            ->addWhere('checkout_id', '=', $checkoutId)
            ->setValues([
                'payment_token_id' => $paymentTokenId,
                'modified_date' => date('Y-m-d H:i:s'),
            ])
            ->execute();
    }

    public static function recordVerifiedState(
        string $checkoutId,
        string $state,
        ?string $transactionId
    ): void {
        if (
            !CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($checkoutId)
            || !in_array($state, ['PENDING', 'PAID', 'FAILED', 'EXPIRED'], true)
        ) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp checkout state.'));
        }

        $values = [
            'state' => $state,
            'modified_date' => date('Y-m-d H:i:s'),
            'verified_date' => date('Y-m-d H:i:s'),
        ];
        if ($transactionId !== null) {
            $values['transaction_id'] = $transactionId;
        }

        SumupCheckout::update(false)
            ->addWhere('checkout_id', '=', $checkoutId)
            ->setValues($values)
            ->execute();
    }

    private static function assertIdentifiers(
        string $checkoutId,
        string $checkoutReference,
        int $contributionId,
        int $paymentProcessorId
    ): void {
        if (
            !CRM_SumupPaymentProcessor_CheckoutService::isValidCheckoutId($checkoutId)
            || CRM_SumupPaymentProcessor_CheckoutService::getContributionIdFromReference($checkoutReference)
                !== $contributionId
            || $paymentProcessorId <= 0
        ) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp checkout registry record.'));
        }
    }
}
