<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    [
        'name' => 'SumUp Payment Processor',
        'entity' => 'PaymentProcessorType',
        'cleanup' => 'unused',
        'update' => 'unmodified',
        'params' => [
            'version' => 4,
            'values' => [
                'name' => 'SumUp',
                'title' => E::ts('SumUp'),
                'description' => E::ts('SumUp online payments'),
                'class_name' => 'Payment_Sumup',
                'is_active' => true,
                'is_default' => false,
                'user_name_label' => E::ts('Merchant code'),
                'password_label' => E::ts('API key'),
                'signature_label' => E::ts('Public merchant key (wallets)'),
                'url_site_default' => 'https://api.sumup.com',
                'url_site_test_default' => 'https://api.sumup.com',
                'billing_mode' => 4,
                'payment_type' => 1,
                'is_recur' => false,
            ],
            'match' => ['name'],
        ],
    ],
];
