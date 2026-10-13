<?php

declare(strict_types = 1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
  'sumup_oauth_client_id' => [
    'name' => 'sumup_oauth_client_id',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('SumUp OAuth client ID'),
    'description' => E::ts('OAuth client ID created for this CiviCRM installation.'),
  ],
  'sumup_oauth_client_secret' => [
    'name' => 'sumup_oauth_client_secret',
    'type' => 'String',
    'html_type' => 'password',
    'default' => '',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('SumUp OAuth client secret'),
    'description' => E::ts('Server-side OAuth client secret for this CiviCRM installation.'),
  ],
  'sumup_oauth_redirect_uri' => [
    'name' => 'sumup_oauth_redirect_uri',
    'type' => 'String',
    'html_type' => 'text',
    'default' => '',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('SumUp OAuth callback URL'),
    'description' => E::ts('Exact callback URL registered with the current OAuth client.'),
  ],
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
  'sumup_single_active_recurring_plan' => [
    'name' => 'sumup_single_active_recurring_plan',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => FALSE,
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
    'default' => TRUE,
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
    'default' => TRUE,
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
