<?php

namespace App;

use GdImage;

class RasterPdf
{
    public function __construct(private SlipTextLayout $layout) {}

    /**
     * @param  list<array{image: GdImage, x: int|float, top: int|float, width: int|float, height: int|float}>  $placements
     * @param  list<string>  $heading
     * @param  list<array<string, mixed>>  $texts
     */
    public function render(array $placements, bool $landscape = false, array $heading = [], array $texts = []): string
    {
        $pageWidth = $landscape ? 841.89 : 595.28;
        $pageHeight = $landscape ? 595.28 : 841.89;
        $imageObjects = [];
        $resources = [];
        $commands = [];
        $fontId = 5 + count($placements);
        foreach ($heading as $line => $text) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
            $commands[] = sprintf('BT /F1 12 Tf 40 %.3F Td (%s) Tj ET', $pageHeight - 55 - 16 * $line, $escaped);
        }
        foreach ($placements as $index => $placement) {
            $image = $placement['image'];
            ob_start();
            imagejpeg($image, null, 98);
            $jpeg = (string) ob_get_clean();
            $resources[] = '/I'.$index.' '.(5 + $index).' 0 R';
            $imageObjects[] = '<< /Type /XObject /Subtype /Image /Width '.imagesx($image).' /Height '.imagesy($image)
                .' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >>\nstream\n".$jpeg."\nendstream";
            $commands[] = sprintf('q %.3F 0 0 %.3F %.3F %.3F cm /I%d Do Q',
                $placement['width'], $placement['height'], $placement['x'], $pageHeight - $placement['top'] - $placement['height'], $index);
        }
        $stream = implode("\n", $commands);
        foreach ($texts as $text) {
            $value = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text['text']);
            $value = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $value);
            $font = $text['font'] ?? 'F2';
            $angle = deg2rad($text['angle'] ?? 0);
            $gray = $text['gray'] ?? 0;
            $scale = isset($text['width']) ? 100 * $text['width'] / max(0.1, $this->layout->width($text['text'], $text['size'], $font)) : 100;
            $stream .= "\nq ";
            if (isset($text['clip'])) {
                [$x, $top, $width, $height] = $text['clip'];
                $stream .= sprintf('%.4F %.4F %.4F %.4F re W n ', $x, $pageHeight - $top - $height, $width, $height);
            }
            $stream .= sprintf('%.3F g BT /%s %.4F Tf %.4F Tz %.6F %.6F %.6F %.6F %.4F %.4F Tm (%s) Tj ET Q',
                $gray, $font, $text['size'], $scale, cos($angle), sin($angle), -sin($angle), cos($angle),
                $text['x'], $pageHeight - $text['y'], $value);
        }
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$pageWidth.' '.$pageHeight.'] /Resources << /XObject << '.implode(' ', $resources).' >> /Font << /F1 '.$fontId.' 0 R /F2 '.($fontId + 1).' 0 R /F3 '.($fontId + 2).' 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
            ...$imageObjects,
            '<< /Type /Font /Subtype /Type1 /BaseFont /Times-BoldItalic >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".count($offsets)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".count($offsets)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
