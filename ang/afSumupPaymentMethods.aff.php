<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    'type' => 'form',
    'title' => E::ts('My recurring payments'),
    'requires' => ['afSumUp'],
    'server_route' => 'civicrm/sumup/payment-methods',
    'placement' => ['contact_summary_tab'],
    'placement_weight' => 75,
    'icon' => 'fa-credit-card',
    'is_public' => true,
    'permission' => ['make online contributions'],
    'submit_enabled' => false,
    'create_submission' => false,
];
