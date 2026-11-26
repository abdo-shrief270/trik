<?php

declare(strict_types=1);

return [
    // Cache store for Idempotency-Key responses (null = the default store; Redis in production).
    'idempotency_store' => env('IDEMPOTENCY_STORE'),
];
