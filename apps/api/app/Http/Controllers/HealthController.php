<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthController
{
    /** Liveness: the process is up. No dependencies are checked. */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Readiness: the app can reach its database and cache. */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            // Row-level security only protects data while the app runs as the restricted role.
            'database_role' => $this->check(function (): void {
                $expected = (string) config('database.connections.pgsql.role');
                if ($expected !== '' && DB::scalar('select current_user') !== $expected) {
                    throw new \RuntimeException("App connection is not running as {$expected}.");
                }
            }),
            'cache' => $this->check(function (): void {
                Cache::put('readyz', '1', 5);
                if (Cache::get('readyz') !== '1') {
                    throw new \RuntimeException('cache read-back failed');
                }
            }),
        ];

        $ok = ! in_array(false, $checks, true);

        return response()->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], $ok ? 200 : 503);
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
