<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupPaymentMethod;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentToken;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

final class Get extends ActionBase
{
    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $contactId = $this->authorisedContactId();
        $processors = PaymentProcessor::get(false)
            ->addSelect('id')
            ->addWhere('class_name', '=', 'Payment_Sumup')
            ->addWhere('is_active', '=', true)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute();
        $processorIds = [];
        foreach ($processors as $processor) {
            $processorIds[] = (int) $processor['id'];
        }
        if ($processorIds === []) {
            return;
        }

        $schedules = ContributionRecur::get(false)
            ->addSelect(
                'id',
                'amount',
                'currency',
                'frequency_interval',
                'frequency_unit',
                'frequency_unit:label',
                'next_sched_contribution_date',
                'payment_processor_id',
                'payment_token_id',
                'is_test'
            )
            ->addWhere('contact_id', '=', $contactId)
            ->addWhere('payment_processor_id', 'IN', $processorIds)
            ->addWhere('contribution_status_id:name', '=', 'In Progress')
            ->addWhere('is_test', 'IN', [true, false])
            ->addOrderBy('id', 'DESC')
            ->execute();
        foreach ($schedules as $schedule) {
            $paymentToken = PaymentToken::get(false)
                ->addSelect('id', 'masked_account_number')
                ->addWhere('id', '=', (int) $schedule['payment_token_id'])
                ->addWhere('contact_id', '=', $contactId)
                ->addWhere('payment_processor_id', '=', (int) $schedule['payment_processor_id'])
                ->execute()
                ->first();
            $remediation = \CRM_SumupPaymentProcessor_RemediationStore::getOpen((int) $schedule['id']);
            $reason = (string) ($remediation['reason'] ?? '');
            $requiresCustomerAction = in_array($reason, ['sca_required', 'payment_method_failed'], true);
            $result[] = [
                'recur_id' => (int) $schedule['id'],
                'amount' => (float) $schedule['amount'],
                'amount_display' => \CRM_Utils_Money::format(
                    (float) $schedule['amount'],
                    (string) $schedule['currency']
                ),
                'currency' => (string) $schedule['currency'],
                'frequency_interval' => (int) $schedule['frequency_interval'],
                'frequency_unit' => (string) $schedule['frequency_unit'],
                'frequency_unit_label' => (string) $schedule['frequency_unit:label'],
                'next_sched_contribution_date' => (string) $schedule['next_sched_contribution_date'],
                'is_test' => (bool) $schedule['is_test'],
                'masked_account_number' => (string) ($paymentToken['masked_account_number'] ?? ''),
                'requires_customer_action' => $requiresCustomerAction,
                'remediation_reason' => $reason,
                'remediation_message' => self::remediationMessage($reason),
                'replacement_url' => \CRM_Utils_System::url(
                    'civicrm/sumup/payment-method/replace',
                    array_merge(
                        ['recur_id' => (int) $schedule['id']],
                        $this->checksumQuery()
                    ),
                    false,
                    null,
                    false,
                    true
                ),
            ];
        }
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore

    private static function remediationMessage(string $reason): string
    {
        return match ($reason) {
            'sca_required' => E::ts('Authentication is required before your next SumUp payment can be collected.'),
            'payment_method_failed' => E::ts('Your saved card can no longer be used for this payment.'),
            default => '',
        };
    }
}
