<?php

namespace Nyra\EscPos;

/**
 * Common command surface shared by all print targets (network printer, PDF, ...),
 * so a JobRunner can replay the same job payload against any of them.
 */
interface PrinterInterface
{
    public function initialize(): self;

    public function text(string $text): self;

    public function line(string $text = ''): self;

    public function nl(int $count = 1): self;

    public function cut(bool $partial = false): self;

    public function align(string $mode): self;

    public function bold(bool $on = true): self;

    public function underline(int $mode = 0): self;

    public function size(int $width = 1, int $height = 1): self;

    public function feed(int $lines = 1): self;

    public function row(array $cols, array $widths, string $separator = ' '): self;

    public function barcodeCode128(string $data, int $height = 80, int $moduleWidth = 3, int $hri = 2): self;

    public function qrcode(string $data, int $size = 6, string $ecc = 'M'): self;

    public function image(string $pngPath, int $maxWidth = 576): self;
}
