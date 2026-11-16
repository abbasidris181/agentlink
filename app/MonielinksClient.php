<?php

namespace App;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class MonielinksClient
{
    public const BASE_URL = 'https://monielinks.com/pay/api/v1';

    /** @param array<string, mixed> $settings */
    public function __construct(private array $settings) {}

    public function authenticate(): string
    {
        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post(self::BASE_URL.'/authentication/token', ['client_id' => $this->settings['client_id'], 'secret_id' => $this->settings['secret_key']]);
            ProviderResponseLogger::response('monielinks', $response, ['operation' => 'authenticate'], [$this->settings['client_id'], $this->settings['secret_key'], $this->settings['webhook_secret']]);
            $token = $response->json('data.access_token');
            if (! $response->successful() || ! is_string($token) || $token === '' || preg_match('/[\r\n]/', $token)) {
                throw new RuntimeException('Monielinks authentication failed.');
            }

            return $token;
        } catch (Throwable $exception) {
            ProviderResponseLogger::failure('monielinks', $exception, ['operation' => 'authenticate']);
            throw new RuntimeException('Monielinks authentication could not be confirmed. Please try again later.');
        }
    }

    /** @param array{email: string, firstName: string, lastName: string, bank: string} $data */
    public function createAccount(string $token, array $data): Response
    {
        try {
            $response = Http::acceptJson()->asJson()->withToken($token)->connectTimeout(5)->timeout(20)->withoutRedirecting()
                ->post(self::BASE_URL.'/virtual-account/create', $data);
            ProviderResponseLogger::response('monielinks', $response, ['operation' => 'create-account'], [$this->settings['client_id'], $this->settings['secret_key'], $this->settings['webhook_secret'], $token]);

            return $response;
        } catch (Throwable $exception) {
            ProviderResponseLogger::failure('monielinks', $exception, ['operation' => 'create-account']);
            throw new RuntimeException('The account request outcome is uncertain. Contact support before trying again.');
        }
    }
}
