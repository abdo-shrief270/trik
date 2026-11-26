<?php

declare(strict_types=1);

it('answers liveness without touching dependencies', function (): void {
    $this->getJson('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('reports not ready when the app connection is not the restricted role', function (): void {
    config(['database.connections.pgsql.role' => 'someone_else']);

    $this->getJson('/readyz')->assertStatus(503)->assertJsonPath('checks.database_role', false);
});

it('reports readiness of database and cache', function (): void {
    $this->getJson('/readyz')
        ->assertOk()
        ->assertJson(['status' => 'ok', 'checks' => ['database' => true, 'database_role' => true, 'cache' => true]]);
});
