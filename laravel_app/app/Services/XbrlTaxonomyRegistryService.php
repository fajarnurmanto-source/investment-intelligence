<?php

namespace App\Services;

/** Auditable allow-list. Issuer extensions and dimensions remain HOLD until explicitly mapped. */
class XbrlTaxonomyRegistryService
{
    private const IFRS_NAMESPACE = '~^https?://xbrl\.ifrs\.org/taxonomy/(20\d{2}-\d{2}-\d{2})/ifrs-full$~';

    /** Metric, expected context period, expected unit kind. */
    private const IFRS_MAP = [
        'Revenue' => ['revenue', 'duration', 'monetary'],
        'Revenues' => ['revenue', 'duration', 'monetary'],
        'RevenueFromContractsWithCustomers' => ['revenue', 'duration', 'monetary'],
        'ProfitLossAttributableToOwnersOfParent' => ['net_income_attributable', 'duration', 'monetary'],
        'Assets' => ['total_assets', 'instant', 'monetary'],
        'Equity' => ['total_equity', 'instant', 'monetary'],
        'EquityAttributableToOwnersOfParent' => ['equity_attributable', 'instant', 'monetary'],
        'CashFlowsFromUsedInOperatingActivities' => ['operating_cash_flow', 'duration', 'monetary'],
        'NumberOfSharesOutstanding' => ['shares_outstanding', 'instant', 'shares'],
        'Borrowings' => ['total_debt', 'instant', 'monetary'],
        'CashAndCashEquivalents' => ['cash_and_equivalents', 'instant', 'monetary'],
        'EBITDA' => ['ebitda', 'duration', 'monetary'],
    ];

    public function resolve(string $namespace, string $localName): ?array
    {
        if (! preg_match(self::IFRS_NAMESPACE, $namespace, $match)) {
            return null;
        }
        $mapping = self::IFRS_MAP[$localName] ?? null;
        if ($mapping === null) {
            return null;
        }

        return ['taxonomy' => 'IFRS_FULL', 'release' => $match[1], 'mapping' => $mapping];
    }

    public function isSupportedNamespace(string $namespace): bool
    {
        return (bool) preg_match(self::IFRS_NAMESPACE, $namespace);
    }
}
