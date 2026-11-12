<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ApplySiteSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Schema::hasTable('site_settings')) {
            $settings = SiteSetting::query()->pluck('value', 'key');
            config([
                'app.name' => $settings->get('site_name') ?: config('app.name'),
                'site.support_email' => $settings->get('support_email', ''),
                'site.support_phone' => $settings->get('support_phone', ''),
                'site.announcement' => $settings->get('announcement', ''),
                'site.email_verification_enabled' => $settings->get('email_verification_enabled') === '1',
                'site.login_disabled' => $settings->get('login_disabled') === '1',
                'site.registration_disabled' => $settings->get('registration_disabled') === '1',
                'services.whatsapp.number' => $settings->get('whatsapp_number', config('services.whatsapp.number')),
            ]);
        }

        return $next($request);
    }
}
