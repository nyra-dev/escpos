<?php

/**
 * Renders a print job JSON payload as a PDF receipt.
 *
 * Usage: php examples/json-to-pdf.php <job.json> <output.pdf>
 */

require __DIR__ . '/../vendor/autoload.php';

use Nyra\EscPos\JobRunner;
use Nyra\EscPos\PdfPrinter;

$jobPath = $argv[1] ?? null;
$outPath = $argv[2] ?? 'receipt.pdf';

if (!$jobPath || !file_exists($jobPath)) {
    fwrite(STDERR, "Usage: php examples/json-to-pdf.php <job.json> <output.pdf>\n");
    exit(1);
}

$printer = new PdfPrinter();
(new JobRunner())->run($printer, file_get_contents($jobPath));
$printer->save($outPath);

echo "PDF written to {$outPath}\n";
