<?php

declare(strict_types=1);

it('answers liveness without touching dependencies', function (): void {
    $this->getJson('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('reports readiness of database and cache', function (): void {
    $this->getJson('/readyz')
        ->assertOk()
        ->assertJson(['status' => 'ok', 'checks' => ['database' => true, 'cache' => true]]);
});
