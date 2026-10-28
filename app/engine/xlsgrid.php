<?php
/**
 * Spreadsheet grids for the attendance log imports (v22).
 *
 * Some biometric software exports the log as an Excel report instead of a text file, for example the
 * "Employee Attendance Record" of the JJM terminals (one block per employee, the punches of each day
 * under the day number). These helpers read such a file into a plain grid of cell texts:
 * grid[row][col] = string (0-based, empty cells omitted), whatever the file really is:
 *
 *   - Excel 97-2003 .xls (BIFF8 inside an OLE2 compound file), read here without any extension;
 *   - Excel .xlsx (Office Open XML; needs the PHP zip extension);
 *   - an "xls" that is really an HTML table or a tab / comma separated text, as some exports are.
 *
 * Numbers keep their value as text ("15", "0.29375"); the report parser turns day numbers and Excel time
 * fractions into dates and times itself.
 */

/** Read a spreadsheet-like file into grid[row][col] => text. Throws RuntimeException when it cannot be read. */
function grid_read_file(string $path, int $sheet = 0): array
{
    $head = (string)@file_get_contents($path, false, null, 0, 8);
    if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        return xls_read_grid($path, $sheet);
    }
    if (substr($head, 0, 2) === 'PK') {
        return xlsx_read_grid($path, $sheet);
    }
    $text = (string)file_get_contents($path);
    if (stripos($text, '<table') !== false) {
        return html_read_grid($text);
    }
    return text_read_grid($text);
}

/* --------------------------------------------------------------- .xls (BIFF8 in an OLE2 compound file) */

/** Grid of one worksheet of an Excel 97-2003 workbook. */
function xls_read_grid(string $path, int $sheet = 0): array
{
    $wb = ole_read_stream((string)file_get_contents($path), ['Workbook', 'Book']);
    if ($wb === null) {
        throw new RuntimeException('The Excel file has no workbook inside.');
    }
    $len = strlen($wb);
    $pos = 0;
    $sheets = [];
    $sst = [];
    // global records: the sheet list and the shared strings
    while ($pos + 4 <= $len) {
        $u = unpack('vtype/vsize', substr($wb, $pos, 4));
        $type = $u['type'];
        $size = $u['size'];
        $data = substr($wb, $pos + 4, $size);
        $pos += 4 + $size;
        if ($type === 0x0085) {   // BOUNDSHEET: stream offset of the sheet, state, type, name
            $sheets[] = ['offset' => unpack('V', substr($data, 0, 4))[1], 'kind' => ord($data[5] ?? "\0")];
        } elseif ($type === 0x00FC) {   // SST (+ CONTINUE records)
            $parts = [$data];
            while ($pos + 4 <= $len) {
                $n = unpack('vtype/vsize', substr($wb, $pos, 4));
                if ($n['type'] !== 0x003C) {
                    break;
                }
                $parts[] = substr($wb, $pos + 4, $n['size']);
                $pos += 4 + $n['size'];
            }
            $sst = xls_parse_sst($parts);
        } elseif ($type === 0x000A) {   // EOF of the globals
            break;
        }
    }
    $sheets = array_values(array_filter($sheets, fn($s) => $s['kind'] === 0));   // worksheets only
    if (!$sheets) {
        throw new RuntimeException('The Excel file has no worksheet.');
    }
    $s = $sheets[min(max(0, $sheet), count($sheets) - 1)];
    $grid = [];
    $set = function (int $r, int $c, string $v) use (&$grid) {
        if ($v !== '') {
            $grid[$r][$c] = $v;
        }
    };
    $pos = (int)$s['offset'];
    $pendingFormula = null;
    while ($pos + 4 <= $len) {
        $u = unpack('vtype/vsize', substr($wb, $pos, 4));
        $type = $u['type'];
        $data = substr($wb, $pos + 4, $u['size']);
        $pos += 4 + $u['size'];
        if ($type === 0x000A) {   // EOF of the sheet
            break;
        }
        if (strlen($data) < 6 && $type !== 0x0207) {
            continue;
        }
        switch ($type) {
            case 0x00FD:   // LABELSST
                $x = unpack('vrow/vcol/vxf/Vidx', $data);
                $set($x['row'], $x['col'], (string)($sst[$x['idx']] ?? ''));
                break;
            case 0x0204:   // LABEL
            case 0x00D6:   // RSTRING
                $x = unpack('vrow/vcol', $data);
                $set($x['row'], $x['col'], xls_read_string($data, 6, 2));
                break;
            case 0x0203:   // NUMBER
                $x = unpack('vrow/vcol', $data);
                $set($x['row'], $x['col'], xls_num(unpack('d', substr($data, 6, 8))[1]));
                break;
            case 0x027E:   // RK
                $x = unpack('vrow/vcol/vxf/Vrk', $data);
                $set($x['row'], $x['col'], xls_num(xls_rk($x['rk'])));
                break;
            case 0x00BD:   // MULRK
                $x = unpack('vrow/vcol', $data);
                $n = intdiv(strlen($data) - 6, 6);
                for ($i = 0; $i < $n; $i++) {
                    $rk = unpack('V', substr($data, 4 + $i * 6 + 2, 4))[1];
                    $set($x['row'], $x['col'] + $i, xls_num(xls_rk($rk)));
                }
                break;
            case 0x0006:   // FORMULA: the cached result
                $x = unpack('vrow/vcol', $data);
                $res = substr($data, 6, 8);
                if (substr($res, 6, 2) === "\xFF\xFF") {
                    if (ord($res[0]) === 0) {
                        $pendingFormula = [$x['row'], $x['col']];   // the text follows in a STRING record
                    } elseif (ord($res[0]) === 1) {
                        $set($x['row'], $x['col'], ord($res[2]) ? 'TRUE' : 'FALSE');
                    }
                } else {
                    $set($x['row'], $x['col'], xls_num(unpack('d', $res)[1]));
                }
                break;
            case 0x0207:   // STRING (result of the previous formula)
                if ($pendingFormula) {
                    $set($pendingFormula[0], $pendingFormula[1], xls_read_string($data, 0, 2));
                    $pendingFormula = null;
                }
                break;
        }
    }
    ksort($grid);
    foreach ($grid as &$r) {
        ksort($r);
    }
    unset($r);
    return $grid;
}

