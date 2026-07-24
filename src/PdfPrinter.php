<?php

namespace Nyra\EscPos;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Nyra\EscPos\Concerns\BuildsRows;
use Nyra\EscPos\Pdf\PdfDocument;
use RuntimeException;

/**
 * Renders ESC/POS style print jobs as a PDF receipt instead of sending them
 * to a physical printer. Accepts the same commands as Printer, so it can be
 * used interchangeably with JobRunner:
 *
 *   $pdf = new PdfPrinter();
 *   (new JobRunner())->run($pdf, $json);
 *   $pdf->save('receipt.pdf');
 *
 * The page width matches the thermal paper (80mm by default); the page height
 * grows with the content, like a real receipt.
 */
class PdfPrinter implements PrinterInterface
{
    use BuildsRows;

    protected const MM_TO_PT = 72 / 25.4;

    /** Courier glyph width in em units */
    protected const CHAR_WIDTH = 0.6;

    protected int $charsPerLine;
    protected float $paperWidthMm;
    protected float $marginMm;

    protected float $printableMm;
    protected float $dotMm;
    protected float $fontPt;
    protected float $lineHeightPt;

    /** @var array<int, array<string, mixed>> */
    protected array $ops = [];

    /** @var array<int, array<string, mixed>> pending inline text segments */
    protected array $buffer = [];

    protected string $currentAlign = 'left';
    protected bool $currentBold = false;
    protected int $currentUnderline = 0;
    protected int $currentWidthMult = 1;
    protected int $currentHeightMult = 1;

    public function __construct(int $charsPerLine = 48, float $paperWidthMm = 80.0, float $marginMm = 4.0)
    {
        $this->charsPerLine = max(1, $charsPerLine);
        $this->paperWidthMm = $paperWidthMm;
        $this->marginMm = $marginMm;

        $this->printableMm = $paperWidthMm - 2 * $marginMm;
        // ESC/POS fonts are 12 dots wide, so a 48-char printer has 576 dots per line.
        $this->dotMm = $this->printableMm / ($this->charsPerLine * 12);
        $this->fontPt = ($this->printableMm * self::MM_TO_PT) / ($this->charsPerLine * self::CHAR_WIDTH);
        $this->lineHeightPt = $this->fontPt * 1.25;
    }

    // ---------------------------------------------------------------
    //  PrinterInterface commands
    // ---------------------------------------------------------------

    public function initialize(): static
    {
        $this->currentAlign = 'left';
        $this->currentBold = false;
        $this->currentUnderline = 0;
        $this->currentWidthMult = 1;
        $this->currentHeightMult = 1;
        return $this;
    }

    public function text(string $text): static
    {
        $parts = explode("\n", $text);
        foreach ($parts as $i => $part) {
            if ($part !== '') {
                $this->buffer[] = [
                    'text' => $this->toWinAnsi($part),
                    'bold' => $this->currentBold,
                    'underline' => $this->currentUnderline,
                    'w' => $this->currentWidthMult,
                    'h' => $this->currentHeightMult,
                ];
            }
            if ($i < count($parts) - 1) {
                $this->flushLine();
            }
        }
        return $this;
    }

    public function line(string $text = ''): static
    {
        if ($text !== '') $this->text($text);
        return $this->nl(1);
    }

    public function nl(int $count = 1): static
    {
        for ($i = 0; $i < max(0, $count); $i++) {
            $this->flushLine();
        }
        return $this;
    }

    public function cut(bool $partial = false): static
    {
        $this->flushBufferIfPending();
        $this->ops[] = ['type' => 'cut'];
        return $this;
    }

    public function align(string $mode): static
    {
        $this->currentAlign = match (strtolower($mode)) {
            'center', 'centre' => 'center',
            'right' => 'right',
            default => 'left',
        };
        return $this;
    }

    public function bold(bool $on = true): static
    {
        $this->currentBold = $on;
        return $this;
    }

    public function underline(int $mode = 0): static
    {
        $this->currentUnderline = max(0, min(2, $mode));
        return $this;
    }

    public function size(int $width = 1, int $height = 1): static
    {
        $this->currentWidthMult = max(1, min(8, $width));
        $this->currentHeightMult = max(1, min(8, $height));
        return $this;
    }

    public function feed(int $lines = 1): static
    {
        $this->flushBufferIfPending();
        $this->ops[] = ['type' => 'feed', 'lines' => max(0, min(255, $lines))];
        return $this;
    }

