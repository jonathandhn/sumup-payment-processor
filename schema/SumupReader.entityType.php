<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    'name' => 'SumupReader',
    'table' => 'civicrm_sumup_reader',
    'class' => 'CRM_SumupPaymentProcessor_DAO_SumupReader',
    'getInfo' => fn() => [
        'title' => E::ts('SumUp reader'),
        'title_plural' => E::ts('SumUp readers'),
        'description' => E::ts('SumUp Solo readers paired with this CiviCRM site'),
        'log' => true,
    ],
    'getIndices' => fn() => [
        'unique_sumup_reader_pairing' => [
            'fields' => ['payment_processor_id' => true, 'reader_id' => true],
            'unique' => true,
        ],
        'index_sumup_reader_device' => [
            'fields' => ['payment_processor_id' => true, 'device_identifier' => true],
        ],
        'index_sumup_reader_site' => [
            'fields' => ['payment_processor_id' => true, 'site_code' => true, 'is_active' => true],
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
        'reader_id' => [
            'title' => E::ts('SumUp reader ID'),
            'sql_type' => 'varchar(100)',
            'input_type' => 'Text',
            'required' => true,
        ],
        'device_identifier' => [
            'title' => E::ts('Physical device identifier'),
            'sql_type' => 'varchar(100)',
            'input_type' => 'Text',
            'required' => true,
        ],
        'site_code' => [
            'title' => E::ts('Site code'),
            'sql_type' => 'varchar(12)',
            'input_type' => 'Text',
            'required' => true,
        ],
        'canonical_name' => [
            'title' => E::ts('Canonical terminal name'),
            'sql_type' => 'varchar(100)',
            'input_type' => 'Text',
            'required' => true,
        ],
        'model' => [
            'title' => E::ts('Reader model'),
            'sql_type' => 'varchar(32)',
            'input_type' => 'Text',
            'required' => true,
        ],
        'pairing_status' => [
            'title' => E::ts('Pairing status'),
            'sql_type' => 'varchar(32)',
            'input_type' => 'Text',
            'required' => true,
        ],
        'device_status' => [
            'title' => E::ts('Device status'),
            'sql_type' => 'varchar(32)',
            'input_type' => 'Text',
        ],
        'device_state' => [
            'title' => E::ts('Device state'),
            'sql_type' => 'varchar(32)',
            'input_type' => 'Text',
        ],
        'is_active' => [
            'title' => E::ts('Active'),
            'sql_type' => 'boolean',
            'input_type' => 'CheckBox',
            'required' => true,
            'default' => true,
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
        'last_sync_date' => [
            'title' => E::ts('Last synchronisation date'),
            'sql_type' => 'datetime',
            'input_type' => null,
        ],
    ],
];
