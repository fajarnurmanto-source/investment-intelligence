<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

class XbrlFundamentalParserService
{
    private const MAP = [
        'Revenue' => 'revenue',
        'Revenues' => 'revenue',
        'RevenueFromContractsWithCustomers' => 'revenue',
        'ProfitLossAttributableToOwnersOfParent' => 'net_income_attributable',
        'ProfitLoss' => 'net_income_attributable',
        'Assets' => 'total_assets',
        'EquityAttributableToOwnersOfParent' => 'equity_attributable',
        'Equity' => 'equity_attributable',
        'CashFlowsFromUsedInOperatingActivities' => 'operating_cash_flow',
        'NumberOfSharesOutstanding' => 'shares_outstanding',
        'Borrowings' => 'total_debt',
        'CashAndCashEquivalents' => 'cash_and_equivalents',
        'EBITDA' => 'ebitda',
    ];

    public function parse(string $path): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->load($path, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new \InvalidArgumentException('XBRL tidak valid.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($dom);
        $contexts = [];
        foreach ($xpath->query('//*[local-name()="context"]') ?: [] as $context) {
            if (! $context instanceof DOMElement || $context->getAttribute('id') === '') {
                continue;
            }
            $end = $xpath->evaluate('string(.//*[local-name()="endDate" or local-name()="instant"][last()])', $context);
            if ($end !== '') {
                $contexts[$context->getAttribute('id')] = $end;
            }
        }

        $periods = [];
        foreach ($xpath->query('//*[@contextRef]') ?: [] as $fact) {
            if (! $fact instanceof DOMElement) {
                continue;
            }
            $metric = self::MAP[$fact->localName] ?? null;
            $end = $contexts[$fact->getAttribute('contextRef')] ?? null;
            $raw = trim($fact->textContent);
            if (! $metric || ! $end || ! is_numeric($raw)) {
                continue;
            }
            $year = (int) substr($end, 0, 4);
            $periods[$year] ??= ['fiscal_year' => $year, 'period_end' => $end, 'restated' => false];
            if (! array_key_exists($metric, $periods[$year])) {
                $periods[$year][$metric] = (float) $raw;
            }
        }
        if ($periods === []) {
            throw new \InvalidArgumentException('Tidak ada fakta XBRL IFRS yang dapat dipetakan. Gunakan CSV normalisasi untuk taxonomy yang belum didukung.');
        }
        ksort($periods);

        return array_values($periods);
    }
}
