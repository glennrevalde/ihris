<?php
/**
 * SimplePDF – a tiny dependency-free PDF writer (Helvetica / Helvetica-Bold, text, lines, boxes).
 * Used to let employees download their payslips as PDF files.
 * Coordinates are in points with the origin at the TOP-LEFT of the page.
 */
class SimplePDF
{
    private array $pages = [];
    private int $cur = -1;
    private float $w;
    private float $h;

    private const HELV = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const HELVB = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    public function __construct(float $width = 595.28, float $height = 841.89)
    {
        $this->w = $width;
        $this->h = $height;
    }

    public function width(): float
    {
        return $this->w;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->cur = count($this->pages) - 1;
    }

    private function enc(string $s): string
    {
        $s = str_replace(['₱', '—', '–', '·', '•'], ['PHP ', '-', '-', '-', '-'], $s);
        $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($c === false) {
            $c = preg_replace('/[^\x20-\x7E]/', '?', $s);
        }
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $c);
    }

    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        $s = str_replace('₱', 'PHP ', $s);
        $m = $bold ? self::HELVB : self::HELV;
        $w = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($s[$i]);
            $w += ($o >= 32 && $o <= 126) ? $m[$o - 32] : 556;
        }
        return $w * $size / 1000;
    }

    public function text(float $x, float $y, string $txt, float $size = 10, bool $bold = false, string $align = 'L', float $gray = 0): void
    {
        if ($align === 'R') {
            $x -= $this->textWidth($txt, $size, $bold);
        } elseif ($align === 'C') {
            $x -= $this->textWidth($txt, $size, $bold) / 2;
        }
        $this->pages[$this->cur] .= sprintf("BT %.3F g /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $gray, $bold ? 'F2' : 'F1', $size, $x, $this->h - $y, $this->enc($txt));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, float $gray = 0): void
    {
        $this->pages[$this->cur] .= sprintf("%.3F G %.2F w %.2F %.2F m %.2F %.2F l S\n", $gray, $width, $x1, $this->h - $y1, $x2, $this->h - $y2);
    }

    public function rect(float $x, float $y, float $w, float $h, ?float $fillGray = null, bool $stroke = true, float $strokeGray = 0.6): void
    {
        $op = $fillGray !== null ? ($stroke ? 'B' : 'f') : 'S';
        $this->pages[$this->cur] .= sprintf("%s%.3F G 0.5 w %.2F %.2F %.2F %.2F re %s\n",
            $fillGray !== null ? sprintf('%.3F g ', $fillGray) : '', $strokeGray, $x, $this->h - $y - $h, $w, $h, $op);
    }

    public function output(): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->pages as $content) {
            $pageId = $n++;
            $contId = $n++;
            $kids[] = "$pageId 0 R";
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->w, $this->h, $contId);
            $objs[$contId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $out;
    }
}
