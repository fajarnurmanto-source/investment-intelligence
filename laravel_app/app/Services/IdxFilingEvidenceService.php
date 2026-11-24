<?php

namespace App\Services;

use App\Models\IssuerDocument;
use Illuminate\Http\UploadedFile;

/**
 * V12 evidence-only intake for user-supplied BEI/IDX filing PDFs.
 *
 * Source URL and document origin are user attestations, NOT independent IDX
 * verification. No crawling, HTTP requests, XBRL numeric extraction, or
 * trading admission. The existing immutable importer owns storage/provenance.
 */
class IdxFilingEvidenceService
{
    private const OFFICIAL_HOSTS = [
        'idx.co.id', 'www.idx.co.id', 'www2.idx.co.id',
        'idx.id', 'www.idx.id', 'www2.idx.id',
    ];

    public function __construct(
        private readonly IssuerFundamentalImportService $importer,
        private readonly IssuerImportMetadataService $metadata,
    ) {}

    public function import(int $userId, array $input, UploadedFile $file): IssuerDocument
    {
        $url = trim((string) $input['idx_source_url']);
        $this->assertOfficialPdfUrl($url);

        if (! $file->isValid() || strtolower($file->getClientOriginalExtension()) !== 'pdf') {
            throw new \InvalidArgumentException('Bukti BEI wajib berupa file PDF yang dapat dibaca.');
        }
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException('Bukti PDF tidak dapat dibaca.');
        }
        try {
            $header = fread($handle, 5);
        } finally {
            fclose($handle);
        }
        if ($header !== '%PDF-') {
            throw new \InvalidArgumentException('Berkas tidak memiliki header PDF; HTML atau berkas palsu tidak diterima.');
        }

        $label = strtoupper(trim((string) $input['idx_report_period']));
        if (! preg_match('/^(FY|Q[1-4]|H[12]) (20\d{2})$/', $label, $match)) {
            throw new \InvalidArgumentException('Periode harus FY 2025, Q1 2025, atau H1 2025.');
        }
        $type = match ($match[1]) {
            'FY' => 'annual',
            'H1', 'H2' => 'half_year',
            default => 'quarterly',
        };
        $ticker = strtoupper(trim((string) $input['idx_ticker']));
        if (str_starts_with($ticker, 'DEMO')) {
            throw new \InvalidArgumentException('Kode DEMO tidak boleh digunakan untuk bukti resmi.');
        }
        if (! str_ends_with($ticker, '.JK')) {
            $ticker .= '.JK';
        }

        // A valid IDX-hosted URL does not prove that the uploaded bytes match.
        // Never declare OFFICIAL_PUBLISHER or VERIFIED_AGAINST_SOURCE here.
        $metadata = $this->metadata->validate([
            'ticker' => $ticker,
            'issuer_name' => trim((string) $input['idx_issuer_name']),
            'sector' => 'Unclassified',
            'kind' => 'user_supplied',
            'report_period' => $label,
            'fiscal_period_type' => $type,
            'report_date' => $input['idx_report_date'],
            'currency' => $input['idx_currency'],
            'multiplier' => '1',
            'consolidated' => (bool) ($input['idx_consolidated'] ?? false),
            'source_name' => 'IDX/BEI filing (user-supplied PDF)',
            'publisher' => 'Indonesia Stock Exchange filing archive (hosting)',
            'source_url' => $url,
            'terms_url' => 'https://www.idx.co.id/id/syarat-penggunaan',
            'license_status' => 'public_disclosure',
            'usage_rights' => 'internal_research',
            'source_authenticity_status' => 'user_attested',
            'numeric_reconciliation_status' => 'unreconciled',
            'provenance_confidence' => 'low',
            'redistribution_allowed' => false,
            'rights_confirmed' => true,
        ]);

        return $this->importer->import($userId, $metadata, $file);
    }

    private function assertOfficialPdfUrl(string $url): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('URL sumber BEI tidak valid.');
        }
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! in_array(strtolower((string) ($parts['host'] ?? '')), self::OFFICIAL_HOSTS, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['fragment'])
            || ! preg_match('/\.pdf$/i', (string) ($parts['path'] ?? ''))) {
            throw new \InvalidArgumentException('Gunakan tautan langsung PDF HTTPS pada domain resmi BEI/IDX (bukan situs perantara).');
        }
    }
}
