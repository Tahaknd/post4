<?php

return [
    // Every public SDK path, for every origin and without credentials. `s` (session recording) and `static` (lazy
    // scripts) are not served in 3.9: the stubbed remote config switches those SDK features off.
    'paths' => ['e', 'batch', 'capture', 'decide', 'flags', 'i/*', 'array/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 86400,
    'supports_credentials' => false,
];
