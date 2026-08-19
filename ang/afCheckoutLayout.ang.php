<?php

// Minimal CiviCRM Angular module registration — partials ONLY.
// This file exists solely so that CiviCRM can resolve
// ~/afCheckoutLayout/*.html URLs for the component templates.
// All JS is bundled and loaded by afSumUp (afSumUp.ang.php).
// No exports, no requires — prevents any Angular bootstrap interference.

return [
    'partials' => ['ang/afCheckoutLayout'],
    'requires' => [],
    'js' => [],
    'css' => [],
];
