<?php

// PSP-agnostic checkout layout module.
// Lives in sumup-payment-processor during UI stabilisation;
// ready to extract to its own extension when a second PSP needs it.

return [
    'js' => [
        'ang/afCheckoutLayout.js',
        'ang/afCheckoutLayout/*.js',
    ],
    'partials' => ['ang/afCheckoutLayout'],
    'requires' => [],
    'l10n' => TRUE,
    'exports' => [
        'crm-checkout-summary' => 'E',
        'crm-payment-orchestrator' => 'E',
        'crm-payment-method' => 'E',
        'crm-offline-payment' => 'E',
    ],
];
