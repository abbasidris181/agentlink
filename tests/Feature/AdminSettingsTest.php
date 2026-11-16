<?php

namespace Tests\Feature;

use App\Livewire\Admin\Settings;
use App\Models\SiteSetting;
use App\Models\User;
use Database\Seeders\SiteSettingSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_settings_persist_and_are_applied_to_the_website(): void
    {
        $this->actingAs(User::factory()->administrator()->create());

        Livewire::test(Settings::class)->set('siteName', 'Configured Portal')
            ->set('supportEmail', 'support@example.com')->set('supportPhone', '+2348012345678')
            ->set('whatsappNumber', '2348012345678')->set('announcement', 'Service notice for customers.')
            ->call('save')->assertHasNoErrors()->assertSee('Site settings saved.');

        $this->assertDatabaseHas('site_settings', ['key' => 'site_name', 'value' => 'Configured Portal']);
        $this->assertDatabaseHas('admin_audits', ['event' => 'settings.updated']);
        $this->assertDatabaseHas('site_settings', ['key' => 'support_email', 'value' => 'support@example.com']);
        $this->get(route('dashboard'))->assertOk()->assertSee('Configured Portal')
            ->assertSee('Service notice for customers.')
            ->assertSee('https://wa.me/2348012345678', false);
    }

    #[TestWith(['https://wa.me/2348098765432?text=Hello%20support'])]
    #[TestWith(['https://chat.whatsapp.com/ExampleInviteCode'])]
    #[TestWith(['https://wa.link/example'])]
    public function test_whatsapp_widget_opens_the_saved_link_directly_without_a_popup(string $url): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Settings::class)->set('whatsappUrl', $url)->set('whatsappNumber', '2348012345678')
            ->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('site_settings', ['key' => 'whatsapp_url', 'value' => $url]);
        Livewire::test(Settings::class)->assertSet('whatsappUrl', $url);
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('id="whatsapp-support-link" href="'.e($url).'"', false)
            ->assertSee('target="_blank" rel="noopener noreferrer"', false)
            ->assertSee('whatsapp-support-button')
            ->assertDontSee('whatsapp-support-panel')->assertDontSee('chatOpen')
            ->assertDontSee('href="https://wa.me/2348012345678"', false);
    }

    #[TestWith(['javascript:alert(1)'])]
    #[TestWith(['data:text/html,<script>alert(1)</script>'])]
    #[TestWith(['ftp://example.com'])]
    #[TestWith(['not-a-url'])]
    public function test_whatsapp_link_rejects_non_web_urls(string $url): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Settings::class)->set('whatsappUrl', $url)->call('save')->assertHasErrors('whatsappUrl');
        $this->assertDatabaseCount('site_settings', 0);
    }

    public function test_saved_number_is_used_when_the_link_is_empty(): void
    {
        SiteSetting::create(['key' => 'whatsapp_number', 'value' => '+2348012345678']);
        SiteSetting::create(['key' => 'whatsapp_url', 'value' => '']);
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('id="whatsapp-support-link" href="https://wa.me/2348012345678"', false)
            ->assertDontSee('whatsapp-support-panel');
    }

    public function test_unconfigured_whatsapp_widget_is_hidden(): void
    {
        SiteSetting::create(['key' => 'whatsapp_number', 'value' => '']);
        SiteSetting::create(['key' => 'whatsapp_url', 'value' => '']);
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()->assertDontSee('id="whatsapp-support-link"', false)
            ->assertDontSee('whatsapp-support-panel');
    }

    public function test_legacy_configuration_cannot_supply_an_unsaved_whatsapp_destination(): void
    {
        config(['services.whatsapp.number' => '2348012345678', 'services.whatsapp.url' => 'https://wa.me/2348012345678']);
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Settings::class)->assertSet('whatsappNumber', '')->assertSet('whatsappUrl', '');
        $this->get(route('dashboard'))->assertOk()->assertDontSee('id="whatsapp-support-link"', false);
    }

    public function test_settings_seeder_does_not_add_a_default_whatsapp_destination_or_overwrite_admin_values(): void
    {
        config(['services.whatsapp.number' => '2348012345678']);
        $this->seed(SiteSettingSeeder::class);
        $this->assertDatabaseHas('site_settings', ['key' => 'whatsapp_number', 'value' => '']);
        $this->assertDatabaseHas('site_settings', ['key' => 'whatsapp_url', 'value' => '']);
        SiteSetting::query()->where('key', 'whatsapp_url')->update(['value' => 'https://wa.link/admin-saved-link']);
        $this->seed(SiteSettingSeeder::class);
        $this->assertDatabaseHas('site_settings', ['key' => 'whatsapp_url', 'value' => 'https://wa.link/admin-saved-link']);
    }

    public function test_invalid_stored_link_cannot_inject_a_script_into_the_widget(): void
    {
        SiteSetting::create(['key' => 'whatsapp_url', 'value' => 'javascript:alert(1)']);
        SiteSetting::create(['key' => 'whatsapp_number', 'value' => '2348012345678']);
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('href="https://wa.me/2348012345678"', false)
            ->assertDontSee('javascript:alert(1)', false);
    }

    public function test_settings_viewer_cannot_change_the_whatsapp_destination(): void
    {
        $this->actingAs(User::factory()->staff(['settings.view'])->create());
        Livewire::test(Settings::class)->set('whatsappUrl', 'https://wa.me/2348098765432')->call('save')->assertForbidden();
        $this->assertDatabaseCount('site_settings', 0);
    }

    public function test_invalid_settings_are_rejected_before_writing_any_values(): void
    {
        $this->actingAs(User::factory()->administrator()->create());

        Livewire::test(Settings::class)->set('siteName', ' ')->set('supportEmail', 'invalid')
            ->set('whatsappNumber', 'javascript:alert(1)')->call('save')
            ->assertHasErrors(['siteName', 'supportEmail', 'whatsappNumber']);

        $this->assertDatabaseCount('site_settings', 0);
    }

    public function test_announcements_are_escaped_on_the_customer_dashboard(): void
    {
        $this->actingAs(User::factory()->administrator()->create());

        Livewire::test(Settings::class)->set('announcement', '<script>alert(1)</script>')->call('save')->assertHasNoErrors();
        $this->get(route('dashboard'))->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
