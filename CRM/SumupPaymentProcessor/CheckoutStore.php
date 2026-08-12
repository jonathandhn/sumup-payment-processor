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
        string $checkoutMode
    ): void {
        self::assertIdentifiers($checkoutId, $checkoutReference, $contributionId, $paymentProcessorId);
        if (!array_key_exists($checkoutMode, CRM_SumupPaymentProcessor_CheckoutMode::getOptions())) {
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
     *   transaction_id: string|null
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
                'transaction_id'
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
     *   transaction_id: string|null
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
                'transaction_id'
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
     *   transaction_id: string|null
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
        ];
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
