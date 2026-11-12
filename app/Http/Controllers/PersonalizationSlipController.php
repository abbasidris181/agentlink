<?php

namespace App\Http\Controllers;

use App\Models\PersonalizationRequest;
use App\Money;
use App\NinSlipRenderer;
use App\SlipDownloadService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PersonalizationSlipController extends Controller
{
    public function __invoke(Request $request, PersonalizationRequest $personalization, string $slip, NinSlipRenderer $renderer, SlipDownloadService $downloads): Response
    {
        abort_unless($request->user()->fresh()->is_active, 403);
        abort_unless($personalization->user_id === $request->user()->id && $personalization->status === 'successful' && $personalization->result !== null, 404);
        $expectedPrice = null;
        if ($request->isMethod('post')) {
            $validated = $request->validate(['expected_price' => ['required', 'integer', 'min:0', 'max:'.Money::MAX_MINOR]]);
            $expectedPrice = (int) $validated['expected_price'];
        } else {
            abort_unless($downloads->canDownload($personalization, $slip), 403, 'Purchase this slip from the personalization result before downloading.');
        }
        $content = $renderer->render($personalization, $slip);
        if ($expectedPrice !== null) {
            $downloads->purchase($request->user(), $personalization, $slip, $expectedPrice);
        }

        return response($content)->withHeaders([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="personalization-'.$slip.'-'.$personalization->reference.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