    public function row(array $cols, array $widths, string $separator = ' '): static
    {
        return $this->line($this->buildRow($cols, $widths, $separator));
    }

    public function barcodeCode128(string $data, int $height = 80, int $moduleWidth = 3, int $hri = 2): static
    {
        $this->flushBufferIfPending();
        $this->ops[] = [
            'type' => 'barcode',
            'modules' => $this->encodeCode128($data),
            'moduleMm' => max(2, min(6, $moduleWidth)) * $this->dotMm,
            'heightMm' => max(1, min(255, $height)) * $this->dotMm,
            'hri' => max(0, min(3, $hri)),
            'data' => $this->toWinAnsi($data),
            'align' => $this->currentAlign,
        ];
        return $this;
    }

    public function qrcode(string $data, int $size = 6, string $ecc = 'M'): static
    {
        $this->flushBufferIfPending();

        $options = new QROptions();
        $options->eccLevel = in_array(strtoupper($ecc), ['L', 'M', 'Q', 'H'], true) ? strtoupper($ecc) : 'M';

        $matrix = (new QRCode($options))->addByteSegment($data)->getQRMatrix()->getBooleanMatrix();

        $this->ops[] = [
            'type' => 'qr',
            'matrix' => $matrix,
            'moduleMm' => max(1, min(16, $size)) * $this->dotMm,
            'align' => $this->currentAlign,
        ];
        return $this;
    }

