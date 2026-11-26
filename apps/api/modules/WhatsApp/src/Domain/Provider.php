<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Domain;

/** Who carries a number's WhatsApp traffic. Stored per number, so a number can move. */
enum Provider: string
{
    /** 360dialog partner programme (the MVP BSP, ADR 0009). */
    case Dialog360 = '360dialog';

    /** Trik's own Meta app on the Cloud API (after Tech Provider approval). */
    case MetaCloud = 'meta_cloud';

    /** In-memory provider for local development and tests. */
    case Fake = 'fake';
}
