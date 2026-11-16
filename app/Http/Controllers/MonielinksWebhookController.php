<?php

namespace App\Http\Controllers;

use App\MonielinksFundingService;
use App\MonielinksSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JsonException;

class MonielinksWebhookController extends Controller
{
    public function __invoke(Request $request, MonielinksFundingService $funding): JsonResponse
    {
        $settings = MonielinksSettings::get();
        if ($settings['client_id'] === '' || $settings['secret_key'] === '') {
            return response()->json(['message' => 'Payment integration is not configured.'], 503);
        }
        $raw = $request->getContent();
        if (strlen($raw) > 262144) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }
        $signature = $request->header('X-Signature');
        $signatureVerified = $settings['webhook_secret'] !== '' && is_string($signature)
            && preg_match('/\A[a-f0-9]{64}\z/i', $signature)
            && hash_equals(hash_hmac('sha256', $raw, $settings['webhook_secret']), strtolower($signature));
        if ($settings['webhook_secret'] !== '' && ! $signatureVerified) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        try {
            $event = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($event) || array_is_list($event) || ! is_string($event['event'] ?? null)) {
                return response()->json(['message' => 'Invalid webhook payload.'], 400);
            }
            $payment = $funding->receive($event, (bool) $signatureVerified);

            return response()->json(['status' => $payment ? 'received' : 'ignored']);
        } catch (JsonException) {
            return response()->json(['message' => 'Invalid JSON.'], 400);
        } catch (ValidationException) {
            return response()->json(['message' => 'Invalid payment details.'], 422);
        }
    }
}
