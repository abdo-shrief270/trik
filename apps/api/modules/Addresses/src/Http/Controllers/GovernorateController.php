<?php

declare(strict_types=1);

namespace Modules\Addresses\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Addresses\Domain\Governorate;

final class GovernorateController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (Governorate $g): array => $g->toArray(), Governorate::cases())]);
    }
}
