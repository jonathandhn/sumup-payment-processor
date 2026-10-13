<?php

declare(strict_types = 1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
  'name' => 'SumupCheckout',
  'table' => 'civicrm_sumup_checkout',
  'class' => 'CRM_SumupPaymentProcessor_DAO_SumupCheckout',
  'getInfo' => fn() => [
    'title' => E::ts('SumUp checkout'),
    'title_plural' => E::ts('SumUp checkouts'),
    'description' => E::ts('SumUp checkout attempts, independently from completed CiviCRM payments'),
    'log' => TRUE,
  ],
  'getIndices' => fn() => [
    'unique_sumup_checkout_id' => [
      'fields' => ['checkout_id' => TRUE],
      'unique' => TRUE,
    ],
    'unique_sumup_checkout_reference' => [
      'fields' => ['checkout_reference' => TRUE],
      'unique' => TRUE,
    ],
    'index_sumup_contribution' => [
      'fields' => ['contribution_id' => TRUE],
    ],
    'index_sumup_processor_state' => [
      'fields' => [
        'payment_processor_id' => TRUE,
        'state' => TRUE,
      ],
    ],
    'unique_sumup_setup_charge' => [
      'fields' => ['setup_checkout_id' => TRUE],
      'unique' => TRUE,
    ],
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'checkout_id' => [
      'title' => E::ts('SumUp checkout ID'),
      'sql_type' => 'varchar(100)',
      'input_type' => 'Text',
      'required' => TRUE,
    ],
    'checkout_reference' => [
      'title' => E::ts('SumUp checkout reference'),
      'sql_type' => 'varchar(100)',
      'input_type' => 'Text',
      'required' => TRUE,
    ],
    'contribution_id' => [
      'title' => E::ts('Contribution ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'entity_reference' => [
        'entity' => 'Contribution',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'payment_processor_id' => [
      'title' => E::ts('Payment processor ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'entity_reference' => [
        'entity' => 'PaymentProcessor',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'state' => [
      'title' => E::ts('Last verified SumUp state'),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'required' => TRUE,
      'default' => 'PENDING',
    ],
    'amount' => [
      'title' => E::ts('Checkout amount'),
      'sql_type' => 'decimal(20,2)',
      'input_type' => 'Money',
      'required' => TRUE,
    ],
    'currency' => [
      'title' => E::ts('Checkout currency'),
      'sql_type' => 'char(3)',
      'input_type' => 'Text',
      'required' => TRUE,
    ],
    'checkout_mode' => [
      'title' => E::ts('Checkout mode'),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'required' => TRUE,
      'default' => 'widget',
    ],
    'transaction_id' => [
      'title' => E::ts('SumUp transaction ID'),
      'sql_type' => 'varchar(100)',
      'input_type' => 'Text',
    ],
    'reader_id' => [
      'title' => E::ts('SumUp reader ID'),
      'sql_type' => 'varchar(100)',
      'input_type' => 'Text',
    ],
    'purpose' => [
      'title' => E::ts('Checkout purpose'),
      'sql_type' => 'varchar(32)',
      'input_type' => 'Text',
      'required' => TRUE,
      'default' => 'PAYMENT',
    ],
    'customer_id' => [
      'title' => E::ts('SumUp customer ID'),
      'sql_type' => 'varchar(100)',
      'input_type' => 'Text',
    ],
    'payment_token_id' => [
      'title' => E::ts('CiviCRM payment token ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'entity_reference' => [
        'entity' => 'PaymentToken',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'setup_checkout_id' => [
      'title' => E::ts('Originating setup checkout ID'),
      'sql_type' => 'varchar(100)',
      'input_type' => 'Text',
    ],
    'created_date' => [
      'title' => E::ts('Created date'),
      'sql_type' => 'timestamp',
      'input_type' => NULL,
      'default' => 'CURRENT_TIMESTAMP',
    ],
    'modified_date' => [
      'title' => E::ts('Modified date'),
      'sql_type' => 'datetime',
      'input_type' => NULL,
    ],
    'verified_date' => [
      'title' => E::ts('Last verification date'),
      'sql_type' => 'datetime',
      'input_type' => NULL,
    ],
  ],
];
