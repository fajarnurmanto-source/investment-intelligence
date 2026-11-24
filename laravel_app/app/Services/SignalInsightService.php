<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * VS7 research-only observations. NEVER an order, adviser recommendation or
 * admission decision. Each user's uploaded series remains isolated locally.
 */
class SignalInsightService
{
    private function directory(int $userId): string
    {
        return 'ii_signal_center/u'.$userId;
    }

    public function save(int $userId, string $ticker, array $document): void
    {
        // Immutable, versioned uploads: importing the same ticker never erases history.
        $path = $this->directory($userId).'/'.$ticker.'__'.bin2hex(random_bytes(12)).'.json';
        app(ByokIntakeFirewall::class)->assertIndependentSource($document);
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sha = hash('sha256', $json);
        // Filesystem and SQLite are not a distributed transaction. If lineage
        // registration fails, delete this newly-created version only.
        if (!Storage::disk('local')->put($path, $json)) {
            throw new \RuntimeException('Could not save the uploaded price series.');
        }
        try {
            app(LocalLineageRegistry::class)->registerSource(
                $userId, 'signal_ohlcv_document', $sha, $sha, $document
            );
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    /** Return the latest original OHLCV document belonging to this signed-in user. */
    public function latestDocument(int $userId, string $ticker): ?array
    {
        if (!preg_match('/^[A-Z0-9.\-]{1,16}$/', $ticker)) {
            return null;
        }
        $documents = $this->latestDocuments($userId);
        return $documents[$ticker] ?? null;
    }

    public function list(int $userId): array
    {
        $records = array_map(fn (array $document) => $this->insight($document), array_values($this->latestDocuments($userId)));
        usort($records, fn (array $a, array $b) => strcmp($a['ticker'], $b['ticker']));
        return $records;
    }

    private function latestDocuments(int $userId): array
    {
        $disk = Storage::disk('local');
        $latest = [];
        foreach ($disk->files($this->directory($userId)) as $path) {
            if (!str_ends_with($path, '.json')) {
                continue;
            }
            try {
                $document = json_decode($disk->get($path), true);
            } catch (\Throwable $exception) {
                continue;
            }
            if (!is_array($document)
                || !preg_match('/^[A-Z0-9.\-]{1,16}$/', (string) ($document['ticker'] ?? ''))
                || !is_array($document['rows'] ?? null)
                || count($document['rows']) < 60) {
                continue;
            }
            $ticker = $document['ticker'];
            $node = app(LocalLineageRegistry::class)->find(
                $userId, 'signal_ohlcv_document',
                hash('sha256', $disk->get($path)), 'ACQUIRED_SOURCE'
            );
            $document['lineage_source_node_id'] = null;
            $document['lineage_source_status'] = 'UNREGISTERED';
            // Independently rehash the physical JSON file before using the
            // source node. Legacy sources remain identifiable as unregistered.
            if ($node !== null && app(LocalLineageRegistry::class)->verifyTrace($userId, $node['id'])
                && hash_equals($node['evidence_sha256'], hash('sha256', $disk->get($path)))) {
                $document['lineage_source_node_id'] = $node['id'];
                $document['lineage_source_status'] = 'REGISTERED';
            }
            // Storage filenames are unique; lexical timestamps select the newest import.
            if (!isset($latest[$ticker]) || strcmp((string) ($document['imported_at'] ?? ''), (string) ($latest[$ticker]['imported_at'] ?? '')) > 0) {
                $latest[$ticker] = $document;
            }
        }
        return $latest;
    }

    public function insight(array $document): array
    {
        $sourceMetadata = MarketSourceMetadataService::fromDocument($document);
        $rows = $document['rows'];
        $close = array_map(fn (array $r) => (float) $r['close'], $rows);
        $latest = $rows[count($rows) - 1];
        $sma20 = array_sum(array_slice($close, -20)) / 20;
        $sma50 = array_sum(array_slice($close, -50)) / 50;
        $price = (float) $latest['close'];

        $gain = 0.0;
        $loss = 0.0;
        for ($i = count($close) - 14; $i < count($close); $i++) {
            $difference = $close[$i] - $close[$i - 1];
            $gain += max($difference, 0);
            $loss += max(-$difference, 0);
        }
        $rsi = $loss == 0.0 ? ($gain == 0.0 ? 50.0 : 100.0) : 100 - 100 / (1 + $gain / $loss);

        $trueRanges = [];
        for ($i = count($rows) - 14; $i < count($rows); $i++) {
            $high = (float) $rows[$i]['high'];
            $low = (float) $rows[$i]['low'];
            $previousClose = (float) $rows[$i - 1]['close'];
            $trueRanges[] = max($high - $low, abs($high - $previousClose), abs($low - $previousClose));
        }
        $atr = array_sum($trueRanges) / count($trueRanges);

        $lastDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $latest['date'], new \DateTimeZone('Asia/Jakarta'));
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Jakarta'));
        $age = (int) $today->diff($lastDate)->days;
        $stale = $lastDate > $today || $age > 10;
        $up = $price > $sma20 && $sma20 > $sma50 && $rsi >= 50 && $rsi < 75;
        $down = $price < $sma20 && $sma20 < $sma50 && $rsi < 50;
        $state = $stale ? 'STALE' : ($up ? 'MOMENTUM_UP' : ($down ? 'MOMENTUM_DOWN' : 'MIXED'));
        $description = match ($state) {
            'STALE' => 'Data terlalu lama / bertanggal tidak valid untuk sinyal terkini; unggah pembaruan.',
            'MOMENTUM_UP' => 'Harga di atas SMA20 dan SMA50; RSI14 mendukung tren. Tetap perlu konfirmasi risiko.',
            'MOMENTUM_DOWN' => 'Harga di bawah SMA20 dan SMA50; momentum melemah. Belum merupakan instruksi jual.',
            default => 'Indikator tren belum searah atau momentum ekstrem. Tidak ada konfirmasi arah.',
        };

        $spark = array_slice($close, -60);
        $minimum = min($spark);
        $range = max(max($spark) - $minimum, 0.000001);
        $points = [];
        foreach ($spark as $i => $v) {
            $points[] = round(8 + 444 * $i / max(count($spark) - 1, 1), 1).','.round(88 - 74 * ($v - $minimum) / $range, 1);
        }

        return [
            'ticker' => $document['ticker'],
            'kind' => $document['kind'],
            'source_name' => $document['source_name'],
            'source_url' => $document['source_url'] ?? null,
            'imported_at' => $document['imported_at'],
            'last_date' => $latest['date'],
            'rows' => count($rows),
            'price' => round($price, 4),
            'sma20' => round($sma20, 4),
            'sma50' => round($sma50, 4),
            'rsi14' => round($rsi, 2),
            'atr14_pct' => round($atr / $price * 100, 2),
            'state' => $state,
            'description' => $description,
            'age_days' => $age,
            'stale' => $stale,
            'spark' => implode(' ', $points),
            'evidence' => 'USER_IMPORTED_UNVERIFIED',
            'decision' => 'HOLD',
            'trading_authorized' => false,
            'currency' => $sourceMetadata['currency'],
            'price_adjustment' => $sourceMetadata['price_adjustment'],
            'session_timezone' => $sourceMetadata['session_timezone'],
            'metadata_provenance' => $sourceMetadata['metadata_provenance'],
        ];
    }
}
