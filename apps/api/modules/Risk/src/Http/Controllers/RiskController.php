<?php

declare(strict_types=1);

namespace Modules\Risk\Http\Controllers;

use App\Support\Http\Problem;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Risk\Application\Jobs\RescoreWorkspace;
use Modules\Risk\Application\RiskReasons;
use Modules\Risk\Domain\RiskWeights;
use Modules\Risk\Infrastructure\Models\RiskAssessment;
use Modules\Risk\Infrastructure\Models\RiskSettings;

final class RiskController
{
    /** An order's refusal risk: the score, whether it is flagged, and why, in Arabic and English. */
    public function show(string $order): JsonResponse
    {
        $assessment = Str::isUuid($order) ? RiskAssessment::query()->find($order) : null;
        if ($assessment === null) {
            throw Problem::notFound('risk-not-scored', 'This order has no risk score yet.');
        }
        $threshold = RiskSettings::current()->threshold;
        $reasons = array_map(fn (array $r): array => RiskReasons::describe($r), $assessment->reasons);

        return response()->json(['data' => [
            'order_id' => $assessment->order_id,
            'score' => $assessment->score,
            'threshold' => $threshold,
            'flagged' => $assessment->score >= $threshold,
            'top' => array_slice(array_values(array_filter($reasons, fn (array $r): bool => $r['code'] !== 'baseline')), 0, 3),
            'reasons' => $reasons,
            'model' => $assessment->model,
            'computed_at' => $assessment->computed_at->toIso8601String(),
        ]]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->presentSettings(RiskSettings::current())]);
    }

    /** Threshold and weights; open orders are scored again with them (queued). */
    public function updateSettings(Request $request, TenantContext $tenant): JsonResponse
    {
        $rules = array_keys(RiskWeights::DEFAULTS);
        $data = $request->validate([
            'threshold' => ['sometimes', 'integer', 'between:1,100'],
            'weights' => ['sometimes', 'array:'.implode(',', $rules)],
            'weights.*' => ['integer', 'between:-50,50'],
        ]);

        $settings = RiskSettings::current();
        if (array_key_exists('threshold', $data)) {
            $settings->threshold = (int) $data['threshold'];
        }
        if (array_key_exists('weights', $data)) {
            // only what differs from the defaults is kept, so later default changes still reach the seller
            $settings->weights = array_filter(array_map('intval', $data['weights']) + $settings->weights,
                fn (int $points, string $rule): bool => in_array($rule, $rules, true) && $points !== RiskWeights::DEFAULTS[$rule], ARRAY_FILTER_USE_BOTH);
        }
        $settings->save();

        RescoreWorkspace::dispatch($tenant->require());

        return response()->json(['data' => $this->presentSettings($settings)]);
    }

    /** @return array<string, mixed> */
    private function presentSettings(RiskSettings $s): array
    {
        return [
            'threshold' => $s->threshold,
            'weights' => $s->riskWeights()->points,
            'defaults' => ['threshold' => RiskSettings::DEFAULT_THRESHOLD, 'weights' => RiskWeights::DEFAULTS],
        ];
    }
}
