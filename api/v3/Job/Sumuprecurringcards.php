<?php

declare(strict_types=1);

use Civi\Api4\SumupRecurringCard;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * Describe the CiviCRM scheduler adapter parameters.
 *
 * @param array<string, mixed> $spec
 */
function _civicrm_api3_job_sumuprecurringcards_spec(array &$spec): void
{
    $spec['recur_id'] = [
        'title' => E::ts('Recurring contribution ID'),
        'type' => CRM_Utils_Type::T_INT,
    ];
    $spec['limit'] = [
        'title' => E::ts('Maximum schedules to process'),
        'type' => CRM_Utils_Type::T_INT,
        'api.default' => 25,
    ];
    $spec['stale_limit'] = [
        'title' => E::ts('Do not charge schedules overdue by more than this many days'),
        'type' => CRM_Utils_Type::T_INT,
        'api.default' => 7,
    ];
    $spec['dry_run'] = [
        'title' => E::ts('Report eligible schedules without creating payments'),
        'type' => CRM_Utils_Type::T_BOOLEAN,
        'api.default' => false,
    ];
}

/**
 * Invoke the API4 recurring-card engine from CiviCRM's scheduled-job runner.
 *
 * @param array<string, mixed> $params
 *
 * @return array<string, mixed>
 */
function civicrm_api3_job_sumuprecurringcards(array $params): array
{
    $action = SumupRecurringCard::run(false)
        ->setLimit(max(1, min((int) ($params['limit'] ?? 25), 100)))
        ->setStaleLimit(max(0, min((int) ($params['stale_limit'] ?? 7), 365)))
        ->setDryRun(!empty($params['dry_run']));
    if (!empty($params['recur_id'])) {
        $action->setRecurId((int) $params['recur_id']);
    }
    $outcome = $action->execute()->first() ?? [];

    // @phpstan-ignore function.notFound (CiviCRM v3 API function loaded at runtime)
    return civicrm_api3_create_success($outcome, $params, 'Job', 'sumuprecurringcards');
}
