<?php

namespace Nyra\EscPos;

use InvalidArgumentException;
use JsonException;

class JobRunner
{
    /**
     * @throws JsonException
     */
    public function run(Printer $printer, array|string $payload): void
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        }

        if (!isset($payload['commands']) || !is_array($payload['commands'])) {
            throw new InvalidArgumentException("Invalid print job payload: Missing 'commands' array.");
        }

        foreach ($payload['commands'] as $cmd) {
            $method = $cmd['method'] ?? null;
            $args = $cmd['args'] ?? [];

            if (!$method) {
                continue;
            }

            // Handle the special base64Image case by creating a temporary file
            if ($method === 'base64Image') {
                $base64Data = $args[0] ?? '';
                $maxWidth = $args[1] ?? 576;

                if ($base64Data) {
                    $this->printBase64Image($printer, $base64Data, $maxWidth);
                }
                continue;
            }

            // Standard method execution
            if (method_exists($printer, $method)) {
                $printer->$method(...$args);
            }
        }
    }

    protected function printBase64Image(Printer $printer, string $base64, int $maxWidth): void
    {
        // Create a temporary file to leverage the existing GD logic in EscPosPrinter
        $tmpPath = tempnam(sys_get_temp_dir(), 'escpos_img_');

        try {
            file_put_contents($tmpPath, base64_decode($base64));
            $printer->image($tmpPath, $maxWidth);
        } finally {
            // Ensure we clean up the temporary file immediately
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }
}
