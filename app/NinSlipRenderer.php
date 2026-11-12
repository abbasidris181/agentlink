<?php

namespace App;

use App\Models\NinVerificationRecord;
use App\Models\VerificationRecord;
use DateTimeImmutable;
use GdImage;

class NinSlipRenderer
{
    public function __construct(private RasterPdf $pdf, private SlipQrCode $qr, private SlipTextLayout $textLayout) {}

    public function render(VerificationRecord $verification, string $slip): string
    {
        $document = $this->document($verification, $slip);

        return $this->pdf->render($document['images'], $slip === 'digital', [], $document['texts']);
    }

    public function preview(VerificationRecord $verification, string $slip): string
    {
        $document = $this->document($verification, $slip);
        $background = $document['images'][0];
        $scale = 4;
        $image = imagecreatetruecolor((int) round($background['width'] * $scale), (int) round($background['height'] * $scale));
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        foreach ($document['images'] as $placement) {
            if ($placement['top'] >= $background['top'] + $background['height']) {
                continue;
            }
            imagecopyresampled($image, $placement['image'],
                (int) round(($placement['x'] - $background['x']) * $scale),
                (int) round(($placement['top'] - $background['top']) * $scale), 0, 0,
                (int) round($placement['width'] * $scale), (int) round($placement['height'] * $scale),
                imagesx($placement['image']), imagesy($placement['image']));
        }
        foreach ($document['texts'] as $text) {
            if ($text['y'] < $background['top']) {
                continue;
            }
            $clip = imagegetclip($image);
            if (isset($text['clip'])) {
                [$x, $top, $width, $height] = $text['clip'];
                imagesetclip($image,
                    (int) round(($x - $background['x']) * $scale), (int) round(($top - $background['top']) * $scale),
                    (int) round(($x + $width - $background['x']) * $scale), (int) round(($top + $height - $background['top']) * $scale));
            }
            $gray = (int) round(($text['gray'] ?? 0) * 255);
            imagettftext($image, $text['size'] * $scale * 0.75, $text['angle'] ?? 0,
                (int) round(($text['x'] - $background['x']) * $scale), (int) round(($text['y'] - $background['top']) * $scale),
                imagecolorallocate($image, $gray, $gray, $gray), $this->textLayout->font($text['font'] ?? 'F2'), $text['text']);
            imagesetclip($image, ...$clip);
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function qrDetails(VerificationRecord $verification): string
    {
        return implode("\n", [
            $verification->exists ? 'NIN DETAILS' : 'SAMPLE NIN DETAILS',
            'NIN: '.$verification->field('idNumber'),
            'Surname: '.$verification->field('lastName'),
            'First name: '.$verification->field('firstName'),
            'Middle name: '.$verification->field('middleName'),
            'Date of birth: '.$this->date($verification->field('dateOfBirth')),
            'Gender: '.$verification->field('gender'),
        ]);
    }

    /**
     * Coordinates are measured in PDF points from the supplied reference slips.
     *
     * @return array{images: list<array<string, mixed>>, texts: list<array<string, mixed>>}
     */
    private function document(VerificationRecord $verification, string $slip): array
    {
        abort_unless(extension_loaded('gd'), 503, 'Slip downloads are temporarily unavailable.');
        abort_unless(isset(NinVerificationRecord::SLIPS[$slip]), 404);
        $field = $verification->field(...);
        $texts = [];
        $add = function (string $value, float $x, float $baseline, float $width, float $height, float $size, string $font = 'F2', float $gray = 0) use (&$texts): void {
            foreach ($this->textLayout->field($value, $x, $baseline, $width, $height, $size, $font) as $line) {
                $texts[] = [...$line, 'gray' => $gray];
            }
        };

        if ($slip === 'regular') {
            $images = [$this->image($this->template('regular.png'), 42.51968, 141.73228, 504.56693, 226.77166)];
            $this->photo($images, $verification->image('photo'), 472.81891, 196.44098, 70.86615, 87.87402);
            $add($field('trackingId'), 105, 210.06183, 65, 11, 8);
            $add($field('idNumber'), 73.70114, 233.17834, 96, 11, 8);
            $add($field('lastName'), 243.77988, 210.06183, 84, 13, 9);
            $add($field('firstName'), 243.77988, 234.72327, 84, 13, 9);
            $add($field('middleName'), 243.77988, 257.4, 84, 13, 9);
            $add($field('gender'), 243.77988, 279.95, 84, 11, 8);
            $add($field('address.addressLine'), 331.6539, 218.5658, 138, 42, 9);
            $add($field('address.lga') ?: $field('address.town'), 331.6539, 264.5, 138, 12, 8);
            $add($field('address.state'), 331.6539, 279.95, 138, 11, 8);

            return compact('images', 'texts');
        }

        if (in_array($slip, ['premium', 'standard'], true)) {
            $premium = $slip === 'premium';
            $images = [$this->image($this->template($premium ? 'premium.jpg' : 'standard.jpg'), 198.4252, 141.73232, 226.77164, 141.73228)];
            $this->photo($images, $verification->image('photo'),
                $premium ? 202.67717 : 204.09448, 175.74805,
                $premium ? 56.69292 : 51.02362, $premium ? 70.86615 : 65.19686);
            $qrWidth = $premium ? 61.2298 : 43.53653;
            $qrHeight = $premium ? 57.47309 : 39.79843;
            $code = $this->qr->image($this->qrDetails($verification));
            $code = imagecrop($code, ['x' => 16, 'y' => 16, 'width' => imagesx($code) - 32, 'height' => imagesy($code) - 32]);
            $qrBox = imagecreatetruecolor((int) round(imagesy($code) * $qrWidth / $qrHeight), imagesy($code));
            imagefill($qrBox, 0, 0, imagecolorallocate($qrBox, 255, 255, 255));
            $qrSize = imagesy($qrBox);
            imagecopyresized($qrBox, $code, 0, 0,
                0, 0, imagesx($qrBox), $qrSize, imagesx($code), imagesy($code));
            $images[] = $this->image($qrBox, $premium ? 359.50991 : 370.26323, $premium ? 149.27404 : 181.87086,
                $qrWidth, $qrHeight);
            $images[] = $this->image($this->template('back.jpg'), 198.4252, 286.29926, 226.77164, 141.7323);
            $nameX = $premium ? 267.30743 : 262.20508;
            $add($field('lastName'), $nameX, $premium ? 197.30597 : 192.07599, 93, 13, $premium ? 9 : 8);
            $givenNames = $field('firstName').($field('middleName') !== '' ? ', '.$field('middleName') : '');
            $add($givenNames, $nameX, $premium ? 217.1485 : 211.91852, 95, 14, $premium ? 9 : 8);
            $add($this->date($field('dateOfBirth')), $nameX, $premium ? 236.013 : 230.91064, 57, 11, 8);
            if ($premium) {
                $add($field('gender'), 325.98461, 236.991, 11, 11, 9);
                $add($field('idNumber'), 368.5043, 213.77515, 45, 10, 7);
            }
            $add($verification->created_at->format('d M Y'), 365.66965, $premium ? 239.69806 : 234.59564, 55, 11, 8);
            $nin = $field('idNumber');
            $add(substr($nin, 0, 4).' '.substr($nin, 4, 3).' '.substr($nin, 7, 4), 232.4413,
                $premium ? 278.49078 : 272.82147, 145, 27, 21);
            $watermarks = [[209.36728, 261.56061, 50]];
            if ($premium) {
                $watermarks[] = [218.95496, 283.35034, 50];
                $watermarks[] = [416.65778, 279.1701, 130];
            }
            foreach ($watermarks as [$x, $y, $angle]) {
                $texts[] = ['text' => $nin, 'x' => $x, 'y' => $y, 'size' => 7, 'font' => 'F2', 'gray' => 0.75, 'angle' => $angle];
            }
            if ($premium) {
                $texts[] = $this->heading('Please find below your new High Resolution NIN Slip...', 144.93903, 68.41095, 333.74417);
            } else {
                $texts[] = $this->heading('Please find below your new High Resolution NIN Slip. You may cut it', 103.20303, 68.41095, 417.21623);
                $texts[] = $this->heading('out of the paper, fold and laminate as desired. Please DO NOT allow', 105.52503, 83.41095, 412.5722);
                $texts[] = $this->heading('others to make copies of your NIN Slip.', 192.19502, 98.41095, 239.23216);
            }

            return compact('images', 'texts');
        }

        $images = [$this->image($this->template('basic.jpg'), 56.69291, 70.86615, 708.66146, 462.04724)];
        $this->photo($images, $verification->image('photo'), 261.07086, 192.18896, 131.81104, 138.89765);
        $this->photo($images, $verification->image('signature'), 308.97638, 333.07089, 85.03937, 22.67716);
        $add($field('firstName'), 147.40193, 208.34518, 110, 18, 10, 'F3', 0.35);
        $add($field('middleName'), 147.40193, 239.668, 110, 20, 10, 'F3', 0.35);
        $add($field('lastName'), 147.40193, 270.99088, 110, 18, 10, 'F3', 0.35);
        $add($this->date($field('dateOfBirth')), 147.40193, 296.21921, 110, 18, 10, 'F3', 0.35);
        $add($field('gender'), 147.40193, 338.7389, 110, 15, 10, 'F3', 0.35);
        $nin = $field('idNumber');
        $add(substr($nin, 0, 4).' '.substr($nin, 4, 3).' '.substr($nin, 7, 4), 212.59879, 373.03711, 185, 25, 18, 'F3', 0.35);
        $add($field('trackingId'), 147.40193, 402.51843, 110, 18, 10, 'F3', 0.35);
        $add($field('mobile'), 334.48856, 402.51843, 65, 18, 10, 'F3', 0.35);
        $add($field('address.state'), 147.40193, 434.54993, 110, 20, 10, 'F3', 0.35);
        $add($field('address.lga') ?: $field('address.town'), 334.48856, 434.54993, 65, 20, 10, 'F3', 0.35);
        $add($field('birthState'), 147.40193, 466.58, 110, 18, 10, 'F3', 0.35);
        $add($field('birthLGA'), 334.48856, 466.58, 65, 18, 10, 'F3', 0.35);
        $add($field('address.addressLine'), 136.06334, 494.64441, 263, 34, 10, 'F3', 0.35);

        return compact('images', 'texts');
    }

    /** @return array<string, mixed> */
    private function image(GdImage $image, float $x, float $top, float $width, float $height): array
    {
        return compact('image', 'x', 'top', 'width', 'height');
    }

    /** @param list<array<string, mixed>> $images */
    private function photo(array &$images, ?string $dataUri, float $x, float $top, float $width, float $height): void
    {
        if ($dataUri === null) {
            return;
        }
        $image = @imagecreatefromstring(base64_decode(explode(',', $dataUri, 2)[1], true));
        if ($image !== false) {
            $images[] = $this->image($image, $x, $top, $width, $height);
        }
    }

    private function template(string $filename): GdImage
    {
        $image = @imagecreatefromstring(file_get_contents(public_path('assets/card_and_Slip/'.$filename)));
        abort_if($image === false, 503, 'The slip template is unavailable.');
        imagepalettetotruecolor($image);

        return $image;
    }

    private function date(string $value): string
    {
        foreach (['d-m-Y', 'Y-m-d', 'd-M-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && $date->format($format) === $value) {
                return $date->format('d M Y');
            }
        }

        return $value;
    }

    /** @return array{text: string, x: float, y: float, size: int, font: string, width: float} */
    private function heading(string $text, float $x, float $y, float $width): array
    {
        return ['text' => $text, 'x' => $x, 'y' => $y, 'width' => $width, 'size' => 12, 'font' => 'F1'];
    }
}
