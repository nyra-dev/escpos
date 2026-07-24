<?php

namespace Nyra\EscPos;

use Nyra\EscPos\Concerns\BuildsRows;
use RuntimeException;

/**
 * Super-einfache ESC/POS Klasse für Netzwerk-Thermodrucker (TCP / Port 9100)
 */
class Printer implements PrinterInterface
{
    use BuildsRows;

    private string $host;
    private int $port;
    private mixed $socket = null;
    private int $charsPerLine;

    public function __construct(string $host, int $port = 9100, int $charsPerLine = 48)
    {
        $this->host = $host;
        $this->port = $port;
        $this->charsPerLine = $charsPerLine;
    }

    public function connect(int $timeoutSeconds = 3): self
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $timeoutSeconds);
        if (!$this->socket) {
            throw new RuntimeException("Konnte nicht verbinden zu {$this->host}:{$this->port} – {$errstr} ({$errno})");
        }
        stream_set_timeout($this->socket, $timeoutSeconds);
        return $this->initialize();
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    public function initialize(): self
    {
        return $this->raw("\x1B\x40");
    }

    public function text(string $text): self
    {
        return $this->raw($text);
    }

    public function line(string $text = ''): self
    {
        if ($text !== '') $this->text($text);
        return $this->nl(1);
    }

    public function nl(int $count = 1): self
    {
        return $this->raw(str_repeat("\n", max(0, $count)));
    }

    public function cut(bool $partial = false): self
    {
        return $this->raw("\x1D\x56" . ($partial ? "\x01" : "\x00"));
    }

    public function align(string $mode): self
    {
        $n = match (strtolower($mode)) {
            'left' => 0,
            'center', 'centre' => 1,
            'right' => 2,
            default => 0,
        };
        return $this->raw("\x1B\x61" . chr($n));
    }

    public function bold(bool $on = true): self
    {
        return $this->raw("\x1B\x45" . ($on ? "\x01" : "\x00"));
    }

    public function underline(int $mode = 0): self
    {
        $mode = max(0, min(2, $mode));
        return $this->raw("\x1B\x2D" . chr($mode));
    }

    public function size(int $width = 1, int $height = 1): self
    {
        $width = max(1, min(8, $width));
        $height = max(1, min(8, $height));
        $n = (($height - 1) << 4) | ($width - 1);
        return $this->raw("\x1D\x21" . chr($n));
    }

    public function feed(int $lines = 1): self
    {
        $lines = max(0, min(255, $lines));
        return $this->raw("\x1B\x64" . chr($lines));
    }

    public function row(array $cols, array $widths, string $separator = ' '): self
    {
        return $this->line($this->buildRow($cols, $widths, $separator));
    }

    public function barcodeCode128(string $data, int $height = 80, int $moduleWidth = 3, int $hri = 2): self
    {
        $height = max(1, min(255, $height));
        $moduleWidth = max(2, min(6, $moduleWidth));
        $hri = max(0, min(3, $hri));

        $this->raw("\x1D\x48" . chr($hri));
        $this->raw("\x1D\x68" . chr($height));
        $this->raw("\x1D\x77" . chr($moduleWidth));

        $payload = "{B" . $data;
        $len = strlen($payload);
        return $this->raw("\x1D\x6B" . chr(73) . chr($len) . $payload)->nl(1);
    }

    public function qrcode(string $data, int $size = 6, string $ecc = 'M'): self
    {
        $size = max(1, min(16, $size));
        $eccVal = match (strtoupper($ecc)) {
            'L' => 48,
            'M' => 49,
            'Q' => 50,
            'H' => 51,
            default => 49,
        };

        $this->raw("\x1D\x28\x6B\x04\x00\x31\x41\x32\x00");
        $this->raw("\x1D\x28\x6B\x03\x00\x31\x43" . chr($size));
        $this->raw("\x1D\x28\x6B\x03\x00\x31\x45" . chr($eccVal));

        $bytes = $data;
        $len = strlen($bytes) + 3;
        $pL = $len & 0xFF;
        $pH = ($len >> 8) & 0xFF;
        $this->raw("\x1D\x28\x6B" . chr($pL) . chr($pH) . "\x31\x50\x30" . $bytes);
        return $this->raw("\x1D\x28\x6B\x03\x00\x31\x51\x30")->nl(1);
    }

    private function raw(string $bytes): self
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException("Nicht verbunden – erst connect() aufrufen.");
        }
        fwrite($this->socket, $bytes);
        return $this;
    }

    /**
     * Prints a PNG image.
     * Automatically scales it down if it exceeds the printer's max pixel width (default 576 for 80mm).
     * Requires the PHP GD extension to be installed and enabled.
     */
    public function image(string $pngPath, int $maxWidth = 576): self
    {
        if (!function_exists('imagecreatefrompng')) {
            throw new RuntimeException("PHP GD extension is required to print images.");
        }
        if (!file_exists($pngPath)) {
            throw new RuntimeException("Image file not found: {$pngPath}");
        }

        $img = imagecreatefrompng($pngPath);
        if (!$img) throw new RuntimeException("Could not load PNG image.");

        $origW = imagesx($img);
        $origH = imagesy($img);

        // Scale proportionally if too wide
        $w = $origW;
        $h = $origH;
        if ($w > $maxWidth) {
            $w = $maxWidth;
            $h = (int)($origH * ($maxWidth / $origW));
        }

        // ESC/POS requires the width to be a multiple of 8
        $w = (int)(floor($w / 8) * 8);

        // Create a blank white canvas and resample the image onto it
        $resized = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $bg);

        // Preserve transparency by converting it to white
        imagealphablending($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $w, $h, $origW, $origH);

        // Convert to 1-bit monochrome bytes
        $bytesPerRow = $w / 8;
        $rasterData = '';

        for ($y = 0; $y < $h; $y++) {
            $rowByte = 0;
            for ($x = 0; $x < $w; $x++) {
                $colorIdx = imagecolorat($resized, $x, $y);
                $colors = imagecolorsforindex($resized, $colorIdx);

                // Calculate luminance to determine if pixel is black or white
                $luminance = ($colors['red'] * 0.3 + $colors['green'] * 0.59 + $colors['blue'] * 0.11);

                // If pixel is dark and mostly opaque, make it a printed dot
                $isDark = ($luminance < 128 && $colors['alpha'] < 64);

                $bitPos = 7 - ($x % 8);
                if ($isDark) {
                    $rowByte |= (1 << $bitPos);
                }

                if ($x % 8 == 7) {
                    $rasterData .= chr($rowByte);
                    $rowByte = 0;
                }
            }
        }

        imagedestroy($img);
        imagedestroy($resized);

        // Send GS v 0 command (Print raster bit image)
        // m=0 (normal), xL, xH, yL, yH
        $xL = $bytesPerRow & 0xFF;
        $xH = ($bytesPerRow >> 8) & 0xFF;
        $yL = $h & 0xFF;
        $yH = ($h >> 8) & 0xFF;

        // Command: GS v 0 0 [width bytes] [height dots] [data]
        $this->raw("\x1D\x76\x30\x00" . chr($xL) . chr($xH) . chr($yL) . chr($yH) . $rasterData);

        return $this->nl(1);
    }
}
