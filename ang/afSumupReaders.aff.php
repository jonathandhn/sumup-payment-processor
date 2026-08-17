<?php

declare(strict_types=1);

use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
    'type' => 'form',
    'title' => E::ts('SumUp card readers'),
    'requires' => ['afSumUp'],
    'server_route' => 'civicrm/admin/sumup-readers',
    'placement' => [],
    'icon' => 'fa-tablet',
    'is_public' => false,
    'permission' => ['administer CiviCRM system', 'access CiviContribute'],
    'permission_operator' => 'OR',
    'submit_enabled' => false,
    'create_submission' => false,
];
