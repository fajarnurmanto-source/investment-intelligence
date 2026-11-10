<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

class IssuerImportMetadataService
{
    public function validate(array $input): array
    {
        $data = Validator::make($input, self::rules())->validate();
        $data['ticker'] = strtoupper($data['ticker']);
        $data['sector'] = trim($data['sector'] ?? 'Unclassified') ?: 'Unclassified';
        $data['fiscal_period_type'] = $data['fiscal_period_type'] ?? 'annual';
        if (($data['kind'] === 'synthetic') !== str_starts_with($data['ticker'], 'DEMO')) {
            throw new \InvalidArgumentException('Ticker DEMO hanya untuk data sintetis; data sintetis wajib menggunakan ticker DEMO.');
        }
        if (($data['redistribution_allowed'] ?? false) && $data['usage_rights'] !== 'redistribution_permitted') {
            throw new \InvalidArgumentException('Redistribusi hanya boleh ditandai bila hak redistribusi dinyatakan.');
        }

        return $data;
    }

    public static function rules(): array
    {
        return [
            'ticker' => ['required', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'issuer_name' => ['required', 'string', 'max:200'],
            'sector' => ['nullable', 'string', 'max:80'],
            'kind' => ['required', 'in:user_supplied,synthetic'],
            'report_period' => ['required', 'string', 'max:40'],
            'restates_document_id' => ['nullable', 'uuid'],
            'fiscal_period_type' => ['nullable', 'in:annual,quarterly,half_year,trailing_twelve_months'],
            'report_date' => ['required', 'date'],
            'currency' => ['required', 'in:IDR,USD'],
            'multiplier' => ['required', 'in:1,1000,1000000,1000000000'],
            'consolidated' => ['nullable', 'boolean'],
            'source_name' => ['required', 'string', 'max:160'],
            'publisher' => ['nullable', 'string', 'max:160'],
            'source_url' => ['nullable', 'url', 'max:1000'],
            'terms_url' => ['nullable', 'url', 'max:1000'],
            'license_status' => ['required', 'in:public_disclosure,licensed,user_provided,unknown'],
            'usage_rights' => ['required', 'in:internal_research,licensed_internal,redistribution_permitted,unknown'],
            'source_authenticity_status' => ['required', 'in:official_publisher,licensed_provider,user_attested,unverified'],
            'numeric_reconciliation_status' => ['required', 'in:verified_against_source,user_attested,unreconciled'],
            'provenance_confidence' => ['required', 'in:high,medium,low'],
            'redistribution_allowed' => ['nullable', 'boolean'],
            'rights_confirmed' => ['accepted'],
        ];
    }
}
