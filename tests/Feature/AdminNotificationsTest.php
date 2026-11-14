<?php

namespace Tests\Feature;

use App\Livewire\Admin\Notifications;
use App\Models\AdminNotificationSend;
use App\Models\SiteSetting;
use App\Models\User;
use App\NotificationContact;
use App\NotificationSender;
use App\ParroticaSettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminNotificationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config([
            'mail.default' => 'smtp', 'mail.from.address' => 'notifications@our-site.test', 'mail.from.name' => 'Our website',
            'services.parrotica' => ['url' => 'https://sms.example.test', 'user_id' => 'agent/id +', 'sender_id' => 'OUR SITE', 'api_key' => 'test-api-key'],
            'services.whatsapp.send_url' => 'https://wa.example.test/send',
            'services.whatsapp.send_token' => 'test-whatsapp-token',
        ]);
    }

    #[TestWith(['email'])]
    #[TestWith(['phone'])]
    #[TestWith(['whatsapp'])]
    public function test_pages_and_actions_require_explicit_staff_permissions(string $channel): void
    {
        $this->get(route('admin.notifications', $channel))->assertRedirect();
        $this->actingAs(User::factory()->create());
        $this->get(route('admin.notifications', $channel))->assertForbidden();
        $this->actingAs(User::factory()->staff(['notifications.view'])->create());
        $this->get(route('admin.notifications', $channel))->assertOk()->assertSee('Recipients');
        Livewire::test(Notifications::class, ['channel' => $channel])->call('prepare')->assertForbidden();
        Livewire::test(Notifications::class, ['channel' => $channel])->call('saveProvider')->assertForbidden();
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_selection_persists_searches_and_pages_in_joining_order_including_inactive_users(): void
    {
        $admin = User::factory()->administrator()->create(['created_at' => now()]);
        $this->actingAs($admin);
        $early = User::factory()->suspended()->create(['name' => 'Earliest Person', 'created_at' => now()->subYears(2), 'phone' => '08012345678']);
        $later = User::factory()->create(['created_at' => now()->subYear()]);
        User::factory()->count(100)->create(['created_at' => now()->subDays(10)]);
        $unknown = User::factory()->create(['created_at' => null]);
        $component = Livewire::test(Notifications::class)->assertSeeInOrder(['Earliest Person', $later->name])
            ->assertSee('Inactive')->call('selectPage')->assertSet('selected', fn (array $ids): bool => count($ids) === 100 && in_array((string) $early->id, $ids, true))
            ->call('gotoPage', 2)->assertSee('Unknown')->assertSet('selected', fn (array $ids): bool => count($ids) === 100)
            ->set('search', 'Earliest Person')->assertSee($early->email)->assertDontSee($unknown->email)
            ->assertSet('selected', fn (array $ids): bool => count($ids) === 100)
            ->set('search', '08012345678')->assertSee('Earliest Person')
            ->set('search', $later->email)->assertSee($later->name)
            ->set('perPage', 200)->assertSet('perPage', 200)->set('perPage', 500)->assertSet('perPage', 500)
            ->call('clearSelection')->assertSet('selected', []);
    }

    #[TestWith(['0801 234-5678', '+2348012345678'])]
    #[TestWith(['(0801) 234 5678', '+2348012345678'])]
    #[TestWith(['2348012345678', '+2348012345678'])]
    #[TestWith(['+44 (20) 7946-0958', '+442079460958'])]
    #[TestWith(['++2348012345678', null])]
    #[TestWith(['0801234567', null])]
    #[TestWith(['+23408012345678', null])]
    #[TestWith(['08012345678 ext 4', null])]
    #[TestWith(['+00000000000', null])]
    #[TestWith(['', null])]
    public function test_phone_contacts_are_strictly_normalized(string $input, ?string $expected): void
    {
        $this->assertSame($expected, NotificationContact::normalize($input, 'phone'));
    }

    public function test_invalid_contacts_are_ineligible_and_shared_contacts_are_deduplicated(): void
    {
        $this->actingAs(User::factory()->administrator()->create(['phone' => null]));
        $first = User::factory()->create(['phone' => '08012345678']);
        $second = User::factory()->suspended()->create(['phone' => '+2348012345678']);
        User::factory()->create(['phone' => 'invalid']);
        $component = Livewire::test(Notifications::class, ['channel' => 'phone'])->call('selectPage')
            ->assertSet('selected', [(string) $first->id, (string) $second->id])
            ->assertViewHas('selectedCount', 2)->assertViewHas('uniqueCount', 1)
            ->set('message', 'Hello')->call('prepare')->assertHasNoErrors();
        $send = AdminNotificationSend::query()->firstOrFail();
        $this->assertCount(1, $send->recipients);
        $this->assertSame('+2348012345678', $send->recipients[0]['contact']);
        Http::assertNothingSent();
        $component->call('confirmSending');
        Http::fake(['https://sms.example.test/v1/message/agent%2Fid%20%2B/send' => Http::response(['status' => 'success', 'data' => ['invalidContacts' => []]])]);
        $component->call('sendNextBatch')->assertSet('selected', []);
        Http::assertSentCount(1);
    }

    public function test_email_is_confirmed_individual_plain_text_in_batches_of_five_and_removes_shared_contacts(): void
    {
        $transport = new ArrayTransport;
        $manager = new MailManager(app());
        $manager->extend('notification-test', fn (): ArrayTransport => $transport);
        Mail::swap($manager);
        config([
            'mail.mailers.smtp.transport' => 'notification-test',
            'mail.reply_to' => ['address' => 'reply@our-site.test', 'name' => 'Our support'],
            'mail.return_path' => ['address' => 'bounces@our-site.test'],
        ]);
        $this->actingAs(User::factory()->administrator()->create());
        $users = User::factory()->count(6)->create();
        $duplicate = User::factory()->create(['email' => ' '.strtoupper($users[0]->email)]);
        $ids = [...$users->map(fn (User $user): string => (string) $user->id)->all(), (string) $duplicate->id];
        $component = Livewire::test(Notifications::class)->set('selected', $ids)->set('subject', 'News')
            ->set('message', "Hello\n<script>plain text</script>")->call('prepare')->assertHasNoErrors();
        $this->assertCount(0, $transport->messages());
        $send = AdminNotificationSend::query()->firstOrFail();
        $this->assertCount(6, $send->recipients);
        $this->assertStringNotContainsString('plain text', $send->getRawOriginal('payload'));
        $component->call('sendNextBatch')->assertStatus(409);
        $component = Livewire::test(Notifications::class)->set('selected', $ids)->set('subject', 'News')->set('message', "Hello\n<script>plain text</script>")
            ->call('prepare')->call('confirmSending')->call('sendNextBatch')->assertSet('selected', [(string) $users[5]->id]);
        $this->assertCount(5, $transport->messages());
        foreach ($transport->messages() as $sent) {
            $mail = $sent->getOriginalMessage();
            $this->assertCount(1, $mail->getTo());
            $this->assertSame([], $mail->getCc());
            $this->assertSame([], $mail->getBcc());
            $this->assertSame('News', $mail->getSubject());
            $this->assertSame("Hello\n<script>plain text</script>", $mail->getTextBody());
            $this->assertNull($mail->getHtmlBody());
            $this->assertSame('notifications@our-site.test', $mail->getFrom()[0]->getAddress());
            $this->assertSame('Our website', $mail->getFrom()[0]->getName());
            $this->assertSame('reply@our-site.test', $mail->getReplyTo()[0]->getAddress());
            $this->assertSame('bounces@our-site.test', $sent->getEnvelope()->getSender()->getAddress());
        }
        $component->call('sendNextBatch')->assertSet('selected', []);
        $this->assertCount(6, $transport->messages());
        $component->call('sendNextBatch');
        $this->assertCount(6, $transport->messages());
    }

    #[TestWith(["Bad\r\nBcc: injected@example.test", 'Hello', 'subject'])]
    #[TestWith(['', 'Hello', 'subject'])]
    #[TestWith(['Good', '', 'message'])]
    public function test_invalid_email_content_cannot_be_confirmed(string $subject, string $message, string $field): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);
        Livewire::test(Notifications::class)->set('selected', [(string) $admin->id])->set('subject', $subject)->set('message', $message)
            ->call('prepare')->assertHasErrors([$field]);
        $this->assertDatabaseCount('admin_notification_sends', 0);
        Mail::assertNothingSent();
    }

    public function test_email_byte_and_subject_limits_and_sms_character_limit_are_enforced(): void
    {
        $admin = User::factory()->administrator()->create(['phone' => '08012345678']);
        $this->actingAs($admin);
        $email = Livewire::test(Notifications::class)->set('selected', [(string) $admin->id])->set('subject', str_repeat('s', 201))->set('message', 'Hello')
            ->call('prepare')->assertHasErrors(['subject']);
        $email->set('subject', 'Good')->set('message', str_repeat('é', 32768))->call('prepare')->assertHasErrors(['message']);
        $email->set('message', str_repeat('a', 65535))->call('prepare')->assertHasNoErrors();
        Livewire::test(Notifications::class, ['channel' => 'phone'])->set('selected', [(string) $admin->id])
            ->set('message', str_repeat('é', 906))->call('prepare')->assertHasErrors(['message'])
            ->set('message', str_repeat('é', 905))->call('prepare')->assertHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_sms_contract_is_encoded_and_batches_are_sequential_with_at_most_500_unique_numbers(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $send = $this->phoneOperation(501);
        Http::fake(['https://sms.example.test/v1/message/agent%2Fid%20%2B/send' => Http::response(['status' => 'success', 'data' => ['invalidContacts' => []]])]);
        $sender = app(NotificationSender::class);
        $sender->next($send);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://sms.example.test/v1/message/agent%2Fid%20%2B/send'
            && $request->method() === 'POST' && $request->hasHeader('x-api-key', 'test-api-key')
            && $request->hasHeader('Accept', 'application/json')
            && $request['content'] === 'Notice' && $request['senderId'] === 'OUR SITE'
            && count($request['numbers']) === 500 && $request['numbers'][0] === '+2348000000000');
        $this->assertSame(['accepted' => 500, 'failed' => 0, 'uncertain' => 0, 'not_attempted' => 1, 'total' => 501], $send->refresh()->totals());
        $sender->next($send);
        Http::assertSentCount(2);
        $this->assertSame('completed', $send->refresh()->status);
        $sender->next($send);
        Http::assertSentCount(2);
    }

    #[TestWith(['unreadable'])]
    #[TestWith(['partial'])]
    #[TestWith(['timeout'])]
    #[TestWith(['server_error'])]
    #[TestWith(['placeholder'])]
    #[TestWith(['wrong_shape'])]
    #[TestWith(['contradictory'])]
    public function test_uncertain_sms_stops_without_retries_and_retains_unattempted_contacts(string $scenario): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $send = $this->phoneOperation(501);
        Http::fake(['https://sms.example.test/v1/message/agent%2Fid%20%2B/send' => match ($scenario) {
            'unreadable' => Http::response('not-json'),
            'partial' => Http::response(['status' => 'success', 'data' => ['invalidContacts' => ['+2348000000000']]]),
            'timeout' => fn () => throw new ConnectionException('Connection interrupted'),
            'server_error' => Http::response(['status' => 'error'], 500),
            'wrong_shape' => Http::response('{"status":"success","data":{"invalidContacts":{}}}'),
            'contradictory' => Http::response(['status' => 'success', 'data' => ['invalidContacts' => []], 'message' => 'Partial acceptance']),
            default => Http::response(['status' => 'status', 'code' => 0.8]),
        }]);
        $sender = app(NotificationSender::class);
        $sender->next($send);
        $this->assertSame('stopped', $send->refresh()->status);
        $this->assertSame(['accepted' => 0, 'failed' => 0, 'uncertain' => 500, 'not_attempted' => 1, 'total' => 501], $send->totals());
        $sender->next($send);
        $this->assertSame('stopped', $send->refresh()->status);
        if ($scenario !== 'timeout') {
            Http::assertSentCount(1);
        }
    }

    public function test_explicit_provider_rejection_is_failed_and_selection_is_retained(): void
    {
        $admin = User::factory()->administrator()->create(['phone' => '08012345678']);
        $this->actingAs($admin);
        Http::fake(['https://sms.example.test/v1/message/agent%2Fid%20%2B/send' => Http::response(['status' => 'error', 'message' => 'Sender not approved'], 422)]);
        Livewire::test(Notifications::class, ['channel' => 'phone'])->set('selected', [(string) $admin->id])->set('message', 'Notice')
            ->call('prepare')->call('confirmSending')->call('sendNextBatch')->assertSet('selected', [(string) $admin->id])
            ->assertViewHas('totals', fn (array $totals): bool => $totals['failed'] === 1 && $totals['uncertain'] === 0);
    }

    public function test_an_interrupted_email_stops_later_recipients_and_cannot_be_retried(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $send = AdminNotificationSend::factory()->create([
            'user_id' => auth()->id(), 'status' => 'ready', 'confirmed_at' => now(),
            'recipients' => [
                ['contact' => 'first@example.test', 'users' => [], 'status' => 'pending'],
                ['contact' => 'next@example.test', 'users' => [], 'status' => 'pending'],
            ],
        ]);
        Mail::shouldReceive('to')->once()->with('first@example.test')->andThrow(new \RuntimeException('Transport lost'));
        app(NotificationSender::class)->next($send);
        $this->assertSame(['accepted' => 0, 'failed' => 0, 'uncertain' => 1, 'not_attempted' => 1, 'total' => 2], $send->refresh()->totals());
        app(NotificationSender::class)->next($send);
        $this->assertSame('stopped', $send->refresh()->status);
    }

    public function test_an_inflight_batch_is_not_sent_twice_and_refresh_stops_the_operation(): void
    {
        $admin = User::factory()->administrator()->create(['phone' => '08000000000']);
        $this->actingAs($admin);
        $send = $this->phoneOperation(2);
        $send->update(['status' => 'sending', 'recipients' => [
            ['contact' => '+2348000000000', 'users' => [(string) $admin->id], 'status' => 'sending'],
            ['contact' => '+2348000000001', 'users' => [], 'status' => 'pending'],
        ]]);
        session()->put('admin_notification.'.$admin->id.'.phone', $send->id);
        Livewire::test(Notifications::class, ['channel' => 'phone'])->assertSet('selected', [(string) $admin->id])
            ->assertSee('Check the provider')->set('message', 'Another attempt')->call('prepare')->assertHasErrors(['reviewedUncertain']);
        $this->assertSame('stopped', $send->refresh()->status);
        app(NotificationSender::class)->next($send);
        Http::assertNothingSent();
    }

    public function test_a_cancelled_mail_is_uncertain_and_does_not_mark_recipients_accepted(): void
    {
        $transport = new ArrayTransport;
        $manager = new MailManager(app());
        $manager->extend('notification-test', fn (): ArrayTransport => $transport);
        Mail::swap($manager);
        config(['mail.mailers.smtp.transport' => 'notification-test']);
        Event::listen(MessageSending::class, fn (): bool => false);
        $send = AdminNotificationSend::factory()->create([
            'status' => 'ready', 'confirmed_at' => now(),
            'recipients' => [
                ['contact' => 'first@example.test', 'users' => [], 'status' => 'pending'],
                ['contact' => 'next@example.test', 'users' => [], 'status' => 'pending'],
            ],
        ]);

        app(NotificationSender::class)->next($send);

        $this->assertSame(['accepted' => 0, 'failed' => 0, 'uncertain' => 1, 'not_attempted' => 1, 'total' => 2], $send->refresh()->totals());
        $this->assertCount(0, $transport->messages());
    }

    public function test_configuration_is_validated_encrypted_and_never_hydrates_the_api_key(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $component = Livewire::test(Notifications::class, ['channel' => 'phone'])->assertSet('providerApiKey', '')
            ->set('providerUrl', 'http://insecure.example.test')->call('saveProvider')->assertHasErrors(['url'])
            ->set('providerUrl', 'https://secure.example.test')->set('providerSenderId', 'TOO LONG SENDER NAME')->call('saveProvider')->assertHasErrors(['sender_id'])
            ->set('providerSenderId', '1234567890123456')->set('providerApiKey', 'new-test-secret')->call('saveProvider')->assertHasNoErrors()
            ->assertSet('providerApiKey', '');
        $raw = SiteSetting::query()->where('key', 'notification_parrotica')->value('value');
        $this->assertStringNotContainsString('new-test-secret', $raw);
        $this->assertSame('new-test-secret', ParroticaSettings::get()['api_key']);
        Livewire::test(Notifications::class, ['channel' => 'phone'])->assertSet('providerApiKey', '')
            ->set('providerSenderId', 'APPROVED')->call('saveProvider')->assertHasNoErrors();
        $this->assertSame('new-test-secret', ParroticaSettings::get()['api_key']);
    }

    public function test_logging_mail_transport_and_global_recipient_overrides_are_rejected(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);
        config(['mail.default' => 'log']);
        $component = Livewire::test(Notifications::class)->set('selected', [(string) $admin->id])->set('subject', 'News')->set('message', 'Notice')
            ->call('prepare')->assertHasErrors(['provider']);
        config(['mail.default' => 'smtp', 'mail.to.address' => 'override@example.test']);
        $component->call('prepare')->assertHasErrors(['provider']);
        Mail::assertNothingSent();
    }

    public function test_owner_isolation_and_locked_channel_protect_sending_operations(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $foreign = AdminNotificationSend::factory()->create();
        session()->put('admin_notification.'.auth()->id().'.email', $foreign->id);
        Livewire::test(Notifications::class)->assertSet('sendId', null)->call('sendNextBatch')->assertStatus(409);
        Mail::assertNothingSent();
    }

    public function test_whatsapp_uses_the_configured_endpoint_and_sends_unique_contacts_individually_after_confirmation(): void
    {
        $admin = User::factory()->administrator()->create(['phone' => null]);
        $this->actingAs($admin);
        $first = User::factory()->create(['phone' => '08123232323']);
        $shared = User::factory()->suspended()->create(['phone' => '+2348123232323']);
        $second = User::factory()->create(['phone' => '08012345678']);
        Http::fake(['https://wa.example.test/send' => fn ($request) => Http::response([
            'success' => true,
            'data' => ['msgId' => 97384835, 'jid' => $request['to'], 'status' => 'in_progress'],
        ])]);

        $component = Livewire::test(Notifications::class, ['channel' => 'whatsapp'])
            ->assertDontSee('Sending will be available')->call('selectPage')
            ->assertViewHas('uniqueCount', 2)->set('message', 'Wallet funding successful.')
            ->call('prepare')->assertHasNoErrors()->assertSee('Confirm WhatsApp notification');
        Http::assertNothingSent();
        $send = AdminNotificationSend::query()->firstOrFail();
        $this->assertStringNotContainsString('test-whatsapp-token', $send->getRawOriginal('payload'));
        $this->assertStringNotContainsString('test-whatsapp-token', $component->html());
        $component->call('confirmSending')->call('sendNextBatch')
            ->assertSet('selected', [(string) $second->id])->assertViewHas('batchSize', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://wa.example.test/send'
            && $request->hasHeader('Authorization', 'Bearer test-whatsapp-token')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['to' => '+2348123232323', 'text' => 'Wallet funding successful.']);
        $this->assertSame(1, $send->refresh()->totals()['accepted']);
        $component->assertSee('Accepted / queued')->assertSee('not final WhatsApp delivery');
        $component->call('sendNextBatch')->assertSet('selected', []);
        $component->call('sendNextBatch');
        Http::assertSentCount(2);
        $this->assertSame('completed', $send->refresh()->status);
    }

    #[TestWith(['timeout'])]
    #[TestWith(['unreadable'])]
    #[TestWith(['ambiguous'])]
    #[TestWith(['server_error'])]
    #[TestWith(['contradictory'])]
    #[TestWith(['partial'])]
    #[TestWith(['missing_receipt'])]
    #[TestWith(['wrong_recipient'])]
    #[TestWith(['nested_failure'])]
    #[TestWith(['invalid_message_id'])]
    public function test_uncertain_whatsapp_outcomes_stop_without_retrying_or_sending_to_the_next_contact(string $scenario): void
    {
        $admin = User::factory()->administrator()->create(['phone' => '08123232323']);
        $this->actingAs($admin);
        $other = User::factory()->create(['phone' => '08012345678']);
        Http::fake(['https://wa.example.test/send' => match ($scenario) {
            'timeout' => fn () => throw new ConnectionException('Interrupted'),
            'unreadable' => Http::response('not json'),
            'server_error' => Http::response(['success' => false], 500),
            'contradictory' => Http::response(['success' => true, 'status' => 'error']),
            'partial' => Http::response(['success' => true, 'status' => 'partial']),
            'missing_receipt' => Http::response(['success' => true]),
            'wrong_recipient' => Http::response(['success' => true, 'data' => ['msgId' => 97384835, 'jid' => '+2348000000001', 'status' => 'in_progress']]),
            'nested_failure' => Http::response(['success' => true, 'data' => ['msgId' => 97384835, 'jid' => '+2348123232323', 'status' => 'failed']]),
            'invalid_message_id' => Http::response(['success' => true, 'data' => ['msgId' => 0, 'jid' => '+2348123232323', 'status' => 'in_progress']]),
            default => Http::response(['message' => 'Processing']),
        }]);
        $selected = [(string) $admin->id, (string) $other->id];
        $component = Livewire::test(Notifications::class, ['channel' => 'whatsapp'])->set('selected', $selected)
            ->set('message', 'Notice')->call('prepare')->call('confirmSending')->call('sendNextBatch')
            ->assertSet('selected', $selected)->assertViewHas('totals', fn (array $totals): bool => $totals['uncertain'] === 1 && $totals['not_attempted'] === 1);
        $component->call('sendNextBatch');
        $this->assertSame('stopped', AdminNotificationSend::query()->firstOrFail()->status);
        if ($scenario !== 'timeout') {
            Http::assertSentCount(1);
        }
    }

    public function test_explicit_whatsapp_rejection_retains_the_failed_selection(): void
    {
        $admin = User::factory()->administrator()->create(['phone' => '08123232323']);
        $this->actingAs($admin);
        Http::fake(['https://wa.example.test/send' => Http::response(['success' => false, 'error' => 'Invalid recipient'], 422)]);
        Livewire::test(Notifications::class, ['channel' => 'whatsapp'])->set('selected', [(string) $admin->id])
            ->set('message', 'Notice')->call('prepare')->call('confirmSending')->call('sendNextBatch')
            ->assertSet('selected', [(string) $admin->id])->assertViewHas('totals', fn (array $totals): bool => $totals['failed'] === 1);
        Http::assertSentCount(1);
    }

    public function test_whatsapp_requires_a_valid_https_endpoint_token_and_message_before_confirmation(): void
    {
        $admin = User::factory()->administrator()->create(['phone' => '08123232323']);
        $this->actingAs($admin);
        $component = Livewire::test(Notifications::class, ['channel' => 'whatsapp'])->set('selected', [(string) $admin->id]);
        $component->call('prepare')->assertHasErrors(['message']);
        config(['services.whatsapp.send_url' => null, 'services.whatsapp.send_token' => null]);
        $component->set('message', 'Notice')->call('prepare')->assertHasErrors(['url', 'token']);
        config(['services.whatsapp.send_url' => 'http://wa.example.test/send', 'services.whatsapp.send_token' => "bad\r\ntoken"]);
        $component->call('prepare')->assertHasErrors(['url', 'token']);
        $this->assertDatabaseCount('admin_notification_sends', 0);
        Http::assertNothingSent();
    }

    private function phoneOperation(int $count): AdminNotificationSend
    {
        $recipients = [];
        for ($index = 0; $index < $count; $index++) {
            $recipients[] = ['contact' => '+234'.(8000000000 + $index), 'users' => [], 'status' => 'pending'];
        }

        return AdminNotificationSend::factory()->create([
            'user_id' => auth()->id(), 'channel' => 'phone', 'status' => 'ready', 'confirmed_at' => now(),
            'payload' => ['subject' => '', 'message' => 'Notice', 'parrotica' => ParroticaSettings::get()], 'recipients' => $recipients,
        ]);
    }
}
