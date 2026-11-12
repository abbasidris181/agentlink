<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProxyAssetUrlsTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['172.20.0.1', 'https', 'https'])]
    #[TestWith(['127.0.0.1', 'https', 'https'])]
    #[TestWith(['172.20.0.1', 'http', 'http'])]
    #[TestWith(['203.0.113.10', 'https', 'http'])]
    public function test_asset_and_navigation_urls_use_the_scheme_from_trusted_proxies_only(string $remoteAddress, string $forwardedScheme, string $expectedScheme): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress, 'HTTP_HOST' => 'preview.trycloudflare.com', 'SERVER_PORT' => 80, 'HTTPS' => 'off'])
            ->withHeaders(['X-Forwarded-Proto' => $forwardedScheme])
            ->get('http://preview.trycloudflare.com/')
            ->assertOk();
        $response->assertSee($expectedScheme.'://preview.trycloudflare.com/build/assets/', false)
            ->assertSee($expectedScheme.'://preview.trycloudflare.com/register', false)
            ->assertDontSee(($expectedScheme === 'https' ? 'http' : 'https').'://preview.trycloudflare.com/build/assets/', false);
    }

    public function test_local_http_still_loads_assets_without_forwarded_headers(): void
    {
        $this->get('http://localhost/')->assertOk()->assertSee('http://localhost/build/assets/', false);
    }
}
