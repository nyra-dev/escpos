<?php

namespace Nyra\EscPos;

use JsonException;
use RuntimeException;

class JobBuilder
{
    protected array $commands = [];

    public static function make(): self
    {
        return new self();
    }

    public function initialize(): self
    {
        return $this->addCommand('initialize');
    }

    public function text(string $text): self
    {
        return $this->addCommand('text', [$text]);
    }

    public function line(string $text = ''): self
    {
        return $this->addCommand('line', [$text]);
    }

    public function nl(int $count = 1): self
    {
        return $this->addCommand('nl', [$count]);
    }

    public function cut(bool $partial = false): self
    {
        return $this->addCommand('cut', [$partial]);
    }

    public function align(string $mode): self
    {
        return $this->addCommand('align', [$mode]);
    }

    public function bold(bool $on = true): self
    {
        return $this->addCommand('bold', [$on]);
    }

    public function underline(int $mode = 0): self
    {
        return $this->addCommand('underline', [$mode]);
    }

    public function size(int $width = 1, int $height = 1): self
    {
        return $this->addCommand('size', [$width, $height]);
    }

    public function feed(int $lines = 1): self
    {
        return $this->addCommand('feed', [$lines]);
    }

    public function row(array $cols, array $widths, string $separator = ' '): self
    {
        return $this->addCommand('row', [$cols, $widths, $separator]);
    }

    public function barcodeCode128(string $data, int $height = 80, int $moduleWidth = 3, int $hri = 2): self
    {
        return $this->addCommand('barcodeCode128', [$data, $height, $moduleWidth, $hri]);
    }

    public function qrcode(string $data, int $size = 6, string $ecc = 'M'): self
    {
        return $this->addCommand('qrcode', [$data, $size, $ecc]);
    }

    /**
     * Reads a local file and stores it as base64 in the JSON payload.
     */
    public function image(string $pngPath, int $maxWidth = 576): self
    {
        if (!file_exists($pngPath)) {
            throw new RuntimeException("Image file not found: {$pngPath}");
        }

        $base64 = base64_encode(file_get_contents($pngPath));
        return $this->base64Image($base64, $maxWidth);
    }

    /**
     * Directly inserts a base64 encoded image string into the payload.
     */
    public function base64Image(string $base64, int $maxWidth = 576): self
    {
        return $this->addCommand('base64Image', [$base64, $maxWidth]);
    }

    protected function addCommand(string $method, array $args = []): self
    {
        $this->commands[] = [
            'method' => $method,
            'args' => $args,
        ];
        return $this;
    }

    public function toArray(): array
    {
        return [
            'version' => '1.0',
            'commands' => $this->commands,
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
