<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    [
        'name' => 'Job_SumupRecurringCards',
        'entity' => 'Job',
        'cleanup' => 'always',
        'update' => 'always',
        'params' => [
            'version' => 3,
            'name' => 'SumupRecurringCards',
            'description' => E::ts('SumUp — collect due recurring card payments'),
            'api_entity' => 'Job',
            'api_action' => 'sumuprecurringcards',
            'run_frequency' => 'Daily',
            'parameters' => "version=3\nlimit=25\nstale_limit=7",
            'is_active' => 1,
        ],
    ],
];
