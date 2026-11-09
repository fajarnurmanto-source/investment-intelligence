<?php

namespace App\Services;

use App\Models\EconomicObservation;
use App\Models\IngestionRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use MathPHP\Statistics\Descriptive;

class MacroAnalyticsService
{
    public function dashboard(): array
    {
        return Cache::remember('ii:macro:dashboard', now()->addMinutes(15), function (): array {
            if (!Schema::hasTable('ii_economic_observations')) {
                return ['series' => [], 'generated_at' => now(), 'pipeline_status' => 'MIGRATION_REQUIRED'];
            }

            $series = [];
            foreach ((array) config('ii.fred.series') as $seriesId => $metadata) {
                $rows = EconomicObservation::query()
                    ->where('source_id', 'FRED')->where('series_id', $seriesId)
                    ->orderByDesc('observed_on')->limit(60)->get()->reverse()->values();
                if ($rows->isEmpty()) {
                    continue;
                }

                $values = $rows->map(fn ($row): float => (float) $row->value)->all();
                $changes = [];
                for ($i = 1; $i < count($values); $i++) {
                    if ($values[$i - 1] != 0.0) {
                        $changes[] = $values[$i] / $values[$i - 1] - 1.0;
                    }
                }
                $latest = $rows->last();
                $previous = count($values) > 1 ? $values[count($values) - 2] : null;
                $twelveAgo = count($values) > 12 ? $values[count($values) - 13] : null;
                $threeAgo = count($values) > 3 ? $values[count($values) - 4] : null;
                $latestValue = (float) $latest->value;
                $headline = $seriesId === 'CPIAUCSL' && $twelveAgo > 0
                    ? 100 * ($latestValue / $twelveAgo - 1)
                    : ($threeAgo !== null ? $latestValue - $threeAgo : null);
                $series[] = [
                    'id' => $seriesId,
                    'title' => $metadata['title'],
                    'owner' => $metadata['owner'],
                    'units' => $metadata['units'],
                    'frequency' => $metadata['frequency'],
                    'latest_date' => $latest->observed_on->format('Y-m-d'),
                    'latest_value' => round($latestValue, 4),
                    'one_period_change' => $previous === null ? null : round($latestValue - $previous, 4),
                    'headline_label' => $seriesId === 'CPIAUCSL' ? 'Inflasi YoY' : 'Perubahan 3 bulan',
                    'headline_value' => $headline === null ? null : round($headline, 2),
                    'headline_suffix' => $seriesId === 'CPIAUCSL' ? '%' : ' pp',
                    'change_volatility_pct' => count($changes) > 1 ? round(100 * Descriptive::standardDeviation($changes), 3) : null,
                    'observations' => $rows->count(),
                    'quality_status' => $latest->quality_status,
                    'retrieved_at' => $latest->retrieved_at?->toIso8601String(),
                    'source_url' => $latest->source_url,
                    'sparkline' => $this->sparkline(array_slice($values, -36)),
                ];
            }

            $latestRun = IngestionRun::query()->orderByDesc('started_at')->first();
            return [
                'series' => $series,
                'generated_at' => now()->toIso8601String(),
                'pipeline_status' => $latestRun?->status ?? 'NOT_RUN',
                'latest_run' => $latestRun,
            ];
        });
    }

    private function sparkline(array $values): string
    {
        if (count($values) < 2) {
            return '';
        }
        $minimum = min($values);
        $range = max(max($values) - $minimum, 0.0000001);
        $points = [];
        foreach ($values as $index => $value) {
            $x = 8 + 444 * $index / (count($values) - 1);
            $y = 88 - 72 * ($value - $minimum) / $range;
            $points[] = round($x, 1).','.round($y, 1);
        }
        return implode(' ', $points);
    }
}
