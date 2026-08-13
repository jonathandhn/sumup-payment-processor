<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupRecurringCard;

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentToken;
use Civi\Payment\System;
use CRM_Core_Exception;
use CRM_Core_Lock;
use CRM_Core_Payment_Sumup;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;
use DateTimeImmutable;
use Throwable;

/**
 * Charge due CiviCRM schedules with a saved SumUp card.
 *
 * @method $this setRecurId(?int $recurId)
 * @method $this setLimit(int $limit)
 * @method $this setStaleLimit(int $staleLimit)
 * @method $this setDryRun(bool $dryRun)
 */
final class Run extends AbstractAction
{
    protected ?int $recurId = null;

    protected int $limit = 25;

    protected int $staleLimit = 7;

    protected bool $dryRun = false;

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        $lock = new CRM_Core_Lock('civicrm.job.SumupRecurringCard');
        if (!$lock->acquire()) {
            $result[] = ['message' => E::ts('A SumUp recurring-card job is already running.')];
            return;
        }

        try {
            $result[] = $this->runSchedules();
        } finally {
            $lock->release();
        }
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore

    /** @return array<string, bool|int|list<string>> */
    private function runSchedules(): array
    {
        $limit = max(1, min($this->limit, 100));
        $staleLimit = max(0, min($this->staleLimit, 365));
        $now = new DateTimeImmutable('now');
        $todayEnd = $now->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        $staleDate = $now->modify('-' . $staleLimit . ' days')->setTime(0, 0)->format('Y-m-d H:i:s');
        $outcome = [
            'dry_run' => $this->dryRun,
            'eligible' => 0,
            'charged' => 0,
            'failed' => 0,
            'pending' => 0,
            'requires_customer' => 0,
            'skipped_stale' => 0,
            'skipped_retry_delay' => 0,
            'errors' => [],
        ];

        $processors = PaymentProcessor::get(false)
            ->addSelect('id', 'class_name', 'is_test', 'name')
            ->addWhere('class_name', '=', 'Payment_Sumup')
            ->addWhere('is_active', '=', true)
            ->execute();
        $processorById = [];
        foreach ($processors as $processor) {
            $processorById[(int) $processor['id']] = $processor;
        }
        if ($processorById === []) {
            $outcome['errors'][] = E::ts('No active SumUp payment processor is configured.');
            return $outcome;
        }

        $query = ContributionRecur::get(false)
            ->addSelect(
                'id',
                'contact_id',
                'amount',
                'currency',
                'financial_type_id',
                'payment_instrument_id',
                'payment_processor_id',
                'payment_token_id',
                'next_sched_contribution_date',
                'frequency_interval',
                'frequency_unit',
                'installments',
                'failure_count',
                'failure_retry_date',
                'is_test',
                'is_email_receipt'
            )
            ->addWhere('next_sched_contribution_date', '<=', $todayEnd)
            ->addWhere('payment_processor_id', 'IN', array_keys($processorById))
            ->addWhere('contribution_status_id:name', '=', 'In Progress')
            ->addWhere('payment_token_id', '>', 0)
            ->addOrderBy('next_sched_contribution_date', 'ASC')
            ->setLimit($limit);
        if ($this->recurId !== null) {
            $query->addWhere('id', '=', $this->recurId);
        }

        foreach ($query->execute() as $schedule) {
            $scheduleId = (int) $schedule['id'];
            $dueDate = (string) $schedule['next_sched_contribution_date'];
            if (\CRM_SumupPaymentProcessor_RemediationStore::getBlocking($scheduleId) !== null) {
                ++$outcome['requires_customer'];
                continue;
            }
            if ($dueDate < $staleDate) {
                ++$outcome['skipped_stale'];
                $outcome['errors'][] = E::ts(
                    'Schedule %1 is overdue since %2 and was not charged.',
                    [1 => $scheduleId, 2 => $dueDate]
                );
                continue;
            }
            if (
                !empty($schedule['failure_retry_date'])
                && (string) $schedule['failure_retry_date'] > $now->format('Y-m-d H:i:s')
            ) {
                ++$outcome['skipped_retry_delay'];
                continue;
            }

            try {
                self::assertPaymentToken($schedule);
                ++$outcome['eligible'];
                if ($this->dryRun) {
                    continue;
                }

                $processorId = (int) $schedule['payment_processor_id'];
                if ((bool) $processorById[$processorId]['is_test'] !== (bool) $schedule['is_test']) {
                    throw new CRM_Core_Exception(E::ts(
                        'Schedule %1 test mode does not match its SumUp processor.',
                        [1 => $scheduleId]
                    ));
                }
                $provider = System::singleton()->getById($processorId);
                if (!$provider instanceof CRM_Core_Payment_Sumup) {
                    throw new CRM_Core_Exception(E::ts(
                        'Unable to load the SumUp processor for schedule %1.',
                        [1 => $scheduleId]
                    ));
                }

                $contribution = self::findOrCreateContribution($schedule, $dueDate);
                $result = $provider->chargeRecurringContribution(
                    (int) $contribution['id'],
                    $scheduleId,
                    (int) $schedule['payment_token_id'],
                    self::occurrenceKey($dueDate)
                );
                if ($result['status'] === 'PAID') {
                    self::advanceSchedule($schedule, $dueDate);
                    ++$outcome['charged'];
                } elseif ($result['status'] === 'CUSTOMER_ACTION_REQUIRED') {
                    self::recordCustomerActionRequired($schedule);
                    ++$outcome['requires_customer'];
                } elseif (in_array($result['status'], ['FAILED', 'EXPIRED'], true)) {
                    self::recordCustomerActionRequired($schedule);
                    ++$outcome['requires_customer'];
                } else {
                    ++$outcome['pending'];
                }
            } catch (Throwable $exception) {
                \Civi::log()->warning(sprintf(
                    'SumUp recurring-card job failed for schedule %d: %s',
                    $scheduleId,
                    $exception->getMessage()
                ));
                $outcome['errors'][] = E::ts(
                    'Schedule %1: %2',
                    [1 => $scheduleId, 2 => $exception->getMessage()]
                );
            }
        }

        return $outcome;
    }

