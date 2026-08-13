<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    'name' => 'SumupRemediation',
    'table' => 'civicrm_sumup_remediation',
    'class' => 'CRM_SumupPaymentProcessor_DAO_SumupRemediation',
    'getInfo' => fn() => [
        'title' => E::ts('SumUp remediation'),
        'title_plural' => E::ts('SumUp remediations'),
        'description' => E::ts('Customer action required for a SumUp recurring-card payment'),
        'log' => true,
    ],
    'getIndices' => fn() => [
        'index_sumup_remediation_recur_state' => [
            'fields' => ['contribution_recur_id' => true, 'state' => true],
        ],
        'index_sumup_remediation_contribution' => [
            'fields' => ['contribution_id' => true],
        ],
    ],
    'getFields' => fn() => [
        'id' => [
            'title' => E::ts('ID'),
            'sql_type' => 'int unsigned',
            'input_type' => 'Number',
            'primary_key' => true,
            'auto_increment' => true,
        ],
        'contribution_recur_id' => [
            'title' => E::ts('Recurring contribution ID'),
            'sql_type' => 'int unsigned',
            'input_type' => 'EntityRef',
            'required' => true,
            'entity_reference' => [
                'entity' => 'ContributionRecur',
                'key' => 'id',
                'on_delete' => 'CASCADE',
            ],
        ],
        'contribution_id' => [
            'title' => E::ts('Contribution ID'),
            'sql_type' => 'int unsigned',
            'input_type' => 'EntityRef',
            'required' => true,
            'entity_reference' => [
                'entity' => 'Contribution',
                'key' => 'id',
                'on_delete' => 'CASCADE',
            ],
        ],
        'payment_processor_id' => [
            'title' => E::ts('Payment processor ID'),
            'sql_type' => 'int unsigned',
            'input_type' => 'EntityRef',
            'required' => true,
            'entity_reference' => [
                'entity' => 'PaymentProcessor',
                'key' => 'id',
                'on_delete' => 'CASCADE',
            ],
        ],
        'checkout_id' => [
            'title' => E::ts('Failed SumUp checkout ID'),
            'sql_type' => 'varchar(100)',
            'input_type' => 'Text',
        ],
        'payment_token_id' => [
            'title' => E::ts('Failed payment token ID'),
            'sql_type' => 'int unsigned',
            'input_type' => 'EntityRef',
            'entity_reference' => [
                'entity' => 'PaymentToken',
                'key' => 'id',
                'on_delete' => 'SET NULL',
            ],
        ],
        'replacement_checkout_id' => [
            'title' => E::ts('Replacement checkout ID'),
            'sql_type' => 'varchar(100)',
            'input_type' => 'Text',
        ],
        'replacement_payment_token_id' => [
            'title' => E::ts('Replacement payment token ID'),
            'sql_type' => 'int unsigned',
            'input_type' => 'EntityRef',
            'entity_reference' => [
                'entity' => 'PaymentToken',
                'key' => 'id',
                'on_delete' => 'SET NULL',
            ],
        ],
        'reason' => [
            'title' => E::ts('Reason'),
            'sql_type' => 'varchar(32)',
            'input_type' => 'Text',
            'required' => true,
        ],
        'provider_error_code' => [
            'title' => E::ts('Provider error code'),
            'sql_type' => 'varchar(100)',
            'input_type' => 'Text',
        ],
        'state' => [
            'title' => E::ts('State'),
            'sql_type' => 'varchar(32)',
            'input_type' => 'Text',
            'required' => true,
            'default' => 'customer_action_required',
        ],
        'created_date' => [
            'title' => E::ts('Created date'),
            'sql_type' => 'timestamp',
            'input_type' => null,
            'default' => 'CURRENT_TIMESTAMP',
        ],
        'modified_date' => [
            'title' => E::ts('Modified date'),
            'sql_type' => 'datetime',
            'input_type' => null,
        ],
        'resolved_date' => [
            'title' => E::ts('Resolved date'),
            'sql_type' => 'datetime',
            'input_type' => null,
        ],
    ],
];
