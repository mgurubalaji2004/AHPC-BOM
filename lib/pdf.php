<?php
/*
 * Minimal PDF writer (no extensions needed): A4 pages, the standard Times / Helvetica fonts,
 * text with word-wrap and justification, filled / stroked rectangles, lines, JPEG images and links.
 * Coordinates are in points from the TOP-left corner of the page.
 */

require_once __DIR__ . '/pdf_fonts.php';

class Pdf
{
    public const W = 595.28;
    public const H = 841.89;
    private const FONTS = ['Times-Roman' => 'F1', 'Times-Bold' => 'F2', 'Times-Italic' => 'F3', 'Helvetica' => 'F4', 'Helvetica-Bold' => 'F5'];

    private array $pages = [];       // content streams
    private array $links = [];       // per page: [x, y, w, h, url]
    private array $images = [];      // path => [name, w, h, data, colorspace]
    private string $font = 'Times-Roman';
    private float $size = 10;
    private int $page = -1;

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->links[] = [];
        $this->page = count($this->pages) - 1;
    }

    private function out(string $s): void
    {
        $this->pages[$this->page] .= $s . "\n";
    }

    private static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        return sprintf('%.3F %.3F %.3F', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255);
    }

    /** UTF-8 -> WinAnsi bytes (characters outside it are approximated). */
    public static function enc(string $s): string
    {
        $s = strtr($s, ['₹' => 'Rs.', '–' => '-', '—' => '-', "\t" => ' ', '✉' => '', '🌐' => '']);
        $s = preg_replace('/[\x{1F000}-\x{1FFFF}]/u', '', $s);
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $out === false ? preg_replace('/[^\x20-\x7e]/', '?', $s) : $out;
    }

    public function font(string $name, float $size): void
    {
        $this->font = $name;
        $this->size = $size;
    }

    /** Width of a UTF-8 string in points with the current font. */
    public function width(string $s, ?string $font = null, ?float $size = null): float
    {
        $w = 0;
        $tbl = PDF_FONT_WIDTHS[$font ?? $this->font];
        $b = self::enc($s);
        for ($i = 0, $n = strlen($b); $i < $n; $i++) {
            $c = ord($b[$i]);
            $w += $c >= 32 ? $tbl[$c - 32] : 0;
        }
        return $w * ($size ?? $this->size) / 1000;
    }

    private static function esc(string $bytes): string
    {
        return strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => '']);
    }

    /** Text at (x, baseline y). $spacing = extra letter spacing, $word = extra word spacing (justify). */
    public function text(float $x, float $y, string $s, string $color = '#000000', float $spacing = 0, float $word = 0): void
    {
        if ($s === '') {
            return;
        }
        $this->out(sprintf('BT /%s %.2F Tf %s rg %.3F Tc %.3F Tw %.2F %.2F Td (%s) Tj ET',
            self::FONTS[$this->font], $this->size, self::rgb($color), $spacing, $word, $x, self::H - $y, self::esc(self::enc($s))));
    }

    public function textRight(float $right, float $y, string $s, string $color = '#000000', float $spacing = 0): void
    {
        $w = $this->width($s) + $spacing * max(0, mb_strlen($s) - 1);
        $this->text($right - $w, $y, $s, $color, $spacing);
    }

    public function textCenter(float $cx, float $y, string $s, string $color = '#000000'): void
    {
        $this->text($cx - $this->width($s) / 2, $y, $s, $color);
    }

    /** Split text into lines no wider than $w (keeps explicit line breaks). */
    public function wrap(string $text, float $w): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) as $para) {
            $words = preg_split('/ +/', trim($para));
            $line = '';
            foreach ($words as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($this->width($try) <= $w || $line === '') {
                    $line = $try;
                    while ($this->width($line) > $w && mb_strlen($line) > 1) {   // one very long word
                        $cut = mb_strlen($line) - 1;
                        while ($cut > 1 && $this->width(mb_substr($line, 0, $cut)) > $w) {
                            $cut--;
                        }
                        $lines[] = mb_substr($line, 0, $cut);
                        $line = mb_substr($line, $cut);
                    }
                } else {
                    $lines[] = $line;
                    $line = $word;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** Wrapped paragraph; returns the y below it. */
    public function paragraph(float $x, float $y, float $w, string $text, float $leading, string $color = '#000000', bool $justify = false): float
    {
        $lines = $this->wrap($text, $w);
        foreach ($lines as $i => $line) {
            $word = 0;
            $spaces = substr_count($line, ' ');
            if ($justify && $i < count($lines) - 1 && $spaces > 0) {
                $word = ($w - $this->width($line)) / $spaces;
            }
            $this->text($x, $y, $line, $color, 0, $word);
            $y += $leading;
        }
        return $y;
    }

    public function rect(float $x, float $y, float $w, float $h, ?string $fill = null, ?string $stroke = null, float $lw = 0.75): void
    {
        $ops = [];
        if ($fill) {
            $ops[] = self::rgb($fill) . ' rg';
        }
        if ($stroke) {
            $ops[] = self::rgb($stroke) . ' RG ' . sprintf('%.2F w', $lw);
        }
        $ops[] = sprintf('%.2F %.2F %.2F %.2F re %s', $x, self::H - $y - $h, $w, $h, $fill && $stroke ? 'B' : ($fill ? 'f' : 'S'));
        $this->out(implode(' ', $ops));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color = '#000000', float $lw = 0.75): void
    {
        $this->out(sprintf('%s RG %.2F w %.2F %.2F m %.2F %.2F l S', self::rgb($color), $lw, $x1, self::H - $y1, $x2, self::H - $y2));
    }

    public function image(string $path, float $x, float $y, float $w, ?float $h = null): float
    {
        if (!isset($this->images[$path])) {
            $info = getimagesize($path);
            if (!$info || $info[2] !== IMAGETYPE_JPEG) {
                return 0;
            }
            $cs = ($info['channels'] ?? 3) === 1 ? 'DeviceGray' : (($info['channels'] ?? 3) === 4 ? 'DeviceCMYK' : 'DeviceRGB');
            $this->images[$path] = ['Im' . (count($this->images) + 1), $info[0], $info[1], file_get_contents($path), $cs];
        }
        [$name, $iw, $ih] = $this->images[$path];
        $h ??= $w * $ih / $iw;
        $this->out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $w, $h, $x, self::H - $y - $h, $name));
        return $h;
    }

    public function link(float $x, float $y, float $w, float $h, string $url): void
    {
        $this->links[$this->page][] = [$x, $y, $w, $h, $url];
    }

    public function output(): string
    {
        $objs = [];   // index => body ; object number = index + 1
        $add = function (string $body) use (&$objs): int {
            $objs[] = $body;
            return count($objs);
        };
        $catalog = $add('');   // filled at the end
        $pages_id = $add('');
        $font_ids = [];
        foreach (self::FONTS as $name => $tag) {
            $font_ids[$tag] = $add("<< /Type /Font /Subtype /Type1 /BaseFont /$name /Encoding /WinAnsiEncoding >>");
        }
        $img_ids = [];
        foreach ($this->images as [$name, $w, $h, $data, $cs]) {
            $extra = $cs === 'DeviceCMYK' ? ' /Decode [1 0 1 0 1 0 1 0]' : '';
            $img_ids[$name] = $add("<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /$cs /BitsPerComponent 8$extra"
                                   . " /Filter /DCTDecode /Length " . strlen($data) . " >>\nstream\n$data\nendstream");
        }
        $fonts = implode(' ', array_map(fn($t, $id) => "/$t $id 0 R", array_keys($font_ids), $font_ids));
        $xobj = $img_ids ? '/XObject << ' . implode(' ', array_map(fn($n, $id) => "/$n $id 0 R", array_keys($img_ids), $img_ids)) . ' >>' : '';
        $kids = [];
        foreach ($this->pages as $i => $content) {
            $z = gzcompress($content);
            $cid = $add('<< /Filter /FlateDecode /Length ' . strlen($z) . " >>\nstream\n$z\nendstream");
            $annots = [];
            foreach ($this->links[$i] as [$x, $y, $w, $h, $url]) {
                $annots[] = $add(sprintf('<< /Type /Annot /Subtype /Link /Rect [%.2F %.2F %.2F %.2F] /Border [0 0 0] /A << /S /URI /URI (%s) >> >>',
                                         $x, self::H - $y - $h, $x + $w, self::H - $y, self::esc($url)));
            }
            $kids[] = $add(sprintf('<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Contents %d 0 R /Resources << /Font << %s >> %s >>%s >>',
                                   $pages_id, self::W, self::H, $cid, $fonts, $xobj,
                                   $annots ? ' /Annots [' . implode(' ', array_map(fn($a) => "$a 0 R", $annots)) . ']' : ''));
        }
        $objs[$pages_id - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($k) => "$k 0 R", $kids)) . '] /Count ' . count($kids) . ' >>';
        $objs[$catalog - 1] = "<< /Type /Catalog /Pages $pages_id 0 R >>";
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root $catalog 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
