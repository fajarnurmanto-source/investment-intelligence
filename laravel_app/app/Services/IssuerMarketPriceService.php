<?php

namespace App\Services;

use App\Models\IssuerMarketPrice;
use Illuminate\Support\Facades\Validator;

class IssuerMarketPriceService
{
    public function store(int $userId, array $input, ?string $batchSha256 = null, ?string $batchOriginalFilename = null): IssuerMarketPrice
    {
        $data = Validator::make($input, [
            'ticker' => ['required', 'string', 'regex:/^[A-Za-z0-9.\-]{1,16}$/'],
            'observed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'currency' => ['required', 'in:IDR,USD'],
            'price' => ['required', 'numeric', 'gt:0'], 'unit' => ['required', 'in:per_share'],
            'adjustment_status' => ['required', 'in:unadjusted,unadjusted_close,split_adjusted'],
            'source_name' => ['required', 'string', 'max:160'], 'publisher' => ['nullable', 'string', 'max:160'],
            'source_url' => ['required', 'url', 'max:1000'],
            'license_status' => ['required', 'in:public_disclosure,licensed,user_provided'],
            'usage_rights' => ['required', 'in:internal_research,licensed_internal,redistribution_permitted'],
            'source_authenticity_status' => ['required', 'in:official_publisher,official_exchange,licensed_provider,user_attested'],
            'provenance_confidence' => ['required', 'in:high,medium,low'],
            'redistribution_allowed' => ['nullable', 'boolean'], 'rights_confirmed' => ['accepted'],
        ])->validate();
        if (($data['redistribution_allowed'] ?? false) && $data['usage_rights'] !== 'redistribution_permitted') {
            throw new \InvalidArgumentException('Harga tidak boleh ditandai dapat didistribusikan tanpa hak redistribusi.');
        }
        $payload = [
            'ticker' => strtoupper($data['ticker']), 'observed_on' => $data['observed_on'],
            'currency' => $data['currency'], 'price' => (string) $data['price'], 'unit' => $data['unit'],
            'adjustment_status' => $data['adjustment_status'], 'source_name' => $data['source_name'],
            'publisher' => $data['publisher'] ?? null, 'source_url' => $data['source_url'],
            'license_status' => $data['license_status'], 'usage_rights' => $data['usage_rights'],
            'source_authenticity_status' => strtoupper($data['source_authenticity_status']),
            'provenance_confidence' => strtoupper($data['provenance_confidence']),
            'redistribution_allowed' => (bool) ($data['redistribution_allowed'] ?? false),
        ];
        $sha256 = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (IssuerMarketPrice::where('user_id', $userId)->where('sha256', $sha256)->exists()) {
            throw new \InvalidArgumentException('Observasi harga identik sudah tersimpan.');
        }

        return IssuerMarketPrice::create([
            'user_id' => $userId, 'issuer_code' => $payload['ticker'], 'observed_on' => $payload['observed_on'],
            'currency' => $payload['currency'], 'price' => $payload['price'], 'unit' => $payload['unit'],
            'adjustment_status' => strtoupper($payload['adjustment_status']), 'source_name' => $payload['source_name'],
            'publisher' => $payload['publisher'], 'source_url' => $payload['source_url'],
            'license_status' => $payload['license_status'], 'usage_rights' => $payload['usage_rights'],
            'source_authenticity_status' => $payload['source_authenticity_status'],
            'provenance_confidence' => $payload['provenance_confidence'],
            'redistribution_allowed' => $payload['redistribution_allowed'], 'sha256' => $sha256,
            'batch_sha256' => $batchSha256, 'batch_original_filename' => $batchOriginalFilename,
            'imported_at' => now(),
        ]);
    }
}
