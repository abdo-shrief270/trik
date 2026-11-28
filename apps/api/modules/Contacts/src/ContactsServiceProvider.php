<?php

declare(strict_types=1);

namespace Modules\Contacts;

use App\Support\Modules\ModuleServiceProvider;
use App\Support\Privacy\PersonalDataRegistry;
use Modules\Contacts\Application\ContactsPersonalData;

/** Customer profiles. Today: one contact per WhatsApp number, created as customers write in. */
final class ContactsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        app(PersonalDataRegistry::class)->register(ContactsPersonalData::class);
    }
}
