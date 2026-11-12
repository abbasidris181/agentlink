<?php

namespace App;

use GdImage;
use Illuminate\Validation\ValidationException;

class SlipQrCode
{
    /** Byte-mode symbols with level L correction, sized for printed identity details. */
    public function image(string $payload): GdImage
    {
        if (strlen($payload) > 520) {
            throw ValidationException::withMessages(['slipDownload' => 'The identity details exceed the slip QR code capacity.']);
        }
        [$versionNumber, $blockLengths, $parityLength, $alignment] = match (true) {
            strlen($payload) <= 192 => [8, [97, 97], 24, [6, 24, 42]],
            strlen($payload) <= 271 => [10, [68, 68, 69, 69], 18, [6, 28, 50]],
            default => [15, [87, 87, 87, 87, 87, 88], 22, [6, 26, 48, 70]],
        };
        $capacity = array_sum($blockLengths);
        $bits = '0100'.str_pad(decbin(strlen($payload)), $versionNumber < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (unpack('C*', $payload) as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $data = array_map(bindec(...), str_split($bits, 8));
        for ($pad = 0; count($data) < $capacity; $pad++) {
            $data[] = $pad % 2 === 0 ? 236 : 17;
        }
        $blocks = [];
        $offset = 0;
        foreach ($blockLengths as $length) {
            $blocks[] = array_slice($data, $offset, $length);
            $offset += $length;
        }
        $parity = array_map(fn (array $block): array => $this->parity($block, $parityLength), $blocks);
        $words = [];
        for ($i = 0; $i < max($blockLengths); $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $words[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $parityLength; $i++) {
            foreach ($parity as $block) {
                $words[] = $block[$i];
            }
        }
        $message = '';
        foreach ($words as $word) {
            $message .= str_pad(decbin($word), 8, '0', STR_PAD_LEFT);
        }

        $size = 17 + 4 * $versionNumber;
        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = $matrix;
        $set = function (int $x, int $y, bool $dark) use (&$matrix, &$reserved, $size): void {
            if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                $matrix[$y][$x] = $dark;
                $reserved[$y][$x] = true;
            }
        };
        for ($i = 0; $i < $size; $i++) {
            $set(6, $i, $i % 2 === 0);
            $set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $distance = max(abs($dx), abs($dy));
                    $set($cx + $dx, $cy + $dy, $distance !== 2 && $distance !== 4);
                }
            }
        }
        $lastAlignment = $alignment[array_key_last($alignment)];
        foreach ($alignment as $cx) {
            foreach ($alignment as $cy) {
                if (($cx === 6 && in_array($cy, [6, $lastAlignment], true)) || ($cx === $lastAlignment && $cy === 6)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        $remainder = 8;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $format = ((8 << 10) | $remainder) ^ 0x5412;
        $bit = fn (int $i): bool => (($format >> $i) & 1) !== 0;
        for ($i = 0; $i <= 5; $i++) {
            $set(8, $i, $bit($i));
        }
        $set(8, 7, $bit(6));
        $set(8, 8, $bit(7));
        $set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, $bit($i));
        }
        $set(8, $size - 8, true);

        $versionRemainder = $versionNumber;
        for ($i = 0; $i < 12; $i++) {
            $versionRemainder = ($versionRemainder << 1) ^ (($versionRemainder >> 11) * 0x1F25);
        }
        $version = ($versionNumber << 12) | $versionRemainder;
        for ($i = 0; $i < 18; $i++) {
            $a = $size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $set($a, $b, (($version >> $i) & 1) !== 0);
            $set($b, $a, (($version >> $i) & 1) !== 0);
        }

        $index = 0;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vertical = 0; $vertical < $size; $vertical++) {
                $upward = ($right & 2) === 0;
                $y = $upward ? $size - 1 - $vertical : $vertical;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if (! $reserved[$y][$x]) {
                        $dark = ($message[$index++] ?? '0') === '1';
                        $matrix[$y][$x] = $dark !== (($x + $y) % 2 === 0);
                    }
                }
            }
        }
        $image = imagecreatetruecolor(($size + 8) * 4, ($size + 8) * 4);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $black = imagecolorallocate($image, 0, 0, 0);
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    imagefilledrectangle($image, ($x + 4) * 4, ($y + 4) * 4, ($x + 5) * 4 - 1, ($y + 5) * 4 - 1, $black);
                }
            }
        }

        return $image;
    }

    /** @param list<int> $data
     * @return list<int>
     */
    private function parity(array $data, int $degree): array
    {
        $divisor = array_fill(0, $degree, 0);
        $divisor[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $divisor[$j] = $this->multiply($divisor[$j], $root);
                if ($j < $degree - 1) {
                    $divisor[$j] ^= $divisor[$j + 1];
                }
            }
            $root = $this->multiply($root, 2);
        }
        $result = array_fill(0, $degree, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $result[$i] ^= $this->multiply($divisor[$i], $factor);
            }
        }

        return $result;
    }

    private function multiply(int $x, int $y): int
    {
        $product = 0;
        for ($i = 7; $i >= 0; $i--) {
            $product = ($product << 1) ^ (($product >> 7) * 0x11D);
            $product ^= (($y >> $i) & 1) * $x;
        }

        return $product;
    }
}
