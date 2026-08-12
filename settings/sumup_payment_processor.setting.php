<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    'sumup_checkout_mode' => [
        'name' => 'sumup_checkout_mode',
        'type' => 'String',
        'html_type' => 'select',
        'default' => 'widget',
        'is_domain' => 1,
        'is_contact' => 0,
        'title' => E::ts('Checkout mode'),
        'description' => E::ts(
            'Choose the SumUp Card Widget, Apple Pay and Google Pay wallets, both, or a redirect to SumUp Hosted Checkout.'
        ),
        'pseudoconstant' => [
            'callback' => 'CRM_SumupPaymentProcessor_CheckoutMode::getOptions',
        ],
        'settings_pages' => [
            'sumup' => [
                'weight' => 10,
            ],
        ],
    ],
    'sumup_merchant_country_code' => [
        'name' => 'sumup_merchant_country_code',
        'type' => 'String',
        'html_type' => 'text',
        'html_attributes' => [
            'maxlength' => 2,
            'size' => 2,
        ],
        'default' => 'FR',
        'is_domain' => 1,
        'is_contact' => 0,
        'title' => E::ts('Merchant country code'),
        'description' => E::ts(
            'Two-letter ISO code for the merchant principal place of business, required by Apple Pay and Google Pay.'
        ),
        'settings_pages' => [
            'sumup' => [
                'weight' => 20,
            ],
        ],
    ],
];
