<?php

declare(strict_types=1);

namespace App\Support\Privacy;

/**
 * A module's part in privacy requests: what it holds about a person, and how it forgets it.
 * Called inside the subject's workspace; each module only touches its own tables.
 */
interface PersonalDataSource
{
    /** A short key for exports and results, e.g. "orders". */
    public function name(): string;

    /** Widens the subject with what this module knows (e.g. the phones on the subject's orders). */
    public function expand(DataSubject $subject): DataSubject;

    /** @return array<string, mixed>|list<mixed> what this module holds about the subject, for the store owner */
    public function export(DataSubject $subject): array;

    /** Erases or anonymises the subject's personal data; returns how many records changed. */
    public function redact(DataSubject $subject): int;
}