/** A number as text: integers without decimals. */
function xls_num(float $v): string
{
    return floor($v) == $v && abs($v) < 1e15 ? (string)(int)$v : rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
}

/** Value of an RK number. */
function xls_rk(int $rk): float
{
    if ($rk & 0x02) {
        $v = $rk >> 2;
        if ($v & 0x20000000) {
            $v -= 0x40000000;   // signed 30-bit integer
        }
        $v = (float)$v;
    } else {
        $v = unpack('d', pack('V', 0) . pack('V', $rk & 0xFFFFFFFC))[1];
    }
    return ($rk & 0x01) ? $v / 100 : $v;
}

/** A BIFF8 unicode string at $off (length field of $lenBytes bytes, then the option flags). */
function xls_read_string(string $data, int $off, int $lenBytes): string
{
    $n = $lenBytes === 1 ? ord($data[$off] ?? "\0") : unpack('v', substr($data, $off, 2))[1];
    $flags = ord($data[$off + $lenBytes] ?? "\0");
    $p = $off + $lenBytes + 1;
    if ($flags & 0x08) {
        $p += 2;   // rich text runs count
    }
    if ($flags & 0x04) {
        $p += 4;   // extended data size
    }
    return ($flags & 0x01) ? xls_utf16(substr($data, $p, $n * 2)) : xls_cp1252(substr($data, $p, $n));
}

function xls_utf16(string $s): string
{
    return function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($s, 'UTF-8', 'UTF-16LE') : (string)iconv('UTF-16LE', 'UTF-8//IGNORE', $s);
}

