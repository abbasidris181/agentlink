<?php

namespace App;

use App\Mail\AdminBroadcast;
use App\Models\AdminNotificationSend;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class NotificationSender
{
    public static function batchSize(string $channel): int
    {
        return match ($channel) {
            'email' => 5,
            'phone' => 500,
            default => 1,
        };
    }

    /** @return array{url: string, token: string} */
    public function whatsAppSettings(): array
    {
        $settings = [
            'url' => (string) config('services.whatsapp.send_url'),
            'token' => (string) config('services.whatsapp.send_token'),
        ];
        Validator::make($settings, [
            'url' => ['required', 'url:https', function (string $attribute, mixed $value, \Closure $fail): void {
                $parts = parse_url($value);
                if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                    $fail('Use the WhatsApp HTTPS endpoint without embedded credentials or fragments.');
                }
            }],
            'token' => ['required', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
        ], [
            'url.required' => 'Configure WA_SEND_URL before sending WhatsApp notifications.',
            'token.required' => 'Configure WA_SEND_TOKEN before sending WhatsApp notifications.',
        ])->validate();

        return $settings;
    }

    public function validateHosting(): void
    {
        $mailer = config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");
        if (! $transport || in_array($transport, ['log', 'array', 'failover', 'roundrobin'], true)) {
            throw ValidationException::withMessages(['provider' => 'Configure the hosting mail transport before sending. Logging and fallback transports cannot confirm acceptance.']);
        }
        $sender = config("mail.mailers.{$mailer}.from.address", config('mail.from.address'));
        if (! filter_var($sender, FILTER_VALIDATE_EMAIL) || $sender === 'hello@example.com' || config('mail.to.address') || config("mail.mailers.{$mailer}.to.address")) {
            throw ValidationException::withMessages(['provider' => 'Configure this website sender address and remove any global recipient override before sending.']);
        }
    }

    public function confirm(AdminNotificationSend $send): void
    {
        DB::transaction(function () use ($send): void {
            $locked = AdminNotificationSend::query()->lockForUpdate()->findOrFail($send->id);
            if ($locked->status === 'draft') {
                $locked->update(['status' => 'ready', 'confirmed_at' => now()]);
            }
        });
    }

    /**
     * Claim one batch. Observing an unfinished batch stops further sends.
     * Sending or uncertain contacts can never be claimed again.
     */
    public function next(AdminNotificationSend $send): AdminNotificationSend
    {
        $indexes = DB::transaction(function () use ($send): array {
            $locked = AdminNotificationSend::query()->lockForUpdate()->findOrFail($send->id);
            if ($locked->status === 'sending') {
                $this->stopLocked($locked);

                return [];
            }
            if ($locked->status !== 'ready' || ! $locked->confirmed_at) {
                return [];
            }
            $indexes = [];
            foreach ($locked->recipients as $index => $recipient) {
                if ($recipient['status'] === 'pending') {
                    $indexes[] = $index;
                    if (count($indexes) === self::batchSize($locked->channel)) {
                        break;
                    }
                }
            }
            $locked->update(['status' => $indexes ? 'sending' : 'completed']);

            return $indexes;
        });

        if (! $indexes) {
            return $send->refresh();
        }
        $send->refresh();
        if ($send->channel === 'email') {
            foreach ($indexes as $index) {
                if (! $this->markAttempt($send, [$index])) {
                    break;
                }
                try {
                    $sent = Mail::to($send->recipients[$index]['contact'])->send(new AdminBroadcast($send->payload['subject'], $send->payload['message']));
                    $this->record($send, [$index], $sent === null ? 'uncertain' : 'accepted');
                    if ($sent === null) {
                        break;
                    }
                } catch (Throwable) {
                    $this->record($send, [$index], 'uncertain');
                    break;
                }
            }
        } elseif ($send->channel === 'phone' && $this->markAttempt($send, $indexes)) {
            $this->record($send, $indexes, $this->sms($send, $indexes));
        } elseif ($send->channel === 'whatsapp' && $this->markAttempt($send, $indexes)) {
            $this->record($send, $indexes, $this->whatsapp($send, $indexes[0]));
        }

        DB::transaction(function () use ($send): void {
            $locked = AdminNotificationSend::query()->lockForUpdate()->findOrFail($send->id);
            if ($locked->status === 'sending') {
                $pending = collect($locked->recipients)->contains(fn (array $recipient): bool => $recipient['status'] === 'pending');
                $locked->update(['status' => $pending ? 'ready' : 'completed']);
            }
        });

        return $send->refresh();
    }

    public function stop(AdminNotificationSend $send): AdminNotificationSend
    {
        DB::transaction(function () use ($send): void {
            $locked = AdminNotificationSend::query()->lockForUpdate()->findOrFail($send->id);
            if (in_array($locked->status, ['ready', 'sending'], true)) {
                $this->stopLocked($locked);
            }
        });

        return $send->refresh();
    }

    private function stopLocked(AdminNotificationSend $send): void
    {
        $recipients = $send->recipients;
        foreach ($recipients as &$recipient) {
            if ($recipient['status'] === 'sending') {
                $recipient['status'] = 'uncertain';
            }
        }
        $send->update(['status' => 'stopped', 'recipients' => $recipients]);
    }

    /** @param list<int> $indexes */
    private function markAttempt(AdminNotificationSend $send, array $indexes): bool
    {
        return DB::transaction(function () use ($send, $indexes): bool {
            $locked = AdminNotificationSend::query()->lockForUpdate()->findOrFail($send->id);
            if ($locked->status !== 'sending') {
                return false;
            }
            $recipients = $locked->recipients;
            foreach ($indexes as $index) {
                if ($recipients[$index]['status'] !== 'pending') {
                    return false;
                }
                $recipients[$index]['status'] = 'sending';
            }
            $locked->update(['recipients' => $recipients]);

            return true;
        });
    }

    /** @param list<int> $indexes */
    private function record(AdminNotificationSend $send, array $indexes, string $outcome): void
    {
        DB::transaction(function () use ($send, $indexes, $outcome): void {
            $locked = AdminNotificationSend::query()->lockForUpdate()->findOrFail($send->id);
            $recipients = $locked->recipients;
            foreach ($indexes as $index) {
                $recipients[$index]['status'] = $outcome;
            }
            $locked->update(['recipients' => $recipients, 'status' => $outcome === 'uncertain' ? 'stopped' : $locked->status]);
        });
    }

    private function whatsapp(AdminNotificationSend $send, int $index): string
    {
        $settings = $send->payload['whatsapp'];
        try {
            $response = Http::acceptJson()->asJson()->withToken($settings['token'])
                ->connectTimeout(10)->timeout(35)->withoutRedirecting()
                ->post($settings['url'], [
                    'to' => $send->recipients[$index]['contact'],
                    'text' => $send->payload['message'],
                ]);
            ProviderResponseLogger::response('whatsapp', $response, ['send_id' => $send->id, 'recipient_index' => $index], [$settings['token']]);
            $body = $response->json();
            if (! is_array($body) || array_is_list($body)) {
                return 'uncertain';
            }
            $status = is_string($body['status'] ?? null) ? strtolower(trim($body['status'])) : '';
            $failed = ($body['success'] ?? null) === false || in_array($status, ['error', 'failed', 'failure', 'rejected'], true);
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            $accepted = ($body['success'] ?? null) === true
                && is_int($data['msgId'] ?? null) && $data['msgId'] > 0
                && is_string($data['jid'] ?? null)
                && NotificationContact::normalize($data['jid'], 'whatsapp') === $send->recipients[$index]['contact']
                && ($data['status'] ?? null) === 'in_progress';
            $consistentStatus = ! isset($body['status']) || in_array($status, ['success', 'accepted', 'queued'], true);
            if ($response->successful() && $accepted && ! $failed && $consistentStatus && empty($body['error']) && empty($body['errors'])
                && ! preg_match('/\b(?:partial|partially|uncertain)\b/i', is_string($body['message'] ?? null) ? $body['message'] : '')) {
                return 'accepted';
            }
            if (in_array($response->status(), [400, 401, 403, 404, 413, 422, 429], true) && $failed && ! $accepted) {
                return 'failed';
            }

            return 'uncertain';
        } catch (Throwable $exception) {
            if (! isset($response)) {
                ProviderResponseLogger::failure('whatsapp', $exception, ['send_id' => $send->id, 'recipient_index' => $index]);
            }

            return 'uncertain';
        }
    }

    /** @param list<int> $indexes */
    private function sms(AdminNotificationSend $send, array $indexes): string
    {
        $settings = $send->payload['parrotica'];
        $numbers = array_map(fn (int $index): string => $send->recipients[$index]['contact'], $indexes);
        try {
            $response = Http::acceptJson()->asJson()->withHeaders(['x-api-key' => $settings['api_key']])
                ->connectTimeout(10)->timeout(35)->withoutRedirecting()
                ->post(rtrim($settings['url'], '/').'/v1/message/'.rawurlencode($settings['user_id']).'/send', [
                    'content' => $send->payload['message'], 'senderId' => $settings['sender_id'], 'numbers' => $numbers,
                ]);
            ProviderResponseLogger::response('parrotica-sms', $response, ['send_id' => $send->id, 'recipient_count' => count($numbers)], [$settings['api_key']]);
            $body = $response->json();
            $document = json_decode($response->body(), flags: JSON_THROW_ON_ERROR);
            if (! is_array($body) || array_is_list($body)) {
                return 'uncertain';
            }
            /** The provider defines no per-number result schema; never infer partial acceptance. */
            if (array_diff(array_keys($body), ['code', 'message', 'status', 'data']) !== []) {
                return 'uncertain';
            }
            $status = is_string($body['status'] ?? null) ? strtolower(trim($body['status'])) : '';
            if ($response->successful() && $status === 'success'
                && is_array($body['data'] ?? null)
                && array_keys($body['data']) === ['invalidContacts']
                && $body['data']['invalidContacts'] === []
                && is_array($document->data->invalidContacts ?? null)
                && ! preg_match('/\b(?:partial|partially|uncertain)\b/i', is_string($body['message'] ?? null) ? $body['message'] : '')
                && (! isset($body['code']) || in_array((string) $body['code'], ['0', '200', '201', '202'], true))) {
                return 'accepted';
            }
            if (in_array($response->status(), [400, 401, 403, 404, 413, 422, 429], true)
                && in_array($status, ['failed', 'failure', 'error', 'rejected'], true)) {
                return 'failed';
            }

            return 'uncertain';
        } catch (Throwable $exception) {
            if (! isset($response)) {
                ProviderResponseLogger::failure('parrotica-sms', $exception, ['send_id' => $send->id, 'recipient_count' => count($numbers)]);
            }

            return 'uncertain';
        }
    }
}
