<?php

// afCheckoutLayout is a PSP-agnostic Angular module bundled here.
// It is NOT a separate CiviCRM module (no .ang.php) to avoid global
// Angular bootstrap interference. The Angular module dependency is
// declared in afSumUp.js via CRM.angRequires + explicit concat.

return [
    'css' => [
        'ang/afSumUp/sumUp.css',
    ],
    'js' => [
        // checkout.js MUST load first — it defines window.CiviSumUpCheckout.
        'js/checkout.js',
        // afCheckoutLayout files load before afSumUp so the Angular module
        // exists when afSumUp declares it as a dependency.
        'ang/afCheckoutLayout.js',
        'ang/afCheckoutLayout/*.js',
        'ang/afSumUp.js',
        'ang/afSumUp/*.js',
    ],
    'partials' => [
        'ang/afCheckoutLayout',
        'ang/afSumUp',
    ],
    'settings' => [],
    'requires' => ['afCheckout', 'afCheckoutLayout'],
    'exports' => [
        // afSumUp components
        'af-sum-up-embedded-checkout' => 'E',
        'af-sum-up-payment-methods' => 'E',
        'af-sum-up-readers' => 'E',
        'af-sum-up-replace-card' => 'E',
        'af-sum-up-solo-checkout' => 'E',
        'af-sum-up-qr-checkout' => 'E',
        'af-sum-up-hybrid-checkout' => 'E',
        // afCheckoutLayout components (bundled here, not a separate module)
        'crm-checkout-summary' => 'E',
        'crm-payment-orchestrator' => 'E',
        'crm-payment-method' => 'E',
        'crm-offline-payment' => 'E',
    ],
];
