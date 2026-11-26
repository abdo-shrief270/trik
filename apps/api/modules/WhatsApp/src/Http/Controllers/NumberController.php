<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Http\Controllers;

use App\Support\Http\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\WhatsApp\Application\ConnectNumber;
use Modules\WhatsApp\Application\Exceptions\ProviderRequestFailed;
use Modules\WhatsApp\Application\ProviderRegistry;
use Modules\WhatsApp\Application\WebhookUrls;
use Modules\WhatsApp\Domain\Provider;
use Modules\WhatsApp\Infrastructure\Models\WhatsAppNumber;

final class NumberController
{
    public function index(ProviderRegistry $providers): JsonResponse
    {
        $numbers = WhatsAppNumber::query()->orderBy('created_at')->get();

        return response()->json([
            'data' => $numbers->map(fn (WhatsAppNumber $n): array => [
                ...$n->toApi(),
                'capabilities' => array_map(fn ($c) => $c->value, $providers->for($n->toConnected())->capabilities()),
                'webhook_url' => WebhookUrls::forNumber($n->id, $n->provider),
            ])->values(),
        ]);
    }

    /**
     * Pilot onboarding: connect a number with credentials from the provider's onboarding.
     * (The in-app embedded onboarding flow replaces this for self-serve sellers.)
     */
    public function store(Request $request, ConnectNumber $connect, ProviderRegistry $providers): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_map(fn (Provider $p) => $p->value, $providers->available()))],
            'external_id' => ['required', 'string', 'max:64'],
            'waba_id' => [Rule::requiredIf($request->input('provider') === Provider::MetaCloud->value), 'nullable', 'string', 'max:64'],
            'display_phone_number' => ['nullable', 'string', 'max:32'],
            'credentials' => ['required', 'array'],
            'credentials.api_key' => [Rule::requiredIf($request->input('provider') === Provider::Dialog360->value), 'string'],
            'credentials.access_token' => [Rule::requiredIf($request->input('provider') === Provider::MetaCloud->value), 'string'],
        ]);

        $provider = Provider::from($data['provider']);

        if (WhatsAppNumber::query()->where('provider', $provider)->where('external_id', $data['external_id'])->exists()) {
            throw Problem::conflict('number-already-connected', 'This number is already connected.');
        }

        try {
            $number = $connect->handle(
                $provider,
                $data['external_id'],
                array_map('strval', array_intersect_key($data['credentials'], array_flip(['api_key', 'access_token']))),
                $data['waba_id'] ?? null,
                $data['display_phone_number'] ?? null,
            );
        } catch (ProviderRequestFailed $e) {
            throw Problem::unprocessable('provider-rejected', 'The provider rejected the connection: '.$e->getMessage(), ['provider_error' => $e->toArray()]);
        }

        return response()->json(['data' => [...$number->toApi(), 'webhook_url' => WebhookUrls::forNumber($number->id, $provider)]], 201);
    }
}
