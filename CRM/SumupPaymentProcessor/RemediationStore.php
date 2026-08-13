<?php

declare(strict_types=1);

use Civi\Api4\SumupRemediation;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
final class CRM_SumupPaymentProcessor_RemediationStore
{
    /** @return array<string, mixed> */
    public static function open(
        int $contributionRecurId,
        int $contributionId,
        int $paymentProcessorId,
        ?string $checkoutId,
        ?int $paymentTokenId,
        string $reason,
        ?string $providerErrorCode = null
    ): array {
        if (
            $contributionRecurId <= 0
            || $contributionId <= 0
            || $paymentProcessorId <= 0
            || !in_array($reason, ['sca_required', 'payment_method_failed', 'customer_requested'], true)
        ) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp remediation request.'));
        }
        $existing = self::getOpen($contributionRecurId);
        $values = [
            'contribution_recur_id' => $contributionRecurId,
            'contribution_id' => $contributionId,
            'payment_processor_id' => $paymentProcessorId,
            'checkout_id' => $checkoutId,
            'payment_token_id' => $paymentTokenId,
            'reason' => $reason,
            'provider_error_code' => $providerErrorCode,
            'state' => 'customer_action_required',
            'modified_date' => date('Y-m-d H:i:s'),
        ];
        if ($existing !== null) {
            return SumupRemediation::update(false)
                ->addWhere('id', '=', (int) $existing['id'])
                ->setValues($values)
                ->execute()
                ->single();
        }

        return SumupRemediation::create(false)
            ->setValues($values)
            ->execute()
            ->single();
    }

    /** @return array<string, mixed>|null */
    public static function getOpen(int $contributionRecurId): ?array
    {
        if ($contributionRecurId <= 0) {
            return null;
        }
        $record = SumupRemediation::get(false)
            ->addSelect('*')
            ->addWhere('contribution_recur_id', '=', $contributionRecurId)
            ->addWhere('state', 'IN', ['customer_action_required', 'replacement_started'])
            ->addOrderBy('id', 'DESC')
            ->setLimit(1)
            ->execute()
            ->first();

        return $record ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function getBlocking(int $contributionRecurId): ?array
    {
        $record = self::getOpen($contributionRecurId);
        if (
            $record === null
            || !in_array((string) $record['reason'], ['sca_required', 'payment_method_failed'], true)
        ) {
            return null;
        }
        return $record;
    }

    public static function attachReplacementCheckout(int $remediationId, string $checkoutId): void
    {
        SumupRemediation::update(false)
            ->addWhere('id', '=', $remediationId)
            ->setValues([
                'replacement_checkout_id' => $checkoutId,
                'state' => 'replacement_started',
                'modified_date' => date('Y-m-d H:i:s'),
            ])
            ->execute();
    }

    public static function resolve(int $remediationId, int $paymentTokenId): void
    {
        SumupRemediation::update(false)
            ->addWhere('id', '=', $remediationId)
            ->setValues([
                'replacement_payment_token_id' => $paymentTokenId,
                'state' => 'resolved',
                'modified_date' => date('Y-m-d H:i:s'),
                'resolved_date' => date('Y-m-d H:i:s'),
            ])
            ->execute();
    }

    public static function resetReplacement(int $remediationId): void
    {
        SumupRemediation::update(false)
            ->addWhere('id', '=', $remediationId)
            ->setValues([
                'replacement_checkout_id' => null,
                'state' => 'customer_action_required',
                'modified_date' => date('Y-m-d H:i:s'),
            ])
            ->execute();
    }
}
