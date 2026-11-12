<?php

namespace App\Services;

use App\Models\IssuerMarketPrice;
use Illuminate\Support\Collection;

/**
 * One user-scoped review state for each ticker's latest observed EOD date.
 * This class is the shared source of truth for conflict detection and the UI.
 */
class MarketPriceQualityService
{
    public function latestByTicker(int $userId): Collection
    {
        return IssuerMarketPrice::where('user_id', $userId)
            ->orderByDesc('observed_on')->orderByDesc('imported_at')->get()
            ->groupBy('issuer_code')
            ->map(function (Collection $history, string $ticker): array {
                $latest = $history->first();
                $date = $latest->observed_on->toDateString();
                $sameDate = $history->filter(fn (IssuerMarketPrice $item): bool =>
                    $item->observed_on->toDateString() === $date
                );
                $conflict = $sameDate->map(fn (IssuerMarketPrice $item): string =>
                    $this->fingerprint($item)
                )->unique()->count() > 1;
                $future = $date > now()->toDateString();
                $splitAdjusted = $sameDate->contains(fn (IssuerMarketPrice $item): bool =>
                    $item->adjustment_status === 'SPLIT_ADJUSTED'
                );

                return [
                    'ticker' => $ticker,
                    'observed_on' => $date,
                    'price' => $latest,
                    'conflict' => $conflict,
                    'status' => $future
                        ? 'N/A_FUTURE_MARKET_PRICE_DATE'
                        : ($conflict
                            ? 'N/A_MARKET_PRICE_CONFLICT'
                            : ($splitAdjusted ? 'N/A_SPLIT_ADJUSTMENT_UNALIGNED' : 'CONSISTENT_REVIEW_REQUIRED')),
                    'source_count' => $sameDate->count(),
                    'sources' => $sameDate->map(fn (IssuerMarketPrice $item): array => [
                        'name' => $item->source_name,
                        'currency' => $item->currency,
                        'price' => $item->price,
                        'adjustment' => $item->adjustment_status,
                        'rights' => $item->usage_rights,
                        'sha256' => $item->sha256,
                    ])->values()->all(),
                ];
            });
    }

    private function fingerprint(IssuerMarketPrice $price): string
    {
        return implode('|', [
            strtoupper((string) $price->currency),
            number_format((float) $price->price, 6, '.', ''),
            in_array($price->adjustment_status, ['UNADJUSTED', 'UNADJUSTED_CLOSE'], true)
                ? 'RAW_CLOSE' : (string) $price->adjustment_status,
        ]);
    }
}