function xls_cp1252(string $s): string
{
    if (!preg_match('/[\x80-\xFF]/', $s)) {
        return $s;
    }
    return function_exists('mb_convert_encoding') ? (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1252') : (string)iconv('CP1252', 'UTF-8//IGNORE', $s);
}

/** The shared strings table; a string may continue in the next CONTINUE record (with a new flag byte for its characters). */
function xls_parse_sst(array $parts): array
{
    $out = [];
    $pi = 0;
    $buf = $parts[0];
    $p = 8;   // total count, unique count
    $count = unpack('V', substr($buf, 4, 4))[1] ?? 0;
    $next = function () use (&$pi, &$buf, &$p, $parts): bool {
        if (!isset($parts[$pi + 1])) {
            return false;
        }
        $pi++;
        $buf = $parts[$pi];
        $p = 0;
        return true;
    };
    // raw bytes that may span records (no flag byte at a record boundary)
    $raw = function (int $n) use (&$buf, &$p, $next): string {
        $s = '';
        while ($n > 0) {
            if ($p >= strlen($buf) && !$next()) {
                break;
            }
            $take = min($n, strlen($buf) - $p);
            $s .= substr($buf, $p, $take);
            $p += $take;
            $n -= $take;
        }
        return $s;
    };
    for ($i = 0; $i < $count; $i++) {
        if ($p + 3 > strlen($buf) && $p >= strlen($buf) && !$next()) {
            break;
        }
        $hdr = $raw(3);
        if (strlen($hdr) < 3) {
            break;
        }
        $chars = unpack('v', substr($hdr, 0, 2))[1];
        $flags = ord($hdr[2]);
        $runs = ($flags & 0x08) ? unpack('v', $raw(2))[1] : 0;
        $ext = ($flags & 0x04) ? unpack('V', $raw(4))[1] : 0;
        $wide = (bool)($flags & 0x01);
        $s = '';
        $left = $chars;
        while ($left > 0) {
            if ($p >= strlen($buf)) {
                if (!$next()) {
                    break;
                }
                $wide = (bool)(ord($buf[0]) & 0x01);   // the continued characters have their own flag byte
                $p = 1;
            }
            $avail = strlen($buf) - $p;
            $take = $wide ? min($left, intdiv($avail, 2)) : min($left, $avail);
            if ($take <= 0) {
                $p = strlen($buf);
                continue;
            }
            $bytes = substr($buf, $p, $wide ? $take * 2 : $take);
            $p += strlen($bytes);
            $s .= $wide ? xls_utf16($bytes) : xls_cp1252($bytes);
            $left -= $take;
        }
        $raw($runs * 4 + $ext);
        $out[] = $s;
    }
    return $out;
}

/** One stream of an OLE2 compound file (the workbook of an .xls), by name. */
function ole_read_stream(string $bin, array $names): ?string
{
    if (strlen($bin) < 512 || substr($bin, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        return null;
    }
    $h = unpack('vminor/vmajor/vorder/vsshift/vmshift', substr($bin, 0x18, 10));
    $ss = 1 << $h['sshift'];
    $ms = 1 << $h['mshift'];
    $x = unpack('VnFat/VdirStart/Vtx/Vcutoff/VminiFatStart/VnMiniFat/VdifatStart/VnDifat', substr($bin, 0x2C, 32));
    $sector = fn(int $n) => substr($bin, ($n + 1) * $ss, $ss);
    // the FAT sectors: 109 in the header, the rest in the DIFAT chain
    $fatSecs = [];
    foreach (array_values(unpack('V109', substr($bin, 0x4C, 436))) as $s) {
        if ($s < 0xFFFFFFFA) {
            $fatSecs[] = $s;
        }
    }
    $d = $x['difatStart'];
    for ($k = 0; $k < $x['nDifat'] && $d < 0xFFFFFFFA; $k++) {
        $vals = array_values(unpack('V' . ($ss / 4), $sector($d)));
        $d = array_pop($vals);
        foreach ($vals as $s) {
            if ($s < 0xFFFFFFFA) {
                $fatSecs[] = $s;
            }
        }
    }
    $fat = [];
    foreach ($fatSecs as $s) {
        foreach (unpack('V' . ($ss / 4), $sector($s)) as $v) {
            $fat[] = $v;
        }
    }
    $chain = function (int $start, array $table) {
        $out = [];
        $seen = [];
        for ($s = $start; $s < 0xFFFFFFFA && isset($table[$s]) && !isset($seen[$s]); $s = $table[$s]) {
            $seen[$s] = true;
            $out[] = $s;
        }
        return $out;
    };
    $read = function (int $start) use ($chain, $fat, $sector) {
        $s = '';
        foreach ($chain($start, $fat) as $n) {
            $s .= $sector($n);
        }
        return $s;
    };
    $dir = $read($x['dirStart']);
    $entries = [];
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $e = substr($dir, $o, 128);
        $nl = unpack('v', substr($e, 0x40, 2))[1];
        $type = ord($e[0x42]);
        if (!$type || $nl < 2) {
            continue;
        }
        $entries[] = ['name' => xls_utf16(substr($e, 0, $nl - 2)), 'type' => $type, 'start' => unpack('V', substr($e, 0x74, 4))[1], 'size' => unpack('V', substr($e, 0x78, 4))[1]];
    }
    $root = null;
    foreach ($entries as $e) {
        if ($e['type'] === 5) {
            $root = $e;
        }
    }
    foreach ($names as $want) {
        foreach ($entries as $e) {
            if ($e['type'] !== 2 || strcasecmp($e['name'], $want) !== 0) {
                continue;
            }
            if ($e['size'] >= $x['cutoff'] || !$root) {
                return substr($read($e['start']), 0, $e['size']);
            }
            // a small stream lives in the mini stream (64-byte sectors)
            $mini = $read($root['start']);
            $miniFat = [];
            foreach ($chain($x['miniFatStart'], $fat) as $n) {
                foreach (unpack('V' . ($ss / 4), $sector($n)) as $v) {
                    $miniFat[] = $v;
                }
            }
            $s = '';
            foreach ($chain($e['start'], $miniFat) as $n) {
                $s .= substr($mini, $n * $ms, $ms);
            }
            return substr($s, 0, $e['size']);
        }
    }
    return null;
}

/* --------------------------------------------------------------- .xlsx, HTML and text */

/** Grid of one worksheet of an .xlsx workbook. */
function xlsx_read_grid(string $path, int $sheet = 0): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is needed to read .xlsx files. Save the file as Excel 97-2003 (.xls) or CSV instead.');
    }
    $z = new ZipArchive();
    if ($z->open($path) !== true) {
        throw new RuntimeException('The .xlsx file cannot be opened.');
    }
    $strings = [];
    if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = @simplexml_load_string($ss);
        if ($x) {
            foreach ($x->si as $si) {
                $t = '';
                if (isset($si->t)) {
                    $t = (string)$si->t;
                } else {
                    foreach ($si->r as $r) {
                        $t .= (string)$r->t;
                    }
                }
                $strings[] = $t;
            }
        }
    }
    $name = 'xl/worksheets/sheet' . ($sheet + 1) . '.xml';
    $xml = $z->getFromName($name);
    if ($xml === false) {
        $xml = $z->getFromName('xl/worksheets/sheet1.xml');
    }
    $z->close();
    $x = $xml !== false ? @simplexml_load_string($xml) : null;
    if (!$x) {
        throw new RuntimeException('The worksheet of the .xlsx file cannot be read.');
    }
    $grid = [];
    foreach ($x->sheetData->row as $row) {
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                continue;
            }
            $col = 0;
            foreach (str_split($m[1]) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $t = (string)$c['t'];
            if ($t === 's') {
                $v = $strings[(int)$c->v] ?? '';
            } elseif ($t === 'inlineStr') {
                $v = (string)$c->is->t;
            } else {
                $v = (string)$c->v;
                if ($v !== '' && is_numeric($v) && $t !== 'str') {
                    $v = xls_num((float)$v);
                }
            }
            if ($v !== '') {
                $grid[(int)$m[2] - 1][$col - 1] = $v;
            }
        }
    }
    return $grid;
}

