<?php

namespace App\Http\Controllers;

use App\Models\NinVerificationRecord;
use App\Money;
use App\NinSlipRenderer;
use App\SlipDownloadService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NinVerificationResultController extends Controller
{
    public function show(Request $request, NinVerificationRecord $verification, SlipDownloadService $downloads): Response
    {
        $this->authorizeResult($request, $verification);

        return response()->view('nin-verification-result', [
            'verification' => $verification, 'slips' => $downloads->optionsFor($verification),
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function download(Request $request, NinVerificationRecord $verification, string $slip, NinSlipRenderer $renderer, SlipDownloadService $downloads): Response
    {
        $this->authorizeResult($request, $verification);
        abort_unless(array_key_exists($slip, NinVerificationRecord::SLIPS), 404);

        $expectedPrice = null;
        if ($request->isMethod('post')) {
            $validated = $request->validate(['expected_price' => ['required', 'integer', 'min:0', 'max:'.Money::MAX_MINOR]]);
            $expectedPrice = (int) $validated['expected_price'];
        } else {
            abort_unless($downloads->canDownload($verification, $slip), 403, 'Purchase this slip from the verification result page before downloading.');
        }
        $content = $renderer->render($verification, $slip);
        if ($expectedPrice !== null) {
            $downloads->purchase($request->user(), $verification, $slip, $expectedPrice);
        }

        return response($content)->withHeaders([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="nin-'.$slip.'-'.$verification->reference.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeResult(Request $request, NinVerificationRecord $verification): void
    {
        abort_unless($request->user()->fresh()->is_active, 403);
        abort_unless($verification->user_id === $request->user()->id && $verification->status === 'successful'
            && $request->routeIs('nin.'.$verification->version.'.*'), 404);
    }
}
