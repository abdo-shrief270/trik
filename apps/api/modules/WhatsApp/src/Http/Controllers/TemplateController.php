<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\WhatsApp\Application\Contracts\WhatsAppProvider;
use Modules\WhatsApp\Application\ProviderRegistry;
use Modules\WhatsApp\Domain\Capability;
use Modules\WhatsApp\Domain\Template;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;

final class TemplateController
{
    public function index(string $number, ProviderRegistry $providers): JsonResponse
    {
        $templates = $this->provider($number, $providers)->templates();

        return response()->json(['data' => array_map(fn (Template $t): array => (array) $t, $templates)]);
    }

    public function store(Request $request, string $number, ProviderRegistry $providers): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-z0-9_]{1,512}$/'],
            'language' => ['required', 'string', 'max:15'],
            'category' => ['required', 'in:marketing,utility,authentication,MARKETING,UTILITY,AUTHENTICATION'],
            'components' => ['required', 'array', 'min:1'],
            'components.*' => ['array'],
        ]);

        $id = $this->provider($number, $providers)->createTemplate(
            $data['name'],
            $data['language'],
            strtoupper($data['category']),
            array_values($data['components']),
        );

        return response()->json(['data' => ['id' => $id, 'name' => $data['name'], 'status' => 'pending']], 201);
    }

    public function destroy(string $number, string $name, ProviderRegistry $providers): JsonResponse
    {
        $this->provider($number, $providers)->deleteTemplate($name);

        return response()->json(null, 204);
    }

    private function provider(string $number, ProviderRegistry $providers): WhatsAppProvider
    {
        $adapter = $providers->for(WhatsAppNumber::query()->findOrFail($number)->toConnected());

        abort_unless($adapter->supports(Capability::Templates), 422, 'This number\'s provider does not manage templates.');

        return $adapter;
    }
}
