<?php

namespace Tests\Feature;

use App\Livewire\Admin\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
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
        $this->get(route('dashboard'))->assertOk()->assertSee('Configured Portal')
            ->assertSee('Service notice for customers.')->assertSee('support@example.com')
            ->assertSee('https://wa.me/2348012345678', false);
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
