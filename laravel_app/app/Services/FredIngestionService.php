<?php

namespace App\Services;

use App\Events\EconomicDataUpdated;
use App\Models\DataSource;
use App\Models\EconomicObservation;
use App\Models\IngestionRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use League\Csv\Reader;

class FredIngestionService
{
    public function ingest(string $seriesId): IngestionRun
    {
        $seriesId = strtoupper($seriesId);
        $metadata = config('ii.fred.series.'.$seriesId);
        if (!is_array($metadata)) {
            throw new \InvalidArgumentException('FRED series is not allow-listed: '.$seriesId);
        }

        $source = DataSource::query()->updateOrCreate(['id' => config('ii.fred.source_id')], [
            'name' => config('ii.fred.name'),
            'source_type' => 'PUBLIC_ECONOMIC_DATA',
            'base_url' => 'https://fred.stlouisfed.org/',
            'terms_url' => config('ii.fred.terms_url'),
            'rights_status' => 'INTERNAL_RESEARCH_WITH_SOURCE_TERMS',
            'attribution_text' => config('ii.fred.attribution'),
            'provenance' => ['series_owner_reviewed' => $metadata['owner'], 'connector' => 'Laravel HTTP Client + League CSV'],
        ]);

        $url = str_replace('{series}', rawurlencode($seriesId), (string) config('ii.fred.endpoint'));
        $run = IngestionRun::query()->create([
            'source_id' => $source->id,
            'series_id' => $seriesId,
            'status' => 'RUNNING',
            'source_url' => $url,
            'started_at' => now(),
        ]);

        try {
            $response = Http::withHeaders([
                'Accept' => 'text/csv',
                'User-Agent' => 'Investment-Intelligence/laravel-research-connector',
            ])->connectTimeout(5)->timeout(20)->retry(3, 400, throw: false)->get($url);

            if (!$response->successful()) {
                throw new \RuntimeException('FRED returned HTTP '.$response->status().'.');
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > 5_000_000) {
                throw new \RuntimeException('FRED response is empty or exceeds the 5 MB safety limit.');
            }

            $checksum = hash('sha256', $body);
            $csv = Reader::createFromString($body);
            $csv->setHeaderOffset(0);
            $rows = [];
            $received = 0;
            $missing = 0;
            foreach ($csv->getRecords() as $record) {
                $received++;
                $date = trim((string) ($record['observation_date'] ?? $record['DATE'] ?? ''));
                $value = trim((string) ($record[$seriesId] ?? ''));
                if ($value === '' || $value === '.') {
                    $missing++;
                    continue;
                }
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date || !is_numeric($value) || !is_finite((float) $value)) {
                    throw new \RuntimeException('FRED response contains an invalid observation.');
                }
                $rows[$date] = (float) $value;
            }

            if ($received === 0 || count($rows) < 12) {
                throw new \RuntimeException('FRED response has insufficient valid observations.');
            }

            $retrievedAt = now();
            DB::transaction(function () use ($rows, $metadata, $seriesId, $run, $url, $checksum, $retrievedAt, $missing): void {
                foreach ($rows as $date => $value) {
                    EconomicObservation::query()->updateOrCreate([
                        'source_id' => 'FRED',
                        'series_id' => $seriesId,
                        'observed_on' => $date,
                    ], [
                        'value' => $value,
                        'units' => $metadata['units'],
                        'frequency' => $metadata['frequency'],
                        'quality_status' => $missing > 0 ? 'VALID_WITH_SOURCE_GAPS' : 'VALID',
                        'retrieved_at' => $retrievedAt,
                        'ingestion_run_id' => $run->id,
                        'source_url' => $url,
                        'raw_checksum' => $checksum,
                    ]);
                }
            });

            $run->update([
                'status' => 'SUCCEEDED',
                'completed_at' => now(),
                'records_received' => $received,
                'records_written' => count($rows),
                'missing_records' => $missing,
                'response_sha256' => $checksum,
                'quality' => [
                    'valid_observations' => count($rows),
                    'missing_source_values' => $missing,
                    'duplicate_dates_collapsed' => max(0, $received - $missing - count($rows)),
                    'status' => $missing > 0 ? 'VALID_WITH_SOURCE_GAPS' : 'VALID',
                ],
            ]);
            $source->update(['last_success_at' => now()]);
            Cache::forget('ii:macro:dashboard');
            EconomicDataUpdated::dispatch($seriesId, $run->id, count($rows));

            return $run->fresh();
        } catch (\Throwable $exception) {
            $run->update([
                'status' => 'FAILED',
                'completed_at' => now(),
                'failure_reason' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            throw $exception;
        }
    }
}
