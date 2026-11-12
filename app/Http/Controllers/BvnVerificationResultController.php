<?php

namespace App\Http\Controllers;

use App\BvnSlipRenderer;
use App\Models\BvnVerificationRecord;
use App\Money;
use App\SlipDownloadService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BvnVerificationResultController extends Controller
{
    public function show(Request $request, BvnVerificationRecord $verification, SlipDownloadService $downloads): Response
    {
        $this->authorizeResult($request, $verification);

        return response()->view('verification-result', [
            'verification' => $verification, 'slips' => $downloads->optionsFor($verification),
            'resultLabel' => 'BVN', 'backRoute' => 'bvn.verify', 'slipRoute' => 'bvn.slip', 'downloadFormat' => 'PDF',
        ])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function download(Request $request, BvnVerificationRecord $verification, string $slip, BvnSlipRenderer $renderer, SlipDownloadService $downloads): Response
    {
        $this->authorizeResult($request, $verification);
        abort_unless(array_key_exists($slip, BvnVerificationRecord::SLIPS), 404);

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
            'Content-Disposition' => 'attachment; filename="bvn-'.$slip.'-'.$verification->reference.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeResult(Request $request, BvnVerificationRecord $verification): void
    {
        abort_unless($request->user()->fresh()->is_active, 403);
        abort_unless($verification->user_id === $request->user()->id && $verification->status === 'successful', 404);
    }
}
