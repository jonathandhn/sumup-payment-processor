<?php

// afCheckoutLayout is a PSP-agnostic Angular module bundled here.
// It is NOT a CiviCRM Angular module (no .ang.php) — only an Angular concept.
// Its HTML templates live in ang/afSumUp/ so CiviCRM serves them via ~/afSumUp/.
// Its JS is bundled first so the Angular module exists when afSumUp depends on it.

return [
    'css' => [
        'ang/afSumUp/sumUp.css',
    ],
    'js' => [
        'js/checkout.js',
        'ang/afCheckoutLayout.js',
        'ang/afCheckoutLayout/*.js',
        'ang/afSumUp.js',
        'ang/afSumUp/*.js',
    ],
    'partials' => ['ang/afSumUp'],
    'settings' => [],
    'requires' => ['afCheckout'],
    'exports' => [
        'af-sum-up-embedded-checkout' => 'E',
        'af-sum-up-payment-methods' => 'E',
        'af-sum-up-readers' => 'E',
        'af-sum-up-replace-card' => 'E',
        'af-sum-up-solo-checkout' => 'E',
        'af-sum-up-qr-checkout' => 'E',
        'af-sum-up-hybrid-checkout' => 'E',
        'crm-checkout-summary' => 'E',
        'crm-payment-orchestrator' => 'E',
        'crm-payment-method' => 'E',
        'crm-offline-payment' => 'E',
    ],
];
