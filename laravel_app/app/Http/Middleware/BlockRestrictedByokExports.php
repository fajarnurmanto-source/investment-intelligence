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

        // Older imported fundamental documents may refer to a restricted
        // issuer source even when no market-price row was persisted. Protect
        // all existing fundamental/screener CSV exports in that case too.
        if (Schema::hasTable('ii_issuer_documents') && Schema::hasTable('ii_issuer_sources')) {
            $restrictedDocument = DB::table('ii_issuer_documents as documents')
                ->join('ii_issuer_sources as sources', 'documents.source_id', '=', 'sources.id')
                ->where('documents.user_id', $userId)
                ->where(function ($query): void {
                    $query->whereRaw('LOWER(sources.source_url) LIKE ?', ['%marketdata.app%'])
                        ->orWhereRaw('LOWER(sources.terms_url) LIKE ?', ['%marketdata.app%'])
                        ->orWhereRaw('LOWER(sources.name) LIKE ?', ['%marketdata.app%'])
                        ->orWhereRaw('LOWER(sources.name) LIKE ?', ['%marketdata byok%'])
                        ->orWhereRaw('LOWER(sources.publisher) LIKE ?', ['%marketdata.app%']);
                })->exists();

            if ($restrictedDocument) {
                abort(403, 'II_BYOK_LEGACY_RESTRICTED_DOCUMENT_EXPORT_BLOCKED');
            }
        }

        // An immutable registry claim must continue to block export even if
        // someone later edits the display name/URL on a legacy source row.
        // Never accept a mutable source label as an override of recorded rights.
        if (Schema::hasTable('ii_lineage_nodes')) {
            $restrictedLineage = DB::table('ii_lineage_nodes')
                ->where('user_id', $userId)
                ->where('rights_basis', 'RESTRICTED_BYOK')
                ->where('can_export', false)->exists();
            if ($restrictedLineage) {
                abort(403, 'II_LINEAGE_RESTRICTED_ANCESTRY_EXPORT_BLOCKED');
            }
        }

        return $next($request);
    }
}
