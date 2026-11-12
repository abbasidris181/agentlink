<?php

namespace App;

use App\Models\BvnVerificationRecord;
use GdImage;
use Illuminate\Support\Str;

class BvnSlipRenderer
{
    public function __construct(private RasterPdf $pdf) {}

    public function render(BvnVerificationRecord $verification, string $slip): string
    {
        $image = $this->populatedImage($verification, $slip);
        if ($slip === 'slip') {
            return $this->pdf->render([['image' => $image, 'x' => 30, 'top' => 30, 'width' => 535, 'height' => 535 * imagesy($image) / imagesx($image)]]);
        }

        return $this->pdf->render([
            ['image' => $image, 'x' => 30, 'top' => 30, 'width' => 245, 'height' => 163],
            ['image' => $this->template('finger.jpg'), 'x' => 30, 'top' => 210, 'width' => 245, 'height' => 163],
        ]);
    }

    public function preview(BvnVerificationRecord $verification, string $slip): string
    {
        $image = $this->populatedImage($verification, $slip);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function populatedImage(BvnVerificationRecord $verification, string $slip): GdImage
    {
        abort_unless(extension_loaded('gd'), 503, 'Slip downloads are temporarily unavailable.');
        $image = $this->template(BvnVerificationRecord::SLIPS[$slip]['preview']);
        $field = $verification->field(...);

        if ($slip === 'slip') {
            $this->text($image, $verification->created_at->format('d M Y'), 695, 166, 270, 18);
            $this->photo($image, $verification->image('photo'), 18, 200, 302, 370);
            $fields = [
                $field('idNumber'), $field('nin'), $field('firstName'), $field('lastName'), $field('middleName'),
                $field('mobile') ?: $field('phoneNumber'), $field('email'), $field('dateOfBirth'), $field('gender'),
                $field('enrollmentBank'), $field('enrollmentBranch'), $field('registrationDate'), $field('address.addressLine'),
            ];
            foreach ($fields as $index => $value) {
                $this->text($image, $value ?: '-', 580, 255 + 43.8 * $index, 390, 18);
            }

            return $image;
        }

        $this->photo($image, $verification->image('photo'), 53, 241, 224, 289);
        $this->text($image, $field('lastName'), 320, 285, 405, 23);
        $this->text($image, trim($field('firstName').' '.$field('middleName')), 320, 382, 405, 22);
        $this->text($image, $field('dateOfBirth'), 320, 485, 250, 22);
        $this->text($image, $field('gender'), 590, 485, 130, 22);
        $this->text($image, trim(chunk_split($field('idNumber'), 4, ' ')), 290, 605, 440, 32);
        $scale = imagesx($image) / 1000;
        imagecopy($image, $image, (int) (780 * $scale), (int) (375 * $scale), (int) (780 * $scale), (int) (475 * $scale), (int) (205 * $scale), (int) (75 * $scale));
        $this->text($image, 'VERIFIED ON', 790, 400, 185, 16);
        $this->text($image, $verification->created_at->format('d M Y'), 790, 437, 185, 18);

        return $image;
    }

    private function template(string $filename): GdImage
    {
        $image = @imagecreatefromstring(file_get_contents(public_path('assets/card_and_Slip/'.$filename)));
        abort_if($image === false, 503, 'The slip template is unavailable.');

        return $image;
    }

    private function text(GdImage $image, string $value, float $x, float $y, float $width, float $size): void
    {
        $scale = imagesx($image) / 1000;
        $font = public_path('assets/fonts/Roboto-Regular.ttf');
        $value = Str::limit(preg_replace('/[\r\n\t]+/', ' ', $value), 200, '');
        if (trim($value) === '') {
            return;
        }
        $fontSize = $size * $scale;
        do {
            $bounds = imagettfbbox($fontSize, 0, $font, $value);
            if ($width * $scale >= $bounds[2] - $bounds[0]) {
                break;
            }
            $fontSize -= 0.5;
        } while ($fontSize > 5 * $scale);
        imagettftext($image, $fontSize, 0, (int) ($x * $scale), (int) ($y * $scale), imagecolorallocate($image, 15, 20, 25), $font, $value);
    }

    private function photo(GdImage $image, ?string $dataUri, float $x, float $y, float $width, float $height): void
    {
        if ($dataUri === null) {
            return;
        }
        $source = @imagecreatefromstring(base64_decode(explode(',', $dataUri, 2)[1], true));
        if ($source === false) {
            return;
        }
        $scale = imagesx($image) / 1000;
        $ratio = min($width * $scale / imagesx($source), $height * $scale / imagesy($source));
        $targetWidth = (int) (imagesx($source) * $ratio);
        $targetHeight = (int) (imagesy($source) * $ratio);
        imagecopyresampled($image, $source, (int) ($x * $scale + ($width * $scale - $targetWidth) / 2),
            (int) ($y * $scale + ($height * $scale - $targetHeight) / 2), 0, 0,
            $targetWidth, $targetHeight, imagesx($source), imagesy($source));
    }
}
