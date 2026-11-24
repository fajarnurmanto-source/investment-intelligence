<?php

namespace App\Services;

use App\Models\IssuerCorporateAction;
use App\Models\IssuerShareBasisReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * User-attested, source-linked reconciliation of fiscal shares to raw EOD shares.
 * Reviews are immutable snapshots of the corporate-action ledger, not proof that
 * an exchange feed is complete or a replacement for licensed market data.
 */
class IssuerShareBasisReconciliationService
{
    private const RIGHTS = ['internal_research', 'licensed_internal', 'redistribution_permitted'];

    public function recordAction(int $userId, array $input): IssuerCorporateAction
    {
        $data = Validator::make($input, [
            'ticker' => ['required', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'effective_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'action_type' => ['required', 'in:split,reverse_split,bonus_issue'],
            'new_shares' => ['required', 'integer', 'between:1,1000000000'],
            'old_shares' => ['required', 'integer', 'between:1,1000000000'],
            'source_name' => ['required', 'string', 'max:160'],
            'source_url' => ['required', 'url', 'max:1000'],
            'usage_rights' => ['required', 'in:internal_research,licensed_internal,redistribution_permitted'],
            'provenance_confidence' => ['required', 'in:high,medium,low'],
            'rights_confirmed' => ['accepted'],
        ])->validate();

        if ((int) $data['new_shares'] === (int) $data['old_shares']) {
            throw new \InvalidArgumentException('Rasio aksi korporasi tidak boleh 1:1; gunakan review tanpa perubahan.');
        }

        app(ByokIntakeFirewall::class)->assertIndependentSource($data);

        $payload = [
            'issuer_code' => strtoupper($data['ticker']),
            'effective_on' => $data['effective_on'],
            'action_type' => $data['action_type'],
            'new_shares' => (int) $data['new_shares'],
            'old_shares' => (int) $data['old_shares'],
            'source_name' => $data['source_name'],
            'source_url' => $data['source_url'],
            'usage_rights' => $data['usage_rights'],
            'provenance_confidence' => strtoupper($data['provenance_confidence']),
        ];
        $sha = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (IssuerCorporateAction::where('user_id', $userId)->where(function ($query) use ($payload, $sha): void {
            $query->where('sha256', $sha)->orWhere(function ($other) use ($payload): void {
                $other->where('issuer_code', $payload['issuer_code'])
                    ->whereDate('effective_on', $payload['effective_on'])
                    ->where('action_type', $payload['action_type']);
            });
        })->exists()) {
            throw new \InvalidArgumentException('Aksi korporasi identik atau bertentangan sudah tercatat pada tanggal tersebut.');
        }

        return DB::transaction(function () use ($userId, $payload, $sha): IssuerCorporateAction {
            $action = IssuerCorporateAction::create(['user_id' => $userId, ...$payload, 'sha256' => $sha]);
            app(LocalLineageRegistry::class)->registerSource(
                $userId, 'issuer_corporate_action', $action->id, $sha, $payload
            );

            return $action;
        }, 1);
    }

    public function attestCoverage(int $userId, array $input): IssuerShareBasisReview
    {
        $data = Validator::make($input, [
            'ticker' => ['required', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'period_end' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'price_observed_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_end', 'before_or_equal:today'],
            'source_name' => ['required', 'string', 'max:160'],
            'source_url' => ['required', 'url', 'max:1000'],
            'usage_rights' => ['required', 'in:internal_research,licensed_internal,redistribution_permitted'],
            'provenance_confidence' => ['required', 'in:high,medium,low'],
            'coverage_confirmed' => ['accepted'],
            'rights_confirmed' => ['accepted'],
        ])->validate();
        app(ByokIntakeFirewall::class)->assertIndependentSource($data);
        $ticker = strtoupper($data['ticker']);

        $hasPeriod = DB::table('ii_issuer_periods')
            ->join('ii_issuer_documents', 'ii_issuer_documents.id', '=', 'ii_issuer_periods.document_id')
            ->where('ii_issuer_documents.user_id', $userId)
            ->where('ii_issuer_documents.issuer_code', $ticker)
            ->whereDate('ii_issuer_periods.period_end', $data['period_end'])
            ->exists();
        $hasPrice = DB::table('ii_issuer_market_prices')
            ->where('user_id', $userId)->where('issuer_code', $ticker)
            ->whereDate('observed_on', $data['price_observed_on'])->exists();

        if (! $hasPeriod || ! $hasPrice) {
            throw new \InvalidArgumentException('Periode fundamental dan tanggal harga harus sudah tercatat untuk pengguna yang sama.');
        }

        $actions = $this->events($userId, $ticker, $data['period_end'], $data['price_observed_on']);
        $factor = $this->factor($actions);
        $payload = [
            'issuer_code' => $ticker,
            'period_end' => $data['period_end'],
            'price_observed_on' => $data['price_observed_on'],
            'events_sha256' => $this->digest($actions),
            'cumulative_share_factor' => number_format($factor, 12, '.', ''),
            'source_name' => $data['source_name'],
            'source_url' => $data['source_url'],
            'usage_rights' => $data['usage_rights'],
            'provenance_confidence' => strtoupper($data['provenance_confidence']),
            'coverage_confirmed' => true,
        ];
        $sha = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (IssuerShareBasisReview::where('user_id', $userId)->where('sha256', $sha)->exists()) {
            throw new \InvalidArgumentException('Review basis saham dengan bukti dan daftar aksi yang sama sudah disimpan.');
        }

        return DB::transaction(function () use ($userId, $payload, $sha, $actions): IssuerShareBasisReview {
            $registry = app(LocalLineageRegistry::class);
            $review = IssuerShareBasisReview::create(['user_id' => $userId, ...$payload, 'sha256' => $sha]);
            $reviewSource = $registry->registerSource(
                $userId, 'issuer_share_basis_review', $review->id, $sha, $payload
            );
            $parents = [$reviewSource['id']];
            foreach ($actions as $action) {
                $registeredAction = $registry->find(
                    $userId, 'issuer_corporate_action', $action->id, 'ACQUIRED_SOURCE'
                );
                if ($registeredAction === null
                    || ! hash_equals((string) $action->sha256, $registeredAction['evidence_sha256'])) {
                    throw new \RuntimeException('II_SHARE_BASIS_ACTION_LINEAGE_MISSING');
                }
                $parents[] = $registeredAction['id'];
            }
            $registry->registerTransformation(
                $userId, 'issuer_share_basis_review', $review->id,
                'RECONCILE_SHARE_BASIS_V1',
                hash('sha256', $sha.'|'.$payload['events_sha256']), $parents
            );

            return $review;
        }, 1);
    }

    /** @return array{status: string, shares: ?float, factor: ?float, source: ?string} */
    public function resolve(int $userId, string $ticker, string $periodEnd, string $priceDate, ?float $shares): array
    {
        if ($shares === null || $shares <= 0) {
            return ['status' => 'N/A_MISSING_SHARE_COUNT', 'shares' => null, 'factor' => null, 'source' => null];
        }
        if ($priceDate < $periodEnd) {
            return ['status' => 'N/A_PRICE_PREDATES_REPORT_PERIOD', 'shares' => null, 'factor' => null, 'source' => null];
        }
        if ($priceDate === $periodEnd) {
            return ['status' => 'ALIGNED_SAME_DAY', 'shares' => $shares, 'factor' => 1.0, 'source' => null];
        }

        $review = IssuerShareBasisReview::where('user_id', $userId)->where('issuer_code', strtoupper($ticker))
            ->whereDate('period_end', $periodEnd)->whereDate('price_observed_on', $priceDate)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($review === null) {
            return ['status' => 'N/A_SHARE_BASIS_REVIEW_MISSING', 'shares' => null, 'factor' => null, 'source' => null];
        }

        $actions = $this->events($userId, strtoupper($ticker), $periodEnd, $priceDate);
        try {
            $factor = $this->factor($actions);
        } catch (\InvalidArgumentException) {
            return ['status' => 'N/A_SHARE_BASIS_FACTOR_UNSAFE', 'shares' => null, 'factor' => null, 'source' => $review->source_name];
        }
        if (! $review->coverage_confirmed || ! in_array($review->usage_rights, self::RIGHTS, true)
            || $review->events_sha256 !== $this->digest($actions)
            || abs((float) $review->cumulative_share_factor - $factor) > 0.000000001) {
            return ['status' => 'N/A_SHARE_BASIS_REVIEW_STALE', 'shares' => null, 'factor' => null, 'source' => $review->source_name];
        }

        return [
            'status' => 'ALIGNED_USER_ATTESTED',
            'shares' => $shares * $factor,
            'factor' => $factor,
            'source' => $review->source_name,
            'review_id' => $review->id,
        ];
    }

    private function events(int $userId, string $ticker, string $periodEnd, string $priceDate)
    {
        return IssuerCorporateAction::where('user_id', $userId)->where('issuer_code', $ticker)
            ->whereDate('effective_on', '>', $periodEnd)->whereDate('effective_on', '<=', $priceDate)
            ->orderBy('effective_on')->orderBy('sha256')->get();
    }

    private function digest($actions): string
    {
        return hash('sha256', json_encode($actions->pluck('sha256')->all(), JSON_THROW_ON_ERROR));
    }

    private function factor($actions): float
    {
        $factor = 1.0;
        foreach ($actions as $event) {
            $factor *= (int) $event->new_shares / (int) $event->old_shares;
            if (! is_finite($factor) || $factor < 0.000001 || $factor > 1000000) {
                throw new \InvalidArgumentException('Rasio kumulatif aksi korporasi di luar batas aman; perlu telaah manual.');
            }
        }

        return $factor;
    }
}
