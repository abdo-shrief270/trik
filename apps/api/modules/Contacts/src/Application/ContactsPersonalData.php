<?php

declare(strict_types=1);

namespace Modules\Contacts\Application;

use App\Support\Privacy\DataSubject;
use App\Support\Privacy\PersonalDataSource;
use Modules\Contacts\Infrastructure\Models\Contact;

/**
 * Contacts in privacy requests. Redaction clears the name (learned from store orders); the phone
 * stays, because it is the identity of the seller's own WhatsApp conversation with the person, which
 * a store's request doesn't cover. A direct erasure request (later) removes the contact entirely.
 */
final class ContactsPersonalData implements PersonalDataSource
{
    public function name(): string
    {
        return 'contacts';
    }

    public function expand(DataSubject $subject): DataSubject
    {
        return $subject;
    }

    public function export(DataSubject $subject): array
    {
        if ($subject->phones === []) {
            return [];
        }

        return array_values(Contact::query()->whereIn('phone', $subject->phones)->orderBy('created_at')->get()
            ->map(fn (Contact $c): array => ['phone' => $c->phone, 'name' => $c->name, 'created_at' => $c->created_at?->toIso8601String()])
            ->all());
    }

    public function redact(DataSubject $subject): int
    {
        $count = 0;
        foreach (array_chunk($subject->phones, 1000) as $phones) {
            $count += Contact::query()->whereIn('phone', $phones)->whereNotNull('name')->update(['name' => null, 'updated_at' => now()]);
        }

        return $count;
    }
}
