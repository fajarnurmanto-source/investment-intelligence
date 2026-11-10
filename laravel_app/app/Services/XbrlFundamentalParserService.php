<?php

namespace App\Services;

use App\Exceptions\UnsupportedXbrlTaxonomyException;
use DOMDocument;
use DOMElement;
use DOMXPath;

class XbrlFundamentalParserService
{
    private const XBRLI = 'http://www.xbrl.org/2003/instance';

    private const XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private const ISO4217 = 'http://www.xbrl.org/2003/iso4217';

    public function __construct(private readonly XbrlTaxonomyRegistryService $taxonomies) {}

    /** @return array{status:string, periods:array, taxonomy_namespaces:array, mapping_report:array, warnings:array} */
    public function parse(string $path, string $expectedCurrency): array
    {
        $xml = file_get_contents($path);
        if ($xml === false || $xml === '' || strlen($xml) > 20 * 1024 * 1024) {
            throw new \InvalidArgumentException('XBRL kosong atau melebihi batas 20MB.');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new \InvalidArgumentException('XBRL dengan DTD atau entity eksternal ditolak.');
        }

        $dom = new DOMDocument;
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOBLANKS)) {
                throw new \InvalidArgumentException('XBRL tidak valid.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $dom->documentElement instanceof DOMElement
            || $dom->documentElement->namespaceURI !== self::XBRLI
            || $dom->documentElement->localName !== 'xbrl') {
            throw new \InvalidArgumentException('Root dokumen bukan instance XBRL 2.1.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('xbrli', self::XBRLI);
        $units = $this->units($xpath);
        $contexts = $this->contexts($xpath);
        $periods = [];
        $seenFacts = [];
        $periodContexts = [];
        $entities = [];
        $namespaces = [];
        $unsupportedNamespaces = [];
        $warnings = [];
        $mappingReport = [];

        $facts = $xpath->query('//*[@contextRef]');
        if ($facts === false || $facts->length > 50000) {
            throw new \InvalidArgumentException('Jumlah fakta XBRL tidak wajar.');
        }
        foreach ($facts as $fact) {
            if (! $fact instanceof DOMElement) {
                continue;
            }
            $resolved = $this->taxonomies->resolve((string) $fact->namespaceURI, $fact->localName);
            if ($resolved === null) {
                if ($this->taxonomies->isSupportedNamespace((string) $fact->namespaceURI)) {
                    continue;
                }
                $unsupportedNamespaces[(string) $fact->namespaceURI] = true;

                continue;
            }
            $namespaces[(string) $fact->namespaceURI] = true;
            [$metric, $expectedPeriodType, $unitKind] = $resolved['mapping'];
            $mappingReport[$metric] = [
                'taxonomy' => $resolved['taxonomy'], 'release' => $resolved['release'],
                'concept' => $fact->localName, 'period_type' => $expectedPeriodType, 'unit_kind' => $unitKind,
            ];
            $contextId = $fact->getAttribute('contextRef');
            $context = $contexts[$contextId] ?? null;
            if ($context === null) {
                throw new \InvalidArgumentException('Fakta XBRL mengacu pada contextRef yang tidak valid: '.$contextId);
            }
            if ($context['dimensioned']) {
                $warnings['dimensioned_contexts_held'] = true;

                continue;
            }
            if ($context['type'] !== $expectedPeriodType) {
                throw new \InvalidArgumentException($fact->localName.' memakai tipe periode yang tidak sesuai.');
            }
            $entities[$context['entity_key']] = true;
            $end = $context['end'];
            if (isset($periodContexts[$end][$expectedPeriodType]) && $periodContexts[$end][$expectedPeriodType] !== $contextId) {
                throw new \InvalidArgumentException('Fakta periode '.$end.' berasal dari konteks '.$expectedPeriodType.' yang berbeda.');
            }
            $periodContexts[$end][$expectedPeriodType] = $contextId;
            [$value, $accuracy] = $this->numericFact($fact, $units, $unitKind, strtoupper($expectedCurrency));
            $factKey = $end.'|'.$metric;
            if (isset($seenFacts[$factKey])) {
                if ($seenFacts[$factKey]['value'] !== $value || $seenFacts[$factKey]['accuracy'] !== $accuracy) {
                    throw new \InvalidArgumentException('Fakta XBRL duplikat atau kontradiktif: '.$metric.' '.$end);
                }
                $warnings['identical_duplicate_facts_ignored'] = true;

                continue;
            }
            $seenFacts[$factKey] = ['value' => $value, 'accuracy' => $accuracy];
            $periods[$end] ??= ['fiscal_year' => (int) substr($end, 0, 4), 'period_end' => $end, 'restated' => false];
            $periods[$end][$metric] = $value;
        }

        if (count($entities) > 1) {
            throw new \InvalidArgumentException('XBRL memuat lebih dari satu entitas untuk fakta yang dipilih.');
        }
        if ($periods === []) {
            if ($unsupportedNamespaces !== []) {
                throw new UnsupportedXbrlTaxonomyException('Taxonomy XBRL belum didukung: '.implode(', ', array_keys($unsupportedNamespaces)));
            }
            if (isset($warnings['dimensioned_contexts_held'])) {
                throw new UnsupportedXbrlTaxonomyException('Hanya fakta berdimensi yang tersedia; mapping dimensi belum didukung dan ditahan.');
            }
            throw new \InvalidArgumentException('Tidak ada fakta IFRS non-dimensional yang dapat divalidasi.');
        }
        ksort($periods);

        return [
            'status' => 'VALIDATED',
            'periods' => array_values($periods),
            'taxonomy_namespaces' => array_keys($namespaces),
            'mapping_report' => $mappingReport,
            'warnings' => array_keys($warnings),
        ];
    }

    private function contexts(DOMXPath $xpath): array
    {
        $contexts = [];
        foreach ($xpath->query('//xbrli:context') ?: [] as $context) {
            if (! $context instanceof DOMElement || ($id = $context->getAttribute('id')) === '' || isset($contexts[$id])) {
                throw new \InvalidArgumentException('Context XBRL tanpa ID unik.');
            }
            $identifiers = $xpath->query('./xbrli:entity/xbrli:identifier', $context);
            if ($identifiers === false || $identifiers->length !== 1 || trim($identifiers->item(0)?->textContent ?? '') === '') {
                throw new \InvalidArgumentException('Context XBRL tidak memiliki identifier entitas tunggal.');
            }
            /** @var DOMElement $identifier */
            $identifier = $identifiers->item(0);
            if ($identifier->getAttribute('scheme') === '') {
                throw new \InvalidArgumentException('Identifier entitas XBRL tidak memiliki scheme.');
            }
            $dimensioned = ($xpath->query('./xbrli:entity/xbrli:segment/* | ./xbrli:scenario/*', $context)?->length ?? 0) > 0;
            $instants = $xpath->query('./xbrli:period/xbrli:instant', $context);
            $starts = $xpath->query('./xbrli:period/xbrli:startDate', $context);
            $ends = $xpath->query('./xbrli:period/xbrli:endDate', $context);
            if ($instants?->length === 1 && $starts?->length === 0 && $ends?->length === 0) {
                $type = 'instant';
                $start = null;
                $end = trim($instants->item(0)->textContent);
            } elseif ($instants?->length === 0 && $starts?->length === 1 && $ends?->length === 1) {
                $type = 'duration';
                $start = trim($starts->item(0)->textContent);
                $end = trim($ends->item(0)->textContent);
            } else {
                throw new \InvalidArgumentException('Periode context XBRL harus instant atau startDate/endDate tunggal.');
            }
            if (! $this->validDate($end) || ($start !== null && (! $this->validDate($start) || $start > $end))) {
                throw new \InvalidArgumentException('Tanggal periode context XBRL tidak valid.');
            }
            $contexts[$id] = [
                'type' => $type, 'start' => $start, 'end' => $end, 'dimensioned' => $dimensioned,
                'entity_key' => $identifier->getAttribute('scheme').'|'.trim($identifier->textContent),
            ];
        }

        return $contexts;
    }

    private function units(DOMXPath $xpath): array
    {
        $units = [];
        foreach ($xpath->query('//xbrli:unit') ?: [] as $unit) {
            if (! $unit instanceof DOMElement || ($id = $unit->getAttribute('id')) === '' || isset($units[$id])) {
                throw new \InvalidArgumentException('Unit XBRL tanpa ID unik.');
            }
            $measures = $xpath->query('./xbrli:measure', $unit);
            if ($measures === false || $measures->length !== 1) {
                throw new \InvalidArgumentException('Hanya unit XBRL dengan satu measure yang didukung.');
            }
            /** @var DOMElement $measure */
            $measure = $measures->item(0);
            $qname = trim($measure->textContent);
            [$prefix, $local] = str_contains($qname, ':') ? explode(':', $qname, 2) : ['', $qname];
            $namespace = $prefix === '' ? $measure->lookupNamespaceURI(null) : $measure->lookupNamespaceURI($prefix);
            $units[$id] = ['namespace' => $namespace, 'local' => $local];
        }

        return $units;
    }

    private function numericFact(DOMElement $fact, array $units, string $unitKind, string $currency): array
    {
        $unitId = $fact->getAttribute('unitRef');
        $unit = $units[$unitId] ?? null;
        if ($unitId === '' || $unit === null) {
            throw new \InvalidArgumentException($fact->localName.' tidak memiliki unitRef yang valid.');
        }
        if ($unitKind === 'monetary' && ($unit['namespace'] !== self::ISO4217 || strtoupper($unit['local']) !== $currency)) {
            throw new \InvalidArgumentException($fact->localName.' tidak memakai unit mata uang '.$currency.'.');
        }
        if ($unitKind === 'shares' && ($unit['namespace'] !== self::XBRLI || strtolower($unit['local']) !== 'shares')) {
            throw new \InvalidArgumentException($fact->localName.' tidak memakai unit saham XBRL.');
        }
        $decimals = $fact->getAttribute('decimals');
        $precision = $fact->getAttribute('precision');
        if (($decimals === '') === ($precision === '')) {
            throw new \InvalidArgumentException($fact->localName.' wajib memiliki tepat satu decimals atau precision.');
        }
        $accuracy = $decimals !== '' ? 'decimals:'.$decimals : 'precision:'.$precision;
        $validAccuracy = $decimals !== '' ? preg_match('/^(INF|-?\d+)$/', $decimals) : preg_match('/^(INF|[1-9]\d*)$/', $precision);
        if (! $validAccuracy) {
            throw new \InvalidArgumentException($fact->localName.' memiliki decimals/precision tidak valid.');
        }
        $nil = strtolower($fact->getAttributeNS(self::XSI, 'nil'));
        if (in_array($nil, ['true', '1'], true)) {
            if (trim($fact->textContent) !== '') {
                throw new \InvalidArgumentException($fact->localName.' bertanda nil tetapi memiliki nilai.');
            }

            return [null, $accuracy];
        }
        $raw = trim($fact->textContent);
        if ($raw === '' || ! is_numeric($raw) || ! is_finite((float) $raw)) {
            throw new \InvalidArgumentException($fact->localName.' bukan fakta numerik valid.');
        }

        return [(float) $raw, $accuracy];
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