    /** @param array<string, mixed> $schedule */
    private static function assertPaymentToken(array $schedule): void
    {
        $token = PaymentToken::get(false)
            ->addSelect('id', 'contact_id', 'payment_processor_id', 'token')
            ->addWhere('id', '=', (int) $schedule['payment_token_id'])
            ->execute()
            ->single();
        if (
            (int) $token['contact_id'] !== (int) $schedule['contact_id']
            || (int) $token['payment_processor_id'] !== (int) $schedule['payment_processor_id']
            || !preg_match('/^[A-Za-z0-9_-]{8,255}$/', (string) $token['token'])
        ) {
            throw new CRM_Core_Exception(E::ts(
                'Schedule %1 has no valid SumUp card token.',
                [1 => (int) $schedule['id']]
            ));
        }
    }

    /**
     * @param array<string, mixed> $schedule
     * @return array<string, mixed>
     */
    private static function findOrCreateContribution(array $schedule, string $dueDate): array
    {
        $invoiceId = self::invoiceId((int) $schedule['id'], $dueDate);
        $existing = Contribution::get(false)
            ->addSelect('id', 'trxn_id')
            ->addWhere('contribution_recur_id', '=', (int) $schedule['id'])
            ->addWhere('invoice_id', '=', $invoiceId)
            ->setLimit(1)
            ->execute()
            ->first();
        if ($existing) {
            return $existing;
        }

        return Contribution::create(false)
            ->setValues([
                'contact_id' => (int) $schedule['contact_id'],
                'receive_date' => $dueDate,
                'total_amount' => (float) $schedule['amount'],
                'currency' => (string) $schedule['currency'],
                'financial_type_id' => (int) $schedule['financial_type_id'],
                'payment_instrument_id' => (int) $schedule['payment_instrument_id'],
                'payment_processor_id' => (int) $schedule['payment_processor_id'],
                'contribution_recur_id' => (int) $schedule['id'],
                'contribution_status_id:name' => 'Pending',
                'is_test' => !empty($schedule['is_test']),
                'is_email_receipt' => !empty($schedule['is_email_receipt']),
                'invoice_id' => $invoiceId,
                'source' => E::ts('SumUp recurring card charge (schedule %1)', [1 => (int) $schedule['id']]),
            ])
            ->execute()
            ->single();
    }

    /** @param array<string, mixed> $schedule */
    private static function advanceSchedule(array $schedule, string $dueDate): void
    {
        $completedCount = Contribution::get(false)
            ->addSelect('id')
            ->addWhere('contribution_recur_id', '=', (int) $schedule['id'])
            ->addWhere('contribution_status_id:name', '=', 'Completed')
            ->execute()
            ->count();
        $installments = (int) ($schedule['installments'] ?? 0);
        $values = [
            'failure_count' => 0,
            'failure_retry_date' => null,
        ];
        if ($installments > 0 && $completedCount >= $installments) {
            $values['contribution_status_id:name'] = 'Completed';
            $values['next_sched_contribution_date'] = null;
        } else {
            $values['next_sched_contribution_date'] = self::nextDueDate(
                $dueDate,
                (int) $schedule['frequency_interval'],
                (string) $schedule['frequency_unit']
            );
        }
        ContributionRecur::update(false)
            ->addWhere('id', '=', (int) $schedule['id'])
            ->setValues($values)
            ->execute();
    }

    private static function nextDueDate(string $dueDate, int $interval, string $unit): string
    {
        $allowedUnits = ['day', 'week', 'month', 'year'];
        if ($interval <= 0 || !in_array($unit, $allowedUnits, true)) {
            throw new CRM_Core_Exception(E::ts('The recurring schedule frequency is invalid.'));
        }
        $current = new DateTimeImmutable($dueDate);
        if (in_array($unit, ['day', 'week'], true)) {
            return $current->modify(sprintf('+%d %s', $interval, $unit))->format('Y-m-d H:i:s');
        }

        $months = $unit === 'year' ? $interval * 12 : $interval;
        $targetMonth = $current->modify('first day of this month')->modify('+' . $months . ' months');
        $targetDay = min((int) $current->format('d'), (int) $targetMonth->format('t'));
        return $targetMonth
            ->setDate((int) $targetMonth->format('Y'), (int) $targetMonth->format('m'), $targetDay)
            ->setTime(
                (int) $current->format('H'),
                (int) $current->format('i'),
                (int) $current->format('s')
            )
            ->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $schedule */
    private static function recordCustomerActionRequired(array $schedule): void
    {
        $failureCount = (int) ($schedule['failure_count'] ?? 0) + 1;
        ContributionRecur::update(false)
            ->addWhere('id', '=', (int) $schedule['id'])
            ->setValues([
                'failure_count' => $failureCount,
                'failure_retry_date' => null,
            ])
            ->execute();
    }

    private static function occurrenceKey(string $dueDate): string
    {
        return preg_replace('/[^0-9]/', '', substr($dueDate, 0, 10)) ?? '';
    }

    private static function invoiceId(int $scheduleId, string $dueDate): string
    {
        return sprintf('sumup-r-%d-%s', $scheduleId, self::occurrenceKey($dueDate));
    }
}
