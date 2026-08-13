<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    [
        'name' => 'Job_SumupRecurringCards',
        'entity' => 'Job',
        'action' => 'create',
        'params' => [
            'version' => 3,
            'name' => 'SumupRecurringCards',
            'description' => E::ts('SumUp — collect due recurring card payments'),
            'api_entity' => 'Job',
            'api_action' => 'sumuprecurringcards',
            'run_frequency' => 'Daily',
            'parameters' => 'limit=25 stale_limit=7',
            'is_active' => 1,
        ],
    ],
];
