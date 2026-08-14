<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    [
        'name' => 'Job_SumupRecurringCards',
        'entity' => 'Job',
        'cleanup' => 'always',
        'update' => 'unmodified',
        'params' => [
            'version' => 4,
            'values' => [
                'name' => 'SumupRecurringCards',
                'description' => E::ts('SumUp — collect due recurring card payments'),
                'api_entity' => 'Job',
                'api_action' => 'sumuprecurringcards',
                'run_frequency' => 'Daily',
                'parameters' => 'limit=25 stale_limit=7',
                'is_active' => true,
            ],
            'match' => ['name'],
        ],
    ],
];
