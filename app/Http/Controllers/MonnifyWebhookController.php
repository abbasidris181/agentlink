<?php

namespace App\Http\Controllers;

use App\MonnifyFundingService;
use App\MonnifySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;

class MonnifyWebhookController extends Controller
{
    public function __invoke(Request $request, MonnifyFundingService $funding): JsonResponse
    {
        $settings = MonnifySettings::get();
        if ($settings['mode'] === 'sandbox') {
            return response()->json(['status' => 'ignored', 'message' => 'Sandbox events do not credit wallets.']);
        }
        try {
            MonnifySettings::validate($settings);
        } catch (ValidationException) {
            return response()->json(['message' => 'Payment integration is not configured.'], 503);
        }
        $raw = $request->getContent();
        if (strlen($raw) > 262144) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }
        $signature = $request->header('monnify-signature');
        if (! is_string($signature) || ! preg_match('/\A[a-f0-9]{128}\z/i', $signature)
            || ! hash_equals(hash_hmac('sha512', $raw, $settings['secret_key']), strtolower($signature))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        try {
            $event = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($event) || array_is_list($event) || ! is_string($event['eventType'] ?? null)) {
                return response()->json(['message' => 'Invalid webhook payload.'], 400);
            }
            $payment = $funding->receive($event, $settings);

            return response()->json(['status' => $payment ? 'received' : 'ignored']);
        } catch (JsonException) {
            return response()->json(['message' => 'Invalid JSON.'], 400);
        } catch (ValidationException) {
            return response()->json(['message' => 'Invalid payment details.'], 422);
        }
    }
}