/** Grid of the first table of an HTML page ("xls" exports that are web pages); a <br> inside a cell becomes a line break. */
function html_read_grid(string $html): array
{
    $grid = [];
    if (!preg_match_all('#<tr\b[^>]*>(.*?)</tr>#is', $html, $trs)) {
        return $grid;
    }
    foreach ($trs[1] as $r => $tr) {
        preg_match_all('#<t[dh]\b([^>]*)>(.*?)</t[dh]>#is', $tr, $tds, PREG_SET_ORDER);
        $c = 0;
        foreach ($tds as $td) {
            $txt = preg_replace('#<br\s*/?>#i', "\n", $td[2]);
            $txt = trim(html_entity_decode(strip_tags((string)$txt), ENT_QUOTES, 'UTF-8'));
            if ($txt !== '') {
                $grid[$r][$c] = $txt;
            }
            $c += preg_match('/colspan\s*=\s*"?(\d+)/i', $td[1], $m) ? max(1, (int)$m[1]) : 1;
        }
    }
    return $grid;
}

/** Grid of a tab (or comma) separated text. */
function text_read_grid(string $text): array
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $lines = preg_split('/\r\n|\n|\r/', (string)$text);
    $delim = substr_count((string)($lines[0] ?? ''), "\t") || substr_count($text, "\t") > substr_count($text, ',') ? "\t" : ',';
    $grid = [];
    foreach ($lines as $r => $line) {
        foreach ($delim === "\t" ? explode("\t", $line) : str_getcsv($line, ',', '"', '\\') as $c => $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $grid[$r][$c] = $v;
            }
        }
    }
    return $grid;
}
