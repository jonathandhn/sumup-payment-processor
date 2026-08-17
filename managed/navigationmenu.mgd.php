<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    [
        'name' => 'Navigation_SumUp_Group',
        'entity' => 'Navigation',
        'cleanup' => 'always',
        'update' => 'unmodified',
        'params' => [
            'version' => 4,
            'values' => [
                'label' => E::ts('SumUp'),
                'name' => 'SumUp',
                'url' => null,
                'permission' => ['administer CiviCRM system', 'access CiviContribute'],
                'permission_operator' => 'OR',
                'parent_id.name' => 'CiviContribute',
                'is_active' => true,
                'has_separator' => false,
                'weight' => 98,
            ],
            'match' => ['name'],
        ],
    ],
    [
        'name' => 'Navigation_SumUp_Settings',
        'entity' => 'Navigation',
        'cleanup' => 'always',
        'update' => 'unmodified',
        'params' => [
            'version' => 4,
            'values' => [
                'label' => E::ts('Settings'),
                'name' => 'SumUp settings',
                'url' => 'civicrm/admin/setting/sumup?reset=1',
                'permission' => ['administer CiviCRM system'],
                'permission_operator' => 'OR',
                'parent_id.name' => 'SumUp',
                'is_active' => true,
                'has_separator' => false,
                'weight' => 1,
            ],
            'match' => ['name'],
        ],
    ],
    [
        'name' => 'Navigation_SumUp_Readers',
        'entity' => 'Navigation',
        'cleanup' => 'always',
        'update' => 'unmodified',
        'params' => [
            'version' => 4,
            'values' => [
                'label' => E::ts('Card readers'),
                'name' => 'SumUp card readers',
                'url' => 'civicrm/admin/sumup-readers?reset=1',
                'permission' => ['administer CiviCRM system', 'access CiviContribute'],
                'permission_operator' => 'OR',
                'parent_id.name' => 'SumUp',
                'is_active' => true,
                'has_separator' => false,
                'weight' => 2,
            ],
            'match' => ['name'],
        ],
    ],
];
