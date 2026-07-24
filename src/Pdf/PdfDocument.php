<?php

namespace Nyra\EscPos\Pdf;

/**
 * Minimal single-page PDF writer, tailored for receipt output.
 *
 * Uses the built-in Courier / Courier-Bold core fonts (WinAnsi encoding) and
 * supports 8-bit grayscale images (FlateDecode). No external dependencies.
 */
class PdfDocument
{
    /** @var array<string, array{width: int, height: int, data: string}> */
    protected array $images = [];

    /**
     * Registers an 8-bit grayscale image (one byte per pixel, row-major)
     * and returns the XObject name to reference it in the content stream.
     */
    public function addGrayscaleImage(string $pixels, int $width, int $height): string
    {
        $name = 'Im' . (count($this->images) + 1);
        $this->images[$name] = [
            'width' => $width,
            'height' => $height,
            'data' => gzcompress($pixels),
        ];
        return $name;
    }

    /**
     * Assembles the final PDF file from the given page size (in points)
     * and content stream.
     */
    public function render(float $pageWidth, float $pageHeight, string $content): string
    {
        $objects = [];

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";

        $xObjects = '';
        $imageObjNum = 7;
        foreach ($this->images as $name => $img) {
            $xObjects .= sprintf('/%s %d 0 R ', $name, $imageObjNum);
            $imageObjNum++;
        }

        $objects[3] = sprintf(
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] " .
            "/Resources << /Font << /F1 5 0 R /F2 6 0 R >> %s >> /Contents 4 0 R >>",
            $pageWidth,
            $pageHeight,
            $xObjects !== '' ? "/XObject << {$xObjects}>>" : ''
        );

        $stream = gzcompress($content);
        $objects[4] = sprintf(
            "<< /Length %d /Filter /FlateDecode >>\nstream\n%s\nendstream",
            strlen($stream),
            $stream
        );

        $objects[5] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>";
        $objects[6] = "<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>";

        $imageObjNum = 7;
        foreach ($this->images as $img) {
            $objects[$imageObjNum] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d " .
                "/ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                $img['width'],
                $img['height'],
                strlen($img['data']),
                $img['data']
            );
            $imageObjNum++;
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }
}
