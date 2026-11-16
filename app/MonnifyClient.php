<?php

namespace App;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class MonnifyClient
{
    private ?string $token = null;

    /** @param array<string, mixed> $settings */
    public function __construct(private array $settings) {}

    /** @param array<string, mixed> $data */
    public function request(string $operation, string $method, string $path, array $data = []): Response
    {
        $this->token ??= $this->authenticate();
        try {
            $request = Http::acceptJson()->asJson()->withToken($this->token)->connectTimeout(5)->timeout(20)->withoutRedirecting();
            $url = MonnifySettings::baseUrl($this->settings).$path;
            $response = $method === 'GET' ? $request->get($url, $data) : $request->post($url, $data);
            ProviderResponseLogger::response('monnify', $response, ['operation' => $operation], [$this->settings['api_key'], $this->settings['secret_key'], $this->token]);

            return $response;
        } catch (Throwable $exception) {
            ProviderResponseLogger::failure('monnify', $exception, ['operation' => $operation]);
            throw new RuntimeException('Monnify could not be reached.');
        }
    }

    private function authenticate(): string
    {
        try {
            $response = Http::acceptJson()->asJson()->withBasicAuth($this->settings['api_key'], $this->settings['secret_key'])
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->send('POST', MonnifySettings::baseUrl($this->settings).'/api/v1/auth/login');
            ProviderResponseLogger::response('monnify', $response, ['operation' => 'authenticate'], [
                $this->settings['api_key'], $this->settings['secret_key'], base64_encode($this->settings['api_key'].':'.$this->settings['secret_key']),
            ]);
            $body = $response->json();
            $token = is_array($body) ? ($body['responseBody']['accessToken'] ?? null) : null;
            if (! $response->successful() || ! is_array($body) || ($body['requestSuccessful'] ?? null) !== true
                || ! in_array($body['responseCode'] ?? null, ['0', 0], true) || ! is_string($token) || $token === '' || preg_match('/[\r\n]/', $token)) {
                throw new RuntimeException('Monnify authentication failed.');
            }

            return $token;
        } catch (Throwable $exception) {
            ProviderResponseLogger::failure('monnify', $exception, ['operation' => 'authenticate']);
            throw new RuntimeException('Monnify authentication could not be confirmed.');
        }
    }

    /** @return array<string, mixed>|null */
    public static function body(Response $response): ?array
    {
        $body = $response->json();

        return $response->successful() && is_array($body) && ($body['requestSuccessful'] ?? null) === true
            && in_array($body['responseCode'] ?? null, ['0', 0], true) && is_array($body['responseBody'] ?? null)
            && ! array_is_list($body['responseBody']) ? $body['responseBody'] : null;
    }
}
