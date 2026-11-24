<?php

namespace App\Services;

use App\Exceptions\UnsupportedXbrlTaxonomyException;
use App\Models\IssuerDocument;
use App\Models\IssuerSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IssuerFundamentalImportService
{
    private const METRICS = ['revenue', 'net_income_attributable', 'total_assets', 'total_equity', 'equity_attributable',
        'operating_cash_flow', 'shares_outstanding', 'total_debt', 'cash_and_equivalents', 'ebitda', 'market_price'];

    public function __construct(private readonly XbrlFundamentalParserService $xbrl) {}

    public function import(int $userId, array $metadata, UploadedFile $file): IssuerDocument
    {
        // Defense in depth for callers bypassing the HTTP metadata validator.
        app(ByokIntakeFirewall::class)->assertIndependentSource($metadata);
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xbrl', 'xml', 'pdf'], true)) {
            throw new \InvalidArgumentException('Format yang didukung: CSV, XBRL/XML, atau PDF.');
        }
        $sha256 = hash_file('sha256', $file->getRealPath());
        if (IssuerDocument::where('user_id', $userId)->where('sha256', $sha256)->exists()) {
            throw new \InvalidArgumentException('Dokumen dengan checksum yang sama sudah tersimpan; versi lama tidak ditimpa.');
        }
        if (isset($metadata['restates_document_id']) && ! IssuerDocument::where('id', $metadata['restates_document_id'])
            ->where('user_id', $userId)->where('issuer_code', strtoupper($metadata['ticker']))->exists()) {
            throw new \InvalidArgumentException('Dokumen yang dinyatakan direstate harus milik pengguna dan emiten yang sama.');
        }

        $xbrlValidation = ['status' => 'NOT_APPLICABLE', 'taxonomy_namespaces' => [], 'mapping_report' => [], 'warnings' => []];
        if (in_array($extension, ['xbrl', 'xml'], true)) {
            if ((int) $metadata['multiplier'] !== 1) {
                throw new \InvalidArgumentException('Fakta XBRL harus memakai satuan asli (multiplier 1); scaling berasal dari unitRef/decimals.');
            }
            try {
                $parsed = $this->xbrl->parse($file->getRealPath(), $metadata['currency']);
                $periods = $parsed['periods'];
                $xbrlValidation = array_diff_key($parsed, ['periods' => true]);
            } catch (UnsupportedXbrlTaxonomyException $exception) {
                $periods = [];
                $xbrlValidation = ['status' => 'UNSUPPORTED_HOLD', 'taxonomy_namespaces' => [], 'mapping_report' => [], 'warnings' => [$exception->getMessage()]];
            }
        } else {
            $periods = $extension === 'csv' ? $this->parseCsv($file->getRealPath()) : [];
        }
        $quality = $this->quality($periods, $xbrlValidation['status']);
        $id = (string) Str::uuid();
        $path = 'ii_issuer_documents/u'.$userId.'/'.$id.'.'.$extension;
        if (! Storage::disk('local')->put($path, file_get_contents($file->getRealPath()))) {
            throw new \RuntimeException('Dokumen sumber tidak dapat disimpan.');
        }

        try {
            return DB::transaction(function () use ($userId, $metadata, $file, $extension, $sha256, $periods, $quality, $id, $path, $xbrlValidation): IssuerDocument {
                $sourceKey = hash('sha256', json_encode([
                    $metadata['source_name'], $metadata['source_url'] ?? null, $metadata['terms_url'] ?? null,
                    $metadata['license_status'], $metadata['usage_rights'], (bool) ($metadata['redistribution_allowed'] ?? false),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                $source = IssuerSource::firstOrCreate([
                    'user_id' => $userId, 'source_key' => $sourceKey,
                ], [
                    'name' => $metadata['source_name'], 'source_url' => $metadata['source_url'] ?? null,
                    'publisher' => $metadata['publisher'] ?? null,
                    'terms_url' => $metadata['terms_url'] ?? null,
                    'license_status' => $metadata['license_status'],
                    'usage_rights' => $metadata['usage_rights'],
                    'redistribution_allowed' => (bool) ($metadata['redistribution_allowed'] ?? false),
                ]);
                $document = IssuerDocument::create([
                    'id' => $id, 'user_id' => $userId, 'source_id' => $source->id,
                    'issuer_code' => strtoupper($metadata['ticker']), 'issuer_name' => $metadata['issuer_name'],
                    'sector' => $metadata['sector'] ?? 'Unclassified',
                    'report_period' => $metadata['report_period'], 'fiscal_period_type' => $metadata['fiscal_period_type'] ?? 'annual',
                    'report_date' => $metadata['report_date'],
                    'currency' => $metadata['currency'], 'unit_multiplier' => (int) $metadata['multiplier'],
                    'consolidated' => (bool) ($metadata['consolidated'] ?? false),
                    'format' => $extension === 'xml' ? 'xbrl' : $extension,
                    'original_filename' => basename($file->getClientOriginalName()), 'storage_path' => $path,
                    'sha256' => $sha256, 'restates_document_id' => $metadata['restates_document_id'] ?? null,
                    'quality_status' => $quality['status'], 'quality_score' => $quality['score'],
                    'source_authenticity_status' => strtoupper($metadata['source_authenticity_status'] ?? 'UNVERIFIED'),
                    'numeric_reconciliation_status' => strtoupper($metadata['numeric_reconciliation_status'] ?? 'UNRECONCILED'),
                    'licensing_assessment' => $this->licensingAssessment($metadata['usage_rights']),
                    'provenance_confidence' => strtoupper($metadata['provenance_confidence'] ?? 'LOW'),
                    'conflict_status' => 'NONE', 'taxonomy_status' => $xbrlValidation['status'],
                    'taxonomy_namespaces' => $xbrlValidation['taxonomy_namespaces'],
                    'provenance' => [
                        'source_url' => $metadata['source_url'] ?? null, 'terms_url' => $metadata['terms_url'] ?? null,
                        'license_status' => $metadata['license_status'], 'usage_rights' => $metadata['usage_rights'],
                        'redistribution_allowed' => (bool) ($metadata['redistribution_allowed'] ?? false),
                        'sha256' => $sha256, 'original_filename' => basename($file->getClientOriginalName()),
                        'reported_by_user' => true, 'xbrl_validation' => $xbrlValidation,
                        'metric_definitions' => $this->metricDefinitions(),
                    ],
                    'imported_at' => now(),
                ]);
                foreach ($periods as $period) {
                    $normalized = $this->normalizePeriod($period, (int) $metadata['multiplier']);
                    $document->periods()->create($normalized);
                }

                $this->assessConflicts($document);

                // Commit source + normalized transform lineage atomically with
                // the imported document and periods. No raw content copied to
                // the registry; only independently supplied SHA-256 digests.
                $lineage = app(LocalLineageRegistry::class);
                $sourceNode = $lineage->registerSource(
                    $userId, 'issuer_document', $id, $sha256, $metadata
                );
                $normalizedPayloadHash = hash('sha256', $document->periods()
                    ->orderBy('fiscal_year')->orderBy('period_end')->get()
                    ->map(fn ($period) => $period->getAttributes())->toJson(JSON_THROW_ON_ERROR));
                $lineage->registerTransformation(
                    $userId, 'issuer_document', $id, 'NORMALIZE_ISSUER_PERIODS_V1',
                    $normalizedPayloadHash, [$sourceNode['id']]
                );

                return $document->load(['source', 'periods']);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('CSV tidak dapat dibaca.');
        }
        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                throw new \InvalidArgumentException('CSV kosong.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(fn ($v) => strtolower(trim((string) $v)), $header);
            $allowed = array_merge(['fiscal_year', 'year', 'period_end', 'restated', 'market_price_as_of'], self::METRICS);
            if (! in_array('fiscal_year', $header, true) && ! in_array('year', $header, true)) {
                throw new \InvalidArgumentException('CSV wajib memiliki fiscal_year (atau year).');
            }
            if (array_diff($header, $allowed)) {
                throw new \InvalidArgumentException('CSV memuat kolom yang tidak dikenal.');
            }
            $rows = [];
            $seen = [];
            while (($line = fgetcsv($handle)) !== false) {
                if (count($line) === 1 && trim((string) $line[0]) === '') {
                    continue;
                }
                if (count($line) !== count($header)) {
                    throw new \InvalidArgumentException('Jumlah kolom CSV tidak konsisten.');
                }
                $row = array_combine($header, $line);
                $year = trim((string) ($row['fiscal_year'] ?? $row['year'] ?? ''));
                if (! preg_match('/^(19|20)\d{2}$/', $year) || isset($seen[$year])) {
                    throw new \InvalidArgumentException('Tahun fiskal tidak valid atau duplikat: '.$year);
                }
                $seen[$year] = true;
                $parsed = ['fiscal_year' => (int) $year, 'period_end' => trim((string) ($row['period_end'] ?? '')) ?: $year.'-12-31',
                    'restated' => filter_var($row['restated'] ?? false, FILTER_VALIDATE_BOOL)];
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $parsed['period_end']);
                if (! $date || $date->format('Y-m-d') !== $parsed['period_end']) {
                    throw new \InvalidArgumentException('period_end harus berformat YYYY-MM-DD.');
                }
                foreach (self::METRICS as $metric) {
                    $value = trim((string) ($row[$metric] ?? ''));
                    if ($value === '') {
                        $parsed[$metric] = null;

                        continue;
                    }
                    if (! is_numeric($value) || ! is_finite((float) $value)) {
                        throw new \InvalidArgumentException($metric.' harus berupa angka desimal tanpa pemisah ribuan.');
                    }
                    $parsed[$metric] = (float) $value;
                }
                if (($parsed['shares_outstanding'] ?? 1) <= 0 || ($parsed['total_assets'] ?? 1) <= 0) {
                    throw new \InvalidArgumentException('Assets dan saham beredar, bila tersedia, harus positif.');
                }
                if (($parsed['market_price'] ?? 1) <= 0 || ($parsed['total_debt'] ?? 0) < 0 || ($parsed['cash_and_equivalents'] ?? 0) < 0) {
                    throw new \InvalidArgumentException('Harga harus positif; utang dan kas tidak boleh negatif.');
                }
                $priceAsOf = trim((string) ($row['market_price_as_of'] ?? ''));
                if (($parsed['market_price'] ?? null) !== null && ! $this->validDate($priceAsOf)) {
                    throw new \InvalidArgumentException('market_price wajib memiliki market_price_as_of berformat YYYY-MM-DD.');
                }
                if (($parsed['market_price'] ?? null) === null && $priceAsOf !== '') {
                    throw new \InvalidArgumentException('market_price_as_of tidak boleh diisi tanpa market_price.');
                }
                $parsed['market_price_as_of'] = $priceAsOf !== '' ? $priceAsOf : null;
                $rows[] = $parsed;
                if (count($rows) > 20) {
                    throw new \InvalidArgumentException('Maksimal 20 periode per impor.');
                }
            }
            if ($rows === []) {
                throw new \InvalidArgumentException('CSV tidak memuat periode data.');
            }
            usort($rows, fn ($a, $b) => [$a['fiscal_year'], $a['period_end']] <=> [$b['fiscal_year'], $b['period_end']]);

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function normalizePeriod(array $period, int $multiplier): array
    {
        $available = [];
        foreach (self::METRICS as $metric) {
            $value = $period[$metric] ?? null;
            if ($value !== null) {
                $available[] = $metric;
            }
            $period[$metric] = $value === null ? null : (string) ($metric === 'shares_outstanding' || $metric === 'market_price' ? $value : $value * $multiplier);
        }
        $period['available_fields'] = $available;

        return array_intersect_key($period, array_flip(array_merge(['fiscal_year', 'period_end', 'restated', 'market_price_as_of', 'available_fields'], self::METRICS)));
    }

    private function quality(array $periods, string $xbrlStatus): array
    {
        if ($periods === []) {
            return ['status' => $xbrlStatus === 'UNSUPPORTED_HOLD' ? 'UNSUPPORTED_XBRL' : 'DOCUMENT_ONLY', 'score' => 0];
        }
        $required = ['revenue', 'net_income_attributable', 'total_assets', 'equity_attributable', 'operating_cash_flow', 'shares_outstanding'];
        $latest = end($periods);
        $present = count(array_filter($required, fn ($field) => ($latest[$field] ?? null) !== null));
        $score = (int) round(100 * $present / count($required));

        return ['status' => $present === count($required) ? 'COMPLETE' : 'PARTIAL', 'score' => $score];
    }

    private function licensingAssessment(string $usageRights): string
    {
        return match ($usageRights) {
            'internal_research' => 'INTERNAL_RESEARCH_ONLY',
            'licensed_internal' => 'LICENSED_INTERNAL',
            'redistribution_permitted' => 'REDISTRIBUTION_DECLARED',
            default => 'UNVERIFIED',
        };
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function metricDefinitions(): array
    {
        return [
            'revenue' => 'Consolidated revenue for the reporting period.',
            'net_income_attributable' => 'Profit attributable to owners of the parent.',
            'total_assets' => 'Consolidated total assets at period end.',
            'total_equity' => 'Consolidated total equity including non-controlling interests at period end.',
            'equity_attributable' => 'Equity attributable to owners of the parent at period end.',
            'operating_cash_flow' => 'Net cash provided by operating activities.',
            'shares_outstanding' => 'Issued and fully paid shares outstanding at period end.',
            'total_debt' => 'Total financing debt including bank loans, bonds/other borrowings and lease liabilities.',
            'cash_and_equivalents' => 'Cash and cash equivalents at period end.',
            'ebitda' => 'Issuer-reported EBITDA for the reporting period.',
            'market_price' => 'Observed market price per share on market_price_as_of; never inferred.',
        ];
    }

    private function assessConflicts(IssuerDocument $document): void
    {
        if ($document->periods()->doesntExist()) {
            return;
        }
        $previous = IssuerDocument::with('periods')->where('user_id', $document->user_id)
            ->where('issuer_code', $document->issuer_code)->where('report_period', $document->report_period)
            ->where('id', '!=', $document->id)->get();
        if ($previous->isEmpty()) {
            return;
        }
        $metrics = array_merge(self::METRICS, ['total_equity']);
        $conflicts = [];
        $overlapFound = false;
        foreach ($previous as $older) {
            foreach ($document->periods as $currentPeriod) {
                $oldPeriod = $older->periods->first(fn ($row) => $row->fiscal_year === $currentPeriod->fiscal_year
                    && $row->period_end->toDateString() === $currentPeriod->period_end->toDateString());
                if ($oldPeriod === null) {
                    continue;
                }
                $overlapFound = true;
                foreach ($metrics as $metric) {
                    if ($oldPeriod->{$metric} !== $currentPeriod->{$metric}) {
                        $conflicts[] = [
                            'prior_document_id' => $older->id, 'period_end' => $currentPeriod->period_end->toDateString(),
                            'metric' => $metric, 'prior_value' => $oldPeriod->{$metric}, 'current_value' => $currentPeriod->{$metric},
                        ];
                    }
                }
            }
        }
        if ($conflicts === []) {
            if ($overlapFound) {
                $document->update(['conflict_status' => 'CONSISTENT_VERSION', 'conflict_details' => []]);
            }

            return;
        }
        $conflicts = array_slice($conflicts, 0, 100);
        $document->update(['conflict_status' => 'VALUE_CONFLICT', 'conflict_details' => $conflicts]);
        $priorIds = collect($conflicts)->pluck('prior_document_id')->unique();
        foreach ($previous->whereIn('id', $priorIds) as $older) {
            $details = $older->conflict_details ?? [];
            $details[] = ['conflicting_document_id' => $document->id, 'detected_at' => now()->toIso8601String()];
            $older->update(['conflict_status' => 'VALUE_CONFLICT', 'conflict_details' => array_slice($details, -100)]);
        }
    }
}
