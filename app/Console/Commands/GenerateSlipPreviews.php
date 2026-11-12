<?php

namespace App\Console\Commands;

use App\BvnSlipRenderer;
use App\Models\BvnVerificationRecord;
use App\Models\NinVerificationRecord;
use App\NinSlipRenderer;
use GdImage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('slips:previews')]
#[Description('Generate sample slip previews using fictional identity details')]
class GenerateSlipPreviews extends Command
{
    public function handle(NinSlipRenderer $ninRenderer, BvnSlipRenderer $bvnRenderer): int
    {
        $sample = [
            'firstName' => 'JOHN', 'middleName' => 'A', 'lastName' => 'SAMPLE',
            'idNumber' => '00000000000', 'nin' => '00000000000', 'mobile' => '08000000000',
            'dateOfBirth' => '01-01-1990', 'gender' => 'M', 'trackingId' => 'SAMPLE001',
            'address' => ['addressLine' => '12 SAMPLE STREET', 'town' => 'SAMPLE TOWN', 'state' => 'SAMPLE STATE', 'lga' => 'SAMPLE LGA'],
            'birthState' => 'SAMPLE STATE', 'birthLGA' => 'SAMPLE LGA',
            'email' => 'sample@example.test', 'enrollmentBank' => 'SAMPLE BANK', 'enrollmentBranch' => 'SAMPLE BRANCH',
            'registrationDate' => '01-01-2020', 'photo' => $this->portrait(), 'signature' => $this->signature(),
        ];
        $nin = new NinVerificationRecord(['result' => $sample]);
        $bvn = new BvnVerificationRecord(['result' => $sample]);
        $nin->created_at = Carbon::create(2026, 1, 1);
        $bvn->created_at = Carbon::create(2026, 1, 1);

        foreach (NinVerificationRecord::SLIPS as $key => $slip) {
            $this->savePreview($ninRenderer->preview($nin, $key), $slip['sample']);
        }
        foreach (BvnVerificationRecord::SLIPS as $key => $slip) {
            $this->savePreview($bvnRenderer->preview($bvn, $key), $slip['sample']);
        }
        $this->info('Generated six sample slip previews.');

        return self::SUCCESS;
    }

    private function portrait(): string
    {
        $image = imagecreatetruecolor(240, 320);
        imagefill($image, 0, 0, imagecolorallocate($image, 226, 232, 240));
        $silhouette = imagecolorallocate($image, 100, 116, 139);
        imagefilledellipse($image, 120, 110, 90, 110, $silhouette);
        imagefilledellipse($image, 120, 290, 200, 230, $silhouette);

        return $this->dataUri($image);
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(360, 120);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagettftext($image, 32, -8, 15, 70, imagecolorallocate($image, 35, 45, 60), public_path('assets/fonts/Roboto-Regular.ttf'), 'J. Sample');

        return $this->dataUri($image);
    }

    private function dataUri(GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function savePreview(string $png, string $filename): void
    {
        $source = imagecreatefromstring($png);
        $width = min(900, imagesx($source));
        $height = (int) round(imagesy($source) * $width / imagesx($source));
        $preview = imagecreatetruecolor($width, $height);
        imagecopyresampled($preview, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        $font = public_path('assets/fonts/Roboto-Bold.ttf');
        $fontSize = $width / 14;
        $color = imagecolorallocatealpha($preview, 90, 100, 110, 75);
        imagettftext($preview, $fontSize, 18, (int) ($width * 0.33), (int) ($height * 0.65), $color, $font, 'SAMPLE');
        $path = public_path('assets/card_and_Slip/'.$filename);
        $saved = str_ends_with($filename, '.jpg') ? imagejpeg($preview, $path, 90) : imagepng($preview, $path, 8);
        if (! $saved) {
            throw new \RuntimeException('Unable to save sample preview: '.$filename);
        }
    }
}