    public function image(string $pngPath, int $maxWidth = 576): static
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException("PHP GD extension is required to render images.");
        }
        if (!file_exists($pngPath)) {
            throw new RuntimeException("Image file not found: {$pngPath}");
        }

        $img = @imagecreatefromstring((string)file_get_contents($pngPath));
        if (!$img) throw new RuntimeException("Could not load image.");

        $origW = imagesx($img);
        $origH = imagesy($img);

        // Scale proportionally if wider than the allowed dot width
        $w = $origW;
        $h = $origH;
        if ($w > $maxWidth) {
            $w = $maxWidth;
            $h = (int)round($origH * ($maxWidth / $origW));
        }

        $resized = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $bg);
        imagealphablending($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $w, $h, $origW, $origH);

        // Flatten to 8-bit grayscale, compositing transparency onto white
        $pixels = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $colors = imagecolorsforindex($resized, imagecolorat($resized, $x, $y));
                $lum = $colors['red'] * 0.3 + $colors['green'] * 0.59 + $colors['blue'] * 0.11;
                $opacity = (127 - $colors['alpha']) / 127;
                $gray = (int)round($lum * $opacity + 255 * (1 - $opacity));
                $pixels .= chr(max(0, min(255, $gray)));
            }
        }

        $this->flushBufferIfPending();
        $this->ops[] = [
            'type' => 'image',
            'pixels' => $pixels,
            'pxW' => $w,
            'pxH' => $h,
            'wMm' => $w * $this->dotMm,
            'hMm' => $h * $this->dotMm,
            'align' => $this->currentAlign,
        ];
        return $this;
    }

    // ---------------------------------------------------------------
    //  Output
    // ---------------------------------------------------------------

    /**
     * Returns the rendered PDF as a binary string.
     */
    public function output(): string
    {
        $this->flushBufferIfPending();

        $doc = new PdfDocument();
        $marginPt = $this->marginMm * self::MM_TO_PT;
        $printablePt = $this->printableMm * self::MM_TO_PT;
        $pageW = $this->paperWidthMm * self::MM_TO_PT;

        $pageH = $marginPt * 2;
        foreach ($this->ops as $op) {
            $pageH += $this->opHeight($op);
        }
        $pageH = max($pageH, 30 * self::MM_TO_PT);

        $content = "0 g\n";
        $y = $marginPt; // distance from the top edge

        foreach ($this->ops as $op) {
            $h = $this->opHeight($op);
            $content .= match ($op['type']) {
                'line' => $this->renderLine($op, $y, $pageH, $marginPt, $printablePt),
                'image' => $this->renderImage($doc, $op, $y, $pageH, $marginPt, $printablePt),
                'qr' => $this->renderQr($op, $y, $pageH, $marginPt, $printablePt),
                'barcode' => $this->renderBarcode($op, $y, $pageH, $marginPt, $printablePt),
                'cut' => $this->renderCut($y, $h, $pageH, $marginPt, $printablePt),
                default => '',
            };
            $y += $h;
        }

        return $doc->render($pageW, $pageH, $content);
    }

    /**
     * Writes the rendered PDF to a file.
     */
    public function save(string $path): void
    {
        if (file_put_contents($path, $this->output()) === false) {
            throw new RuntimeException("Could not write PDF to: {$path}");
        }
    }

    // ---------------------------------------------------------------
    //  Layout helpers
    // ---------------------------------------------------------------

    protected function flushLine(): void
    {
        $mult = $this->currentHeightMult;
        foreach ($this->buffer as $seg) {
            $mult = max($mult, $seg['h']);
        }
        $this->ops[] = [
            'type' => 'line',
            'segments' => $this->buffer,
            'align' => $this->currentAlign,
            'mult' => $mult,
        ];
        $this->buffer = [];
    }

    protected function flushBufferIfPending(): void
    {
        if ($this->buffer !== []) {
            $this->flushLine();
        }
    }

    protected function opHeight(array $op): float
    {
        return match ($op['type']) {
            'line' => $op['mult'] * $this->lineHeightPt,
            'feed' => $op['lines'] * $this->lineHeightPt,
            'image' => $op['hMm'] * self::MM_TO_PT,
            'qr' => count($op['matrix']) * $op['moduleMm'] * self::MM_TO_PT,
            'barcode' => $op['heightMm'] * self::MM_TO_PT
                + (($op['hri'] & 1) ? $this->lineHeightPt : 0)   // HRI above
                + (($op['hri'] & 2) ? $this->lineHeightPt : 0),  // HRI below
            'cut' => 6 * self::MM_TO_PT,
            default => 0.0,
        };
    }

    protected function alignX(string $align, float $width, float $marginPt, float $printablePt): float
    {
        return match ($align) {
            'center' => $marginPt + max(0, ($printablePt - $width) / 2),
            'right' => $marginPt + max(0, $printablePt - $width),
            default => $marginPt,
        };
    }

    protected function renderLine(array $op, float $y, float $pageH, float $marginPt, float $printablePt): string
    {
        if ($op['segments'] === []) {
            return '';
        }

        $lineFs = $op['mult'] * $this->fontPt;
        $lineH = $op['mult'] * $this->lineHeightPt;
        // Baseline sits above the descender + half the leading
        $baselineY = $pageH - ($y + $lineH - 0.3 * $lineFs);

        $totalW = 0.0;
        foreach ($op['segments'] as $seg) {
            $totalW += strlen($seg['text']) * self::CHAR_WIDTH * $this->fontPt * $seg['w'];
        }

        $x = $this->alignX($op['align'], $totalW, $marginPt, $printablePt);
        $out = '';

        foreach ($op['segments'] as $seg) {
            $segW = strlen($seg['text']) * self::CHAR_WIDTH * $this->fontPt * $seg['w'];
            if ($seg['text'] !== '') {
                $fs = $seg['h'] * $this->fontPt;
                $tz = $seg['w'] / $seg['h'] * 100;
                $out .= sprintf(
                    "BT /F%d %.2F Tf %.2F Tz %.2F %.2F Td (%s) Tj ET\n",
                    $seg['bold'] ? 2 : 1,
                    $fs,
                    $tz,
                    $x,
                    $baselineY,
                    $this->escapePdfString($seg['text'])
                );
                if ($seg['underline'] > 0) {
                    $thickness = $fs * 0.05 * $seg['underline'];
                    $out .= sprintf(
                        "%.2F %.2F %.2F %.2F re f\n",
                        $x,
                        $baselineY - $fs * 0.15,
                        $segW,
                        $thickness
                    );
                }
            }
            $x += $segW;
        }

        return $out;
    }

    protected function renderImage(PdfDocument $doc, array $op, float $y, float $pageH, float $marginPt, float $printablePt): string
    {
        $name = $doc->addGrayscaleImage($op['pixels'], $op['pxW'], $op['pxH']);
        $wPt = $op['wMm'] * self::MM_TO_PT;
        $hPt = $op['hMm'] * self::MM_TO_PT;
        $x = $this->alignX($op['align'], $wPt, $marginPt, $printablePt);

        return sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
            $wPt,
            $hPt,
            $x,
            $pageH - ($y + $hPt),
            $name
        );
    }

    protected function renderQr(array $op, float $y, float $pageH, float $marginPt, float $printablePt): string
    {
        $modulePt = $op['moduleMm'] * self::MM_TO_PT;
        $sizePt = count($op['matrix']) * $modulePt;
        $x0 = $this->alignX($op['align'], $sizePt, $marginPt, $printablePt);

        $out = '';
        foreach ($op['matrix'] as $rowIdx => $row) {
            $rowTop = $y + $rowIdx * $modulePt;
            $cols = count($row);
            $col = 0;
            while ($col < $cols) {
                if (!$row[$col]) {
                    $col++;
                    continue;
                }
                $run = 0;
                while ($col + $run < $cols && $row[$col + $run]) {
                    $run++;
                }
                $out .= sprintf(
                    "%.2F %.2F %.2F %.2F re\n",
                    $x0 + $col * $modulePt,
                    $pageH - ($rowTop + $modulePt),
                    $run * $modulePt,
                    $modulePt
                );
                $col += $run;
            }
        }

        return $out === '' ? '' : $out . "f\n";
    }

    protected function renderBarcode(array $op, float $y, float $pageH, float $marginPt, float $printablePt): string
    {
        $modulePt = $op['moduleMm'] * self::MM_TO_PT;
        $barHPt = $op['heightMm'] * self::MM_TO_PT;

        $totalModules = array_sum($op['modules']);
        $barcodeW = $totalModules * $modulePt;
        $x0 = $this->alignX($op['align'], $barcodeW, $marginPt, $printablePt);

        $out = '';
        $barTop = $y + (($op['hri'] & 1) ? $this->lineHeightPt : 0);

        // modules alternate bar / space, starting with a bar
        $x = $x0;
        foreach ($op['modules'] as $i => $units) {
            $w = $units * $modulePt;
            if ($i % 2 === 0) {
                $out .= sprintf(
                    "%.2F %.2F %.2F %.2F re\n",
                    $x,
                    $pageH - ($barTop + $barHPt),
                    $w,
                    $barHPt
                );
            }
            $x += $w;
        }
        if ($out !== '') {
            $out .= "f\n";
        }

        // Human readable interpretation, centered on the barcode
        $textW = strlen($op['data']) * self::CHAR_WIDTH * $this->fontPt;
        $textX = max($marginPt, $x0 + ($barcodeW - $textW) / 2);
        foreach ([1 => $y, 2 => $barTop + $barHPt] as $bit => $textTop) {
            if (($op['hri'] & $bit) === 0) {
                continue;
            }
            $baselineY = $pageH - ($textTop + $this->lineHeightPt - 0.3 * $this->fontPt);
            $out .= sprintf(
                "BT /F1 %.2F Tf 100 Tz %.2F %.2F Td (%s) Tj ET\n",
                $this->fontPt,
                $textX,
                $baselineY,
                $this->escapePdfString($op['data'])
            );
        }

        return $out;
    }

    protected function renderCut(float $y, float $h, float $pageH, float $marginPt, float $printablePt): string
    {
        $lineY = $pageH - ($y + $h / 2);
        return sprintf(
            "q [4 2] 0 d 0.4 w %.2F %.2F m %.2F %.2F l S Q\n",
            $marginPt,
            $lineY,
            $marginPt + $printablePt,
            $lineY
        );
    }

    // ---------------------------------------------------------------
    //  Encoding helpers
    // ---------------------------------------------------------------

    protected function toWinAnsi(string $text): string
    {
        $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        return $converted !== false ? $converted : $text;
    }

    protected function escapePdfString(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /**
     * Encodes data as Code 128 (code set B) and returns the module widths,
     * alternating bar / space, starting with a bar.
     *
     * @return int[]
     */
    protected function encodeCode128(string $data): array
    {
        static $patterns = [
            '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
            '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
            '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
            '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
            '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
            '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
            '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
            '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
            '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
            '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
            '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
        ];

        $values = [104]; // Start B
        foreach (str_split($data) as $char) {
            $code = ord($char);
            // Code set B covers ASCII 32..127; replace anything else with a space
            $values[] = ($code >= 32 && $code <= 127) ? $code - 32 : 0;
        }

        $checksum = $values[0];
        foreach ($values as $i => $value) {
            if ($i > 0) {
                $checksum += $value * $i;
            }
        }
        $values[] = $checksum % 103;
        $values[] = 106; // Stop

        $modules = [];
        foreach ($values as $value) {
            foreach (str_split($patterns[$value]) as $digit) {
                $modules[] = (int)$digit;
            }
        }
        return $modules;
    }
}
