<?php

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
    'partials' => [
        'ang/afCheckoutLayout',
        'ang/afSumUp',
    ],
    'settings' => [],
    'requires' => ['afCheckout', 'afCheckoutLayout'],
    'exports' => [
        'af-sum-up-embedded-checkout' => 'E',
        'af-sum-up-payment-methods' => 'E',
        'af-sum-up-readers' => 'E',
        'af-sum-up-replace-card' => 'E',
        'af-sum-up-solo-checkout' => 'E',
        'af-sum-up-qr-checkout' => 'E',
        'af-sum-up-hybrid-checkout' => 'E',
        'crm-checkout-summary' => 'E',
    ],
];
