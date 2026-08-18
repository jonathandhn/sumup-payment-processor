<?php
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

return [
  'type' => 'form',
  'title' => E::ts('Don SumUp'),
  'description' => E::ts('Formulaire de don avec paiement SumUp integre (layout Bento 2 colonnes).'),
  'icon' => 'fa-credit-card',
  'server_route' => 'civicrm/sumup-donate',
  'is_public' => TRUE,
  'permission' => [
    'make online contributions',
  ],
  'submit_enabled' => FALSE,
  'create_submission' => TRUE,
  'confirmation_type' => 'show_confirmation_message',
  'confirmation_message' => E::ts('Thank you for your support!'),
];
