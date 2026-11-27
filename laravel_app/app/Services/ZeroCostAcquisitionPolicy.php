<?php

namespace App\Services;

use Psr\Http\Message\RequestInterface;

/** Deny by default at the Laravel HTTP transport boundary. No account-based
 * API is currently proven to have a hard zero-charge entitlement. */
class ZeroCostAcquisitionPolicy
{
    public function assertAllowed(RequestInterface $request): void
    {
        $uri = $request->getUri();
        $host = strtolower($uri->getHost());
        $path = $uri->getPath();
        $query = $uri->getQuery();
        if ($request->getMethod() !== 'GET' || $uri->getScheme() !== 'https'
            || $uri->getUserInfo() !== '' || $uri->getPort() !== null
            || $request->hasHeader('Authorization') || $request->hasHeader('Cookie')) {
            throw new \RuntimeException('II_ZERO_COST_REQUEST_DENIED');
        }
        if ($host === 'fred.stlouisfed.org' && $path === '/graph/fredgraph.csv'
            && preg_match('/^id=(CPIAUCSL|FEDFUNDS)$/D', $query)) {
            return;
        }
        if ($host === 'data.sec.gov' && $query === ''
            && preg_match('#^/api/xbrl/companyfacts/CIK[0-9]{10}\.json$#D', $path)) {
            return;
        }
        if ($host === 'raw.githubusercontent.com' && $query === ''
            && preg_match('#^/wildangunawan/Dataset-Saham-IDX/'.IdxHistoricalSnapshotSource::COMMIT.'/(Saham/(?:Semua|LQ45)/([A-Z0-9]{4})\.csv)$#D', $path, $match)) {
            if (app(IdxHistoricalCatalog::class)->resolve($match[2])['path'] === $match[1]) {
                return;
            }
        }
        throw new \RuntimeException('II_ZERO_COST_PROVIDER_UNVERIFIED');
    }

    public function status(): array
    {
        return [
            'idx_pinned' => 'PUBLIC_PINNED_NO_SUBSCRIPTION',
            'fred' => 'PUBLIC_OFFICIAL_NO_SUBSCRIPTION',
            'sec_companyfacts' => 'PUBLIC_OFFICIAL_NO_SUBSCRIPTION',
            'local' => 'LOCAL_NO_REQUEST',
            'twelve_data' => 'BLOCKED_COST_UNVERIFIED',
            'marketdata' => 'BLOCKED_COST_UNVERIFIED',
            'unknown' => 'BLOCKED_DEFAULT',
        ];
    }
}
