<?php
/**
 * Minimal .xlsx reader (first worksheet) using only ZipArchive + SimpleXML.
 * Returns an array of rows, each an array of cell values (0-based columns).
 */
function xlsx_read_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is required to read .xlsx files. Save the file as CSV instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Unable to open the Excel file.');
    }
    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml);
        foreach ($sx->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $t = '';
                foreach ($si->r as $r) {
                    $t .= (string)$r->t;
                }
                $shared[] = $t;
            }
        }
    }
    // locate the first sheet via workbook relationships
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $wbx = simplexml_load_string($wb);
        $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheets = $wbx->xpath('//m:sheets/m:sheet');
        if ($sheets) {
            $rid = (string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $rx = simplexml_load_string($rels);
            foreach ($rx->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheetPath = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                }
            }
        }
    }
    $xml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('Worksheet not found in the Excel file.');
    }
    $sx = simplexml_load_string($xml);
    $out = [];
    foreach ($sx->sheetData->row as $r) {
        $cells = [];
        foreach ($r->c as $c) {
            $ref = (string)$c['r'];
            $col = xlsx_col_index(preg_replace('/\d+/', '', $ref));
            $t = (string)$c['t'];
            if ($t === 's') {
                $v = $shared[(int)$c->v] ?? '';
            } elseif ($t === 'inlineStr') {
                $v = (string)$c->is->t;
            } elseif ($t === 'b') {
                $v = ((string)$c->v) === '1' ? 'TRUE' : 'FALSE';
            } else {
                $v = (string)$c->v;
            }
            $cells[$col] = $v;
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $rowVals = [];
            for ($i = 0; $i <= $max; $i++) {
                $rowVals[] = $cells[$i] ?? '';
            }
            $out[] = $rowVals;
        }
    }
    return $out;
}

function xlsx_col_index(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

/** Convert an Excel serial date (e.g. 46023) to Y-m-d. */
function excel_serial_to_date($v): ?string
{
    if (!is_numeric($v)) {
        return null;
    }
    $days = (int)floor((float)$v);
    return date('Y-m-d', (int)round(($days - 25569) * 86400) + 43200);
}

/** Convert an Excel time fraction (e.g. 0.3333) to H:i:s. */
function excel_fraction_to_time($v): ?string
{
    if (!is_numeric($v)) {
        return null;
    }
    $frac = (float)$v - floor((float)$v);
    $secs = (int)round($frac * 86400);
    return sprintf('%02d:%02d:%02d', intdiv($secs, 3600) % 24, intdiv($secs % 3600, 60), $secs % 60);
}
