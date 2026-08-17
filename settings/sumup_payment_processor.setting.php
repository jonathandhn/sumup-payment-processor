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
            'Choose the SumUp Card Widget, Apple Pay and Google Pay wallets, both, '
            . 'or a redirect to SumUp Hosted Checkout.'
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
    'sumup_affiliate_app_id' => [
        'name' => 'sumup_affiliate_app_id',
        'type' => 'String',
        'html_type' => 'text',
        'html_attributes' => [
            'maxlength' => 100,
            'size' => 40,
        ],
        'default' => '',
        'is_domain' => 1,
        'is_contact' => 0,
        'title' => E::ts('SumUp Affiliate application ID'),
        'description' => E::ts(
            'Application identifier registered with the SumUp Affiliate Key for Solo Cloud API payments.'
        ),
        'settings_pages' => [
            'sumup' => [
                'weight' => 30,
            ],
        ],
    ],
    'sumup_affiliate_key' => [
        'name' => 'sumup_affiliate_key',
        'type' => 'String',
        'html_type' => 'text',
        'html_attributes' => [
            'maxlength' => 255,
            'size' => 60,
            'autocomplete' => 'off',
        ],
        'default' => '',
        'is_domain' => 1,
        'is_contact' => 0,
        'title' => E::ts('SumUp Affiliate Key'),
        'description' => E::ts(
            'Required by SumUp for card-present Solo Cloud API checkout requests. '
            . 'It is not an authorization credential.'
        ),
        'settings_pages' => [
            'sumup' => [
                'weight' => 40,
            ],
        ],
    ],
    'sumup_single_active_recurring_plan' => [
        'name' => 'sumup_single_active_recurring_plan',
        'type' => 'Boolean',
        'html_type' => 'checkbox',
        'default' => false,
        'is_domain' => 1,
        'is_contact' => 0,
        'title' => E::ts('Allow only one active recurring plan per contact'),
        'description' => E::ts(
            'When enabled, a contact who already has an active SumUp recurring contribution in the same '
            . 'test or live environment is directed to manage that plan instead of creating another one.'
        ),
        'settings_pages' => [
            'sumup' => [
                'weight' => 50,
            ],
        ],
    ],
    'sumup_apple_pay_domain_association' => [
        'name' => 'sumup_apple_pay_domain_association',
        'type' => 'String',
        'html_type' => 'textarea',
        'html_attributes' => [
            'rows' => 6,
            'cols' => 60,
        ],
        'default' => '',
        'is_domain' => 1,
        'is_contact' => 0,
        'title' => E::ts('Apple Pay domain association text'),
        'description' => E::ts(
            'Paste the contents of the Apple Developer Merchant ID Domain Association file provided by SumUp. '
            . 'CiviCRM will automatically serve it at /.well-known/apple-developer-merchantid-domain-association.'
        ),
        'settings_pages' => [
            'sumup' => [
                'weight' => 60,
            ],
        ],
    ],
    'sumup_qr_allow_send_email' => [
        'name' => 'sumup_qr_allow_send_email',
        'type' => 'Boolean',
        'html_type' => 'checkbox',
        'default' => true,
        'is_domain' => 1,
        'is_contact' => 0,
        'title' => E::ts('Allow sending payment link by Email from QR views'),
        'description' => E::ts(
            'When enabled, users and kiosk operators can send the payment link by Email from QR payment screens.'
        ),
        'settings_pages' => [
            'sumup' => [
                'weight' => 70,
            ],
        ],
    ],
    'sumup_qr_allow_send_sms' => [
        'name' => 'sumup_qr_allow_send_sms',
        'type' => 'Boolean',
        'html_type' => 'checkbox',
        'default' => true,
        'is_domain' => 1,
        'is_contact' => 0,
        'title' => E::ts('Allow sending payment link by SMS from QR views'),
        'description' => E::ts(
            'When enabled, users and kiosk operators can send the payment link by SMS from QR payment screens.'
        ),
        'settings_pages' => [
            'sumup' => [
                'weight' => 80,
            ],
        ],
    ],
    'sumup_qr_sms_provider_id' => [
        'name' => 'sumup_qr_sms_provider_id',
        'type' => 'Integer',
        'html_type' => 'select',
        'default' => 0,
        'is_domain' => 1,
        'is_contact' => 0,
        'title' => E::ts('SMS Provider for payment links'),
        'description' => E::ts(
            'Choose the active CiviCRM SMS Provider / Gateway used to send SMS payment links.'
        ),
        'pseudoconstant' => [
            'callback' => 'CRM_SumupPaymentProcessor_SmsHelper::getProviderOptions',
        ],
        'settings_pages' => [
            'sumup' => [
                'weight' => 90,
            ],
        ],
    ],
];
