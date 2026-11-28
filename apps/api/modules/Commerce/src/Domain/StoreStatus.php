<?php

declare(strict_types=1);

namespace Modules\Commerce\Domain;

enum StoreStatus: string
{
    /** Connected: webhooks route here and the token works. */
    case Active = 'active';

    /** The seller removed the app in the store's admin (app/uninstalled); the token is gone. */
    case Uninstalled = 'uninstalled';

    /** Disconnected from Trik; the token is dropped. The app may still be installed in the store. */
    case Disconnected = 'disconnected';
}
