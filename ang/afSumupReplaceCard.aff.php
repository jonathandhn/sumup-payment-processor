<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    'type' => 'form',
    'title' => E::ts('Replace my payment card'),
    'requires' => ['afSumUp'],
    'server_route' => 'civicrm/sumup/payment-method/replace',
    'is_public' => true,
    'permission' => ['make online contributions'],
    'submit_enabled' => false,
    'create_submission' => false,
];
