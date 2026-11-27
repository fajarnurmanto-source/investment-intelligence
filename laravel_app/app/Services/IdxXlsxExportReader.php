<?php

namespace App\Services;

/** Restricted OOXML reader for reviewed tabular exports. Never extracts ZIP paths,
 * resolves external relationships, executes formulas or uses cached formula values. */
final class IdxXlsxExportReader
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public function read(string $raw, mixed $sheet, mixed $headerRow): array
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class)) {
            throw new \RuntimeException('II_IDX_XLSX_EXTENSION_REQUIRED');
        }
        if (!is_string($sheet) || $sheet === '' || strlen($sheet) > 100
            || !is_int($headerRow) || $headerRow < 1 || $headerRow > 30
            || strlen($raw) > IdxOfficialExportIntake::MAX_BYTES) {
            throw new \InvalidArgumentException('II_IDX_XLSX_REVIEW_INVALID');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ii-xlsx-');
        if ($tmp === false) {
            throw new \RuntimeException('II_IDX_XLSX_TEMP_FAILED');
        }
        $zip = new \ZipArchive();
        $opened = false;
        try {
            if (file_put_contents($tmp, $raw) !== strlen($raw) || $zip->open($tmp, \ZipArchive::RDONLY) !== true) {
                throw new \InvalidArgumentException('II_IDX_XLSX_ARCHIVE_INVALID');
            }
            $opened = true;
            $size = 0;
            $names = [];
            if ($zip->numFiles > 256) {
                throw new \InvalidArgumentException('II_IDX_XLSX_ARCHIVE_LIMIT');
            }
            for ($i=0; $i<$zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false || isset($names[$stat['name']]) || $stat['size'] > 30_000_000
                    || ($size += $stat['size']) > 60_000_000
                    || ($stat['encryption_method'] ?? 0) !== 0
                    || preg_match('~(?:^|/)\.\.(?:/|$)|\\\\|^/|vbaProject|externalLinks~i', $stat['name'])) {
                    throw new \InvalidArgumentException('II_IDX_XLSX_ARCHIVE_LIMIT');
                }
                $names[$stat['name']] = true;
            }
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $id = null;
            foreach ($workbook->getElementsByTagNameNS(self::NS, 'sheet') as $node) {
                if ($node->getAttribute('name') === $sheet && $node->getAttribute('state') !== 'hidden'
                    && $node->getAttribute('state') !== 'veryHidden') {
                    $id = $node->getAttributeNS(self::REL, 'id');
                }
            }
            $path = null;
            foreach ($rels->getElementsByTagNameNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationship') as $rel) {
                if ($rel->getAttribute('TargetMode') === 'External') {
                    throw new \InvalidArgumentException('II_IDX_XLSX_EXTERNAL_RELATIONSHIP');
                }
                if ($id !== null && $id !== '' && $rel->getAttribute('Id') === $id
                    && $rel->getAttribute('Type') === self::REL.'/worksheet') {
                    $target = $rel->getAttribute('Target');
                    if (preg_match('~^/?(?:xl/)?worksheets/sheet[0-9]+\.xml$~D', $target)) {
                        $path = str_starts_with(ltrim($target,'/'),'xl/') ? ltrim($target,'/') : 'xl/'.$target;
                    }
                }
            }
            if ($path === null) {
                throw new \InvalidArgumentException('II_IDX_XLSX_SHEET_INVALID');
            }
            $strings = [];
            if (isset($names['xl/sharedStrings.xml'])) {
                foreach ($this->xml($zip,'xl/sharedStrings.xml')->getElementsByTagNameNS(self::NS,'si') as $si) {
                    if (count($strings) >= 100000) {
                        throw new \InvalidArgumentException('II_IDX_XLSX_STRING_LIMIT');
                    }
                    $strings[] = $this->string($si);
                }
            }
            $xml = $this->xml($zip,$path);
            if ($xml->getElementsByTagNameNS(self::NS,'f')->length || $xml->getElementsByTagNameNS(self::NS,'mergeCell')->length) {
                throw new \InvalidArgumentException('II_IDX_XLSX_FORMULA_OR_MERGE');
            }
            $header = [];
            $records = [];
            $previous = 0;
            foreach ($xml->getElementsByTagNameNS(self::NS,'row') as $row) {
                $n = (int)$row->getAttribute('r');
                if ($n <= $previous || $n > 2030) {
                    throw new \InvalidArgumentException('II_IDX_XLSX_ROW_INVALID');
                }
                $previous = $n;
                if ($n < $headerRow) {
                    continue;
                }
                $cells = [];
                foreach ($row->getElementsByTagNameNS(self::NS,'c') as $cell) {
                    if (!preg_match('/^([A-Z]{1,2})([1-9][0-9]*)$/D',$cell->getAttribute('r'),$m)
                        || (int)$m[2] !== $n || isset($cells[$m[1]])) {
                        throw new \InvalidArgumentException('II_IDX_XLSX_CELL_INVALID');
                    }
                    $v = $cell->getElementsByTagNameNS(self::NS,'v')->item(0)?->textContent ?? '';
                    $type = $cell->getAttribute('t');
                    if ($type === 's') {
                        if (!ctype_digit($v) || !isset($strings[(int)$v])) {
                            throw new \InvalidArgumentException('II_IDX_XLSX_STRING_INVALID');
                        }
                        $v = $strings[(int)$v];
                    } elseif ($type === 'inlineStr') {
                        $v = $this->string($cell);
                    } elseif (!in_array($type,['','n'],true)) {
                        throw new \InvalidArgumentException('II_IDX_XLSX_CELL_TYPE_INVALID');
                    }
                    if (strlen($v)>4096) {
                        throw new \InvalidArgumentException('II_IDX_XLSX_CELL_LIMIT');
                    }
                    $cells[$m[1]] = $v;
                }
                if ($n === $headerRow) {
                    $header = array_filter($cells, fn ($v) => trim($v) !== '');
                    if (!$header || count(array_unique($header)) !== count($header)) {
                        throw new \InvalidArgumentException('II_IDX_XLSX_HEADER_INVALID');
                    }
                } elseif (array_filter($cells,fn($v)=>$v!=='')) {
                    if (!$header) {
                        throw new \InvalidArgumentException('II_IDX_XLSX_HEADER_INVALID');
                    }
                    $record = [];
                    foreach ($header as $col=>$name) {
                        $record[$name] = $cells[$col] ?? '';
                    }
                    $records[$n] = $record;
                }
            }
            return [array_values($header),$records];
        } finally {
            if ($opened) {
                $zip->close();
            }
            unlink($tmp);
        }
    }

    private function xml(\ZipArchive $zip,string $path): \DOMDocument
    {
        $raw = $zip->getFromName($path);
        if (!is_string($raw) || strlen($raw)>30_000_000 || str_contains($raw,"\0")
            || preg_match('/<!DOCTYPE|<!ENTITY/i',$raw)) {
            throw new \InvalidArgumentException('II_IDX_XLSX_XML_INVALID');
        }
        $xml = new \DOMDocument();
        if (!@$xml->loadXML($raw, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \InvalidArgumentException('II_IDX_XLSX_XML_INVALID');
        }
        return $xml;
    }

    private function string(\DOMElement $node): string
    {
        $value = '';
        foreach ($node->getElementsByTagNameNS(self::NS,'t') as $text) {
            $value .= $text->textContent;
        }
        return $value;
    }
}
