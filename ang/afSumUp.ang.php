<?php

return [
    'css' => [
        'ang/afSumUp/sumUp.css',
    ],
    'js' => [
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
        'sumup-checkout-admin' => 'E',
    ],
];
