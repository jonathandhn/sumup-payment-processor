<?php

return [
    'css' => [
        'ang/afSumUp/sumUp.css',
    ],
    'js' => [
        'js/checkout.js',
        'ang/afSumUp.js',
        'ang/afSumUp/*.js',
    ],
    'partials' => ['ang/afSumUp'],
    'settings' => [],
    // afCheckoutLayout is a separate CiviCRM Angular module declared in
    // ang/afCheckoutLayout.ang.php — CiviCRM resolves and loads it once.
    'requires' => ['afCheckout', 'afCheckoutLayout'],
    'exports' => [
        'af-sum-up-embedded-checkout' => 'E',
        'af-sum-up-payment-methods' => 'E',
        'af-sum-up-readers' => 'E',
        'af-sum-up-replace-card' => 'E',
        'af-sum-up-solo-checkout' => 'E',
        'af-sum-up-qr-checkout' => 'E',
        'af-sum-up-hybrid-checkout' => 'E',
    ],
];
