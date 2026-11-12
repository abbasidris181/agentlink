<?php

namespace Tests\Feature;

use App\Models\NinVerificationRecord;
use App\NinSlipRenderer;
use App\SlipQrCode;
use GdImage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NinSlipLayoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_qr_codes_contain_identity_details_directly_without_a_website_link(): void
    {
        $record = NinVerificationRecord::factory()->create(['result' => $this->details()]);
        $payload = app(NinSlipRenderer::class)->qrDetails($record);

        $this->assertSame("NIN DETAILS\nNIN: 01234567890\nSurname: SAMPLE\nFirst name: JOHN\nMiddle name: A\nDate of birth: 01 Jan 1990\nGender: M", $payload);
        $this->assertStringNotContainsString('http', $payload);
        $this->get('/n/'.$record->reference)->assertNotFound();
        if (getenv('NIN_SLIP_QA_DIR')) {
            imagepng(app(SlipQrCode::class)->image($payload), getenv('NIN_SLIP_QA_DIR').'/qr.png');
            file_put_contents(getenv('NIN_SLIP_QA_DIR').'/expected-details.txt', $payload."\n");
        }
    }

    #[TestWith(['regular', false, 2, [472.81891, 196.44098, 70.86615, 87.87402], [243.77988, 210.06183]])]
    #[TestWith(['premium', false, 4, [202.67717, 175.74805, 56.69292, 70.86615], [267.30743, 197.30597]])]
    #[TestWith(['standard', false, 4, [204.09448, 175.74805, 51.02362, 65.19686], [262.20508, 192.07599]])]
    #[TestWith(['digital', true, 3, [261.07086, 192.18896, 131.81104, 138.89765], [147.40193, 270.99088]])]
    public function test_pdf_photo_rectangles_and_value_baselines_match_the_reference_documents(string $slip, bool $landscape, int $images, array $photoBounds, array $surnameOrigin): void
    {
        $record = NinVerificationRecord::factory()->create(['result' => $this->details()]);
        $this->actingAs($record->user);

        $response = $this->get(route('nin.v1.slip', ['verification' => $record->reference, 'slip' => $slip]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertDownload('nin-'.$slip.'-'.$record->reference.'.pdf');

        $pdf = $response->getContent();
        $pageHeight = $landscape ? 595.28 : 841.89;
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString($landscape ? '/MediaBox [0 0 841.89 595.28]' : '/MediaBox [0 0 595.28 841.89]', $pdf);
        $this->assertSame($images, substr_count($pdf, '/Subtype /Image'));
        preg_match_all('#q ([0-9.]+) 0 0 ([0-9.]+) ([0-9.]+) ([0-9.]+) cm /I[0-9]+ Do Q#', $pdf, $placements, PREG_SET_ORDER);
        $photo = $placements[1];
        $actualPhotoBounds = [(float) $photo[3], $pageHeight - (float) $photo[4] - (float) $photo[2], (float) $photo[1], (float) $photo[2]];
        foreach ($photoBounds as $index => $expected) {
            $this->assertEqualsWithDelta($expected, $actualPhotoBounds[$index], 0.002);
        }
        preg_match('#([-0-9.]+) ([-0-9.]+) Tm \(SAMPLE\)#', $pdf, $surname);
        $this->assertNotEmpty($surname);
        $this->assertEqualsWithDelta($surnameOrigin[0], (float) $surname[1], 0.001);
        $this->assertEqualsWithDelta($surnameOrigin[1], $pageHeight - (float) $surname[2], 0.001);
        if (getenv('NIN_SLIP_QA_DIR')) {
            file_put_contents(getenv('NIN_SLIP_QA_DIR').'/'.$slip.'.pdf', $pdf);
            imagepng(imagecreatefromstring(app(NinSlipRenderer::class)->preview($record, $slip)), getenv('NIN_SLIP_QA_DIR').'/'.$slip.'.png');
            file_put_contents(getenv('NIN_SLIP_QA_DIR').'/'.$slip.'-expected-details.txt', app(NinSlipRenderer::class)->qrDetails($record)."\n");
        }
    }

    public function test_long_regular_slip_fields_do_not_overflow_into_the_photo_or_label_columns(): void
    {
        $short = NinVerificationRecord::factory()->make(['result' => $this->details()]);
        $long = NinVerificationRecord::factory()->make(['result' => [
            ...$short->result,
            'firstName' => str_repeat('LONGFIRSTNAME ', 12),
            'middleName' => str_repeat('LONGMIDDLENAME ', 12),
            'lastName' => str_repeat('LONGSURNAME ', 12),
            'address' => [
                'addressLine' => str_repeat('A LONG ADDRESS WITH WORDS AND BUILDING DETAILS ', 12),
                'lga' => 'A VERY LONG LOCAL GOVERNMENT AREA NAME',
                'state' => 'A VERY LONG STATE NAME',
            ],
        ]]);
        $original = imagecreatefromstring(app(NinSlipRenderer::class)->preview($short, 'regular'));
        $preview = imagecreatefromstring(app(NinSlipRenderer::class)->preview($long, 'regular'));
        $photoX = (int) round((472.81891 - 42.51968) * 4);
        $photoY = (int) round((196.44098 - 141.73228) * 4);

        $this->assertSame(
            $this->pixels($original, $photoX + 2, $photoY + 2, 278, 345),
            $this->pixels($preview, $photoX + 2, $photoY + 2, 278, 345),
        );
        $this->assertSame(
            $this->pixels($original, (int) ((178 - 42.51968) * 4), (int) ((198 - 141.73228) * 4), 200, 330),
            $this->pixels($preview, (int) ((178 - 42.51968) * 4), (int) ((198 - 141.73228) * 4), 200, 330),
        );
        if (getenv('NIN_SLIP_QA_DIR')) {
            imagepng($preview, getenv('NIN_SLIP_QA_DIR').'/regular-long.png');
        }
    }

    #[TestWith([192])]
    #[TestWith([271])]
    #[TestWith([520])]
    public function test_qr_sizes_support_details_up_to_their_byte_capacity(int $length): void
    {
        $payload = str_repeat('a', $length);
        $image = app(SlipQrCode::class)->image($payload);
        $this->assertInstanceOf(GdImage::class, $image);
        if (getenv('NIN_SLIP_QA_DIR')) {
            imagepng($image, getenv('NIN_SLIP_QA_DIR').'/qr-'.$length.'.png');
            file_put_contents(getenv('NIN_SLIP_QA_DIR').'/qr-'.$length.'.txt', $payload."\n");
        }
    }

    public function test_oversized_qr_details_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(SlipQrCode::class)->image(str_repeat('a', 521));
    }

    private function pixels(GdImage $image, int $x, int $y, int $width, int $height): string
    {
        $pixels = '';
        for ($row = $y; $row < $y + $height; $row++) {
            for ($column = $x; $column < $x + $width; $column++) {
                $pixels .= pack('N', imagecolorat($image, $column, $row));
            }
        }

        return hash('sha256', $pixels);
    }

    /** @return array<string, mixed> */
    private function details(): array
    {
        $portrait = imagecreatetruecolor(180, 240);
        imagefill($portrait, 0, 0, imagecolorallocate($portrait, 120, 150, 180));
        ob_start();
        imagepng($portrait);
        $dataUri = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());

        return [
            'idNumber' => '01234567890', 'firstName' => 'JOHN', 'middleName' => 'A', 'lastName' => 'SAMPLE',
            'dateOfBirth' => '01-01-1990', 'gender' => 'M', 'trackingId' => '123456789012345',
            'photo' => $dataUri, 'signature' => $dataUri,
            'mobile' => '08000000000', 'address' => [
                'addressLine' => '12 SAMPLE STREET WITH A LONG BUILDING NAME AND ANOTHER ADDRESS LINE',
                'lga' => 'SAMPLE LGA', 'state' => 'SAMPLE STATE', 'town' => 'SAMPLE TOWN',
            ],
        ];
    }
}
