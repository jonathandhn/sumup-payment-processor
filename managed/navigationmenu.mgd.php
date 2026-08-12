<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    [
        'name' => 'Navigation_SumUp_Settings',
        'entity' => 'Navigation',
        'cleanup' => 'always',
        'update' => 'unmodified',
        'params' => [
            'version' => 4,
            'values' => [
                'label' => E::ts('SumUp settings'),
                'name' => 'SumUp settings',
                'url' => 'civicrm/admin/setting/sumup?reset=1',
                'permission' => ['administer CiviCRM system'],
                'permission_operator' => 'OR',
                'parent_id.name' => 'CiviContribute',
                'is_active' => true,
                'has_separator' => false,
                'weight' => 99,
            ],
            'match' => ['name'],
        ],
    ],
];
