<?php

return [
  'js' => [
    'ang/afCheckoutLayout.js',
    'ang/afCheckoutLayout/*.js',
  ],
  'partials' => ['ang/afCheckoutLayout'],
  'requires' => [],
  'l10n' => TRUE,
  // PSP-agnostic; any PSP extension can declare a dependency on this module.
  'exports' => ['crm-checkout-summary' => 'E'],
];
