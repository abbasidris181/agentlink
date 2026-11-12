<?php

namespace App;

class SlipTextLayout
{
    public function font(string $font = 'F2'): string
    {
        $name = match ($font) {
            'F1' => 'LiberationSerif-BoldItalic.ttf',
            'F3' => 'LiberationSans-Bold.ttf',
            default => 'LiberationSans-Regular.ttf',
        };
        $path = '/usr/share/fonts/truetype/liberation/'.$name;

        return is_readable($path) ? $path : public_path('assets/fonts/'.($font === 'F3' ? 'Roboto-Bold.ttf' : 'Roboto-Regular.ttf'));
    }

    public function width(string $text, float $size, string $font = 'F2'): float
    {
        $bounds = imagettfbbox(750, 0, $this->font($font), $text);

        return ($bounds[2] - $bounds[0]) / 1000 * $size;
    }

    /**
     * @return list<array{text: string, x: float, y: float, size: float, font: string, clip: array{0: float, 1: float, 2: float, 3: float}}>
     */
    public function field(string $value, float $x, float $baseline, float $width, float $height, float $size, string $font = 'F2'): array
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return [];
        }
        $top = $baseline - $size;
        $fittedSize = $size;
        do {
            $lines = $this->wrap($value, $width, $fittedSize, $font);
            $lineHeight = $fittedSize * 1.2;
            if (count($lines) * $lineHeight <= $height) {
                break;
            }
            $fittedSize -= 0.1;
        } while ($fittedSize > 0.5);
        $result = [];
        foreach ($lines as $index => $line) {
            $result[] = [
                'text' => $line, 'x' => $x, 'y' => $top + $fittedSize + $index * $lineHeight,
                'size' => $fittedSize, 'font' => $font, 'clip' => [$x, $top - 1, $width, $height + 2],
            ];
        }

        return $result;
    }

    /** @return list<string> */
    private function wrap(string $text, float $width, float $size, string $font): array
    {
        $lines = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($this->width($candidate, $size, $font) <= $width) {
                $line = $candidate;

                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            $line = '';
            foreach (mb_str_split($word) as $character) {
                if ($line !== '' && $this->width($line.$character, $size, $font) > $width) {
                    $lines[] = $line;
                    $line = '';
                }
                $line .= $character;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }
}
