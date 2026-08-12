<?php

return [
    'js' => [
        'js/checkout.js',
        'ang/afSumUp.js',
        'ang/afSumUp/*.js',
    ],
    'partials' => ['ang/afSumUp'],
    'settings' => [],
    'requires' => ['afCheckout'],
    'exports' => ['af-sum-up-embedded-checkout' => 'E'],
];
