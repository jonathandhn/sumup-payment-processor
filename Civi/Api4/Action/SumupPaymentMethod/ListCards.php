<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupPaymentMethod;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentProcessor;
use Civi\Payment\System;
use CRM_Core_Payment_Sumup;

final class ListCards extends ActionBase
{
    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $contactId = $this->authorisedContactId();
        $processors = PaymentProcessor::get(false)
            ->addSelect('id', 'title', 'is_test')
            ->addWhere('class_name', '=', 'Payment_Sumup')
            ->addWhere('is_active', '=', true)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute();
        foreach ($processors as $processorRecord) {
            $processor = System::singleton()->getById((int) $processorRecord['id']);
            if (!$processor instanceof CRM_Core_Payment_Sumup) {
                continue;
            }
            $schedulesByToken = $this->activeSchedulesByToken(
                $contactId,
                (int) $processorRecord['id']
            );
            foreach ($processor->getSavedCardsForContact($contactId) as $card) {
                $paymentTokenId = (int) $card['payment_token_id'];
                $recurringPayments = $schedulesByToken[$paymentTokenId] ?? [];
                $result[] = $card + [
                    'payment_processor_id' => (int) $processorRecord['id'],
                    'payment_processor_title' => (string) $processorRecord['title'],
                    'is_test' => (bool) $processorRecord['is_test'],
                    'recurring_payments' => $recurringPayments,
                    'recurring_payment_count' => count($recurringPayments),
                    'can_deactivate' => $recurringPayments === [],
                ];
            }
        }
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore

    /**
     * @return array<int, list<array<string, int|string>>>
     */
    private function activeSchedulesByToken(int $contactId, int $processorId): array
    {
        $schedules = ContributionRecur::get(false)
            ->addSelect(
                'id',
                'payment_token_id',
                'amount',
                'currency',
                'frequency_interval',
                'frequency_unit:label'
            )
            ->addWhere('contact_id', '=', $contactId)
            ->addWhere('payment_processor_id', '=', $processorId)
            ->addWhere('contribution_status_id:name', '=', 'In Progress')
            ->addWhere('payment_token_id', 'IS NOT NULL')
            ->addWhere('is_test', 'IN', [true, false])
            ->execute();
        $byToken = [];
        foreach ($schedules as $schedule) {
            $paymentTokenId = (int) $schedule['payment_token_id'];
            $byToken[$paymentTokenId][] = [
                'recur_id' => (int) $schedule['id'],
                'amount_display' => \CRM_Utils_Money::format(
                    (float) $schedule['amount'],
                    (string) $schedule['currency']
                ),
                'frequency_interval' => (int) $schedule['frequency_interval'],
                'frequency_unit_label' => (string) $schedule['frequency_unit:label'],
            ];
        }
        return $byToken;
    }
}
