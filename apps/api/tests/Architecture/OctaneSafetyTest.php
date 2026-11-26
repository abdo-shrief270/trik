<?php

declare(strict_types=1);

/*
 * Under Octane every request runs in its own copy of the container. A runtime callback that
 * captured the provider's $this->app resolves services from the boot-time container instead,
 * so per-request (scoped) state set by middleware is invisible to it. This bit the organization
 * gate once (it always answered "forbidden" under Octane while tests passed). Runtime callbacks
 * must use app() / the injected container.
 */
it('never resolves per-request services from the boot container inside runtime callbacks', function (): void {
    $root = dirname(__DIR__, 2);
    $offenders = [];

    $files = [...glob($root.'/app/Providers/*.php') ?: [], ...glob($root.'/modules/*/src/*ServiceProvider.php') ?: []];

    foreach ($files as $file) {
        foreach (file($file) ?: [] as $n => $line) {
            if (preg_match('/(Gate::before|Gate::after|Gate::define|RateLimiter::for)\(.*\$this->app/', $line) === 1) {
                $offenders[] = str_replace($root.'/', '', $file).':'.($n + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
