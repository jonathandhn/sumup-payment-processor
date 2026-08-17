<?php

declare(strict_types=1);

namespace Civi\SumupPaymentProcessor\ActionSchedule;

use Civi\ActionSchedule\MappingBase;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\Service\Spec\RequestSpec;
use CRM_Core_DAO_ActionSchedule;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;
use CRM_Utils_Array;
use CRM_Utils_SQL_Select;
use CRM_Utils_Type;

/**
 * Expose SumUp recurring contributions to CiviCRM Scheduled Reminders.
 *
 * @service sumup_payment_processor.action_schedule.contribution_recur
 */
final class ContributionRecurMapping extends MappingBase
{
    private const REMEDIATION_DATE = 'sumup_remediation_created_date';

    public function getName(): string
    {
        return 'sumup_contribution_recur';
    }

    public function getEntityName(): string
    {
        return 'ContributionRecur';
    }

    public function getLabel(): string
    {
        return E::ts('SumUp recurring contribution');
    }

    public function modifyApiSpec(RequestSpec $spec): void
    {
        $spec->getFieldByName('entity_value')
            ->setLabel(E::ts('SumUp payment processor'))
            ->setRequired(false);
        $spec->getFieldByName('entity_status')
            ->setLabel(E::ts('Recurring contribution status'));
    }

    /** @return array<int, string> */
    public function getValueLabels(): array
    {
        $processors = PaymentProcessor::get(false)
            ->addSelect('id', 'title', 'name', 'is_test')
            ->addWhere('class_name', '=', 'Payment_Sumup')
            ->addOrderBy('is_test', 'ASC')
            ->addOrderBy('title', 'ASC')
            ->execute();
        $labels = [];
        foreach ($processors as $processor) {
            $label = trim((string) ($processor['title'] ?? $processor['name'] ?? 'SumUp'));
            if (!empty($processor['is_test'])) {
                $label .= ' — ' . E::ts('Test / Sandbox');
            }
            $labels[(int) $processor['id']] = $label;
        }
        return $labels;
    }

    /**
     * @param array<int|string>|null $entityValue
     * @return array<int|string, string>
     */
    public function getStatusLabels(?array $entityValue): array
    {
        return \CRM_Contribute_BAO_ContributionRecur::buildOptions(
            'contribution_status_id',
            'get',
            []
        );
    }

    /**
     * @param array<int|string>|null $entityValue
     * @return array<string, string>
     */
    public function getDateFields(?array $entityValue = null): array
    {
        return [
            'start_date' => E::ts('Start date'),
            'create_date' => E::ts('Creation date'),
            'next_sched_contribution_date' => E::ts('Next scheduled payment'),
            'failure_retry_date' => E::ts('Failure retry date'),
            self::REMEDIATION_DATE => E::ts('Customer action required since'),
            'cancel_date' => E::ts('Cancellation date'),
            'end_date' => E::ts('End date'),
        ];
    }

    /**
     * @param CRM_Core_DAO_ActionSchedule $schedule
     * @param string $phase
     * @param array<string, mixed> $defaultParams
     */
    public function createQuery($schedule, $phase, $defaultParams): CRM_Utils_SQL_Select
    {
        $selectedProcessors = (array) CRM_Utils_Array::explodePadded($schedule->entity_value);
        $selectedStatuses = (array) CRM_Utils_Array::explodePadded($schedule->entity_status);

        $query = CRM_Utils_SQL_Select::from('civicrm_contribution_recur e')->param($defaultParams);
        $query['casAddlCheckFrom'] = 'civicrm_contribution_recur e';
        $query['casContactIdField'] = 'e.contact_id';
        $query['casEntityIdField'] = 'e.id';
        $query['casContactTableAlias'] = null;
        $query->join(
            'sumup_processor',
            "INNER JOIN civicrm_payment_processor sumup_processor
              ON sumup_processor.id = e.payment_processor_id
              AND (sumup_processor.class_name LIKE 'Payment_Sum%' OR sumup_processor.payment_processor_type_id IN (
                SELECT ppt.id FROM civicrm_payment_processor_type ppt WHERE ppt.class_name LIKE 'Payment_Sum%'
              ))"
        );

        if (empty($schedule->absolute_date)) {
            $dateField = (string) ($schedule->start_action_date ?? '');
            if (!array_key_exists($dateField, $this->getDateFields())) {
                throw new \CRM_Core_Exception(E::ts('Invalid SumUp recurring reminder date field.'));
            }
            if ($dateField === self::REMEDIATION_DATE) {
                $query->join(
                    'sumup_remediation',
                    "INNER JOIN civicrm_sumup_remediation sumup_remediation
                      ON sumup_remediation.id = (
                        SELECT MAX(sumup_remediation_latest.id)
                        FROM civicrm_sumup_remediation sumup_remediation_latest
                        WHERE sumup_remediation_latest.contribution_recur_id = e.id
                          AND sumup_remediation_latest.state IN
                            ('customer_action_required', 'replacement_started')
                          AND sumup_remediation_latest.reason IN
                            ('sca_required', 'payment_method_failed')
                      )
                      AND sumup_remediation.state IN ('customer_action_required', 'replacement_started')
                      AND sumup_remediation.reason IN ('sca_required', 'payment_method_failed')"
                );
                $query['casDateField'] = 'sumup_remediation.created_date';
            } else {
                $query['casDateField'] = 'e.' . $dateField;
            }
        } else {
            $query['casDateField'] = "'" . CRM_Utils_Type::escape($schedule->absolute_date, 'String') . "'";
        }

        if ($selectedProcessors !== []) {
            $query->where('e.payment_processor_id IN (#sumupProcessorIds)')
                ->param('sumupProcessorIds', $selectedProcessors);
        }
        if ($selectedStatuses !== []) {
            $query->where('e.contribution_status_id IN (#sumupRecurStatusIds)')
                ->param('sumupRecurStatusIds', $selectedStatuses);
        }

        return $query;
    }

    /** @param CRM_Core_DAO_ActionSchedule $schedule */
    public function resetOnTriggerDateChange($schedule): bool
    {
        return empty($schedule->absolute_date);
    }
}
