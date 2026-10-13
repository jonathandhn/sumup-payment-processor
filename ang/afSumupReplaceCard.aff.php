<?php

declare(strict_types = 1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
  'type' => 'form',
  'title' => E::ts('Replace my payment card'),
  'requires' => ['afSumUp'],
  'server_route' => 'civicrm/sumup/payment-method/replace',
  'is_public' => TRUE,
  'permission' => ['make online contributions', 'access CiviContribute'],
  'permission_operator' => 'OR',
  'submit_enabled' => FALSE,
  'create_submission' => FALSE,
];
