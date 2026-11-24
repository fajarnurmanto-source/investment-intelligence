<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail closed on known legacy MarketData BYOK provenance before allowing CSV
 * exports, even if the rows predate the new import firewall.
 *
 * This is intentionally conservative and user-scoped. Full cross-module
 * lineage tracking remains required before provider-derived calculations
 * can be independently exported.
 */
final class BlockRestrictedByokExports
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->id;
        if ($userId === null) {
            abort(403, 'II_BYOK_EXPORT_AUTH_REQUIRED');
        }

        if (Schema::hasTable('ii_issuer_market_prices')) {
            $restricted = DB::table('ii_issuer_market_prices')
                ->where('user_id', $userId)
                ->where(function ($query): void {
                    $query->whereRaw('LOWER(source_url) LIKE ?', ['%marketdata.app%'])
                        ->orWhereRaw('LOWER(source_name) LIKE ?', ['%marketdata.app%'])
                        ->orWhereRaw('LOWER(source_name) LIKE ?', ['%marketdata byok%'])
                        ->orWhereRaw('LOWER(publisher) LIKE ?', ['%marketdata.app%']);
                })->exists();

            if ($restricted) {
                abort(403, 'II_BYOK_LEGACY_RESTRICTED_PROVENANCE_EXPORT_BLOCKED');
            }
        }

        return $next($request);
    }
}
