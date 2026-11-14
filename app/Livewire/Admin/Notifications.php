<?php

namespace App\Livewire\Admin;

use App\Models\AdminNotificationSend;
use App\Models\User;
use App\NotificationContact;
use App\NotificationSender;
use App\ParroticaSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class Notifications extends AdminComponent
{
    use WithPagination;

    #[Locked]
    public string $channel = 'email';

    #[Locked]
    public ?int $sendId = null;

    public string $search = '';

    public int $perPage = 100;

    /** @var list<string> */
    public array $selected = [];

    public string $subject = '';

    public string $message = '';

    public bool $reviewedUncertain = false;

    public string $providerUrl = '';

    public string $providerUserId = '';

    public string $providerSenderId = '';

    public string $providerApiKey = '';

    public string $notice = '';

    public function mount(string $channel = 'email'): void
    {
        abort_unless(in_array($channel, ['email', 'phone', 'whatsapp'], true), 404);
        $this->channel = $channel;
        if ($channel === 'phone' && Gate::allows('notifications.configure')) {
            $settings = ParroticaSettings::get();
            $this->providerUrl = $settings['url'];
            $this->providerUserId = $settings['user_id'];
            $this->providerSenderId = $settings['sender_id'];
        }
        $previous = session($this->sessionKey());
        if ($previous) {
            $send = AdminNotificationSend::query()->where('user_id', auth()->id())->where('channel', $channel)->find($previous);
            if ($send && $send->status !== 'draft') {
                $this->sendId = $send->id;
                if (in_array($send->status, ['ready', 'sending'], true)) {
                    app(NotificationSender::class)->stop($send);
                }
                $this->subject = $send->payload['subject'];
                $this->message = $send->payload['message'];
                $this->selected = array_values(array_unique(array_merge(...array_map(
                    fn (array $recipient): array => $recipient['status'] === 'accepted' ? [] : $recipient['users'], $send->recipients
                ))));
            }
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->validate(['perPage' => ['required', 'integer', 'in:100,200,500']]);
        $this->resetPage();
    }

    public function selectPage(): void
    {
        $this->ensureEditable();
        $ids = $this->usersQuery()->paginate($this->pageSize())->getCollection()
            ->filter(fn (User $user): bool => NotificationContact::forUser($user, $this->channel) !== null)
            ->map(fn (User $user): string => (string) $user->id)->all();
        $this->selected = array_values(array_unique([...$this->selected, ...$ids]));
    }

    public function clearSelection(): void
    {
        $this->ensureEditable();
        $this->selected = [];
    }

    public function saveProvider(): void
    {
        Gate::authorize('notifications.configure');
        abort_unless($this->channel === 'phone', 403);
        $this->ensureEditable();
        $settings = [
            'url' => trim($this->providerUrl), 'user_id' => trim($this->providerUserId),
            'sender_id' => trim($this->providerSenderId), 'api_key' => $this->providerApiKey !== '' ? $this->providerApiKey : ParroticaSettings::get()['api_key'],
        ];
        ParroticaSettings::save($settings);
        $this->providerApiKey = '';
        $this->notice = 'Parrotica configuration saved. Sender IDs must be approved by Parrotica.';
    }

    public function prepare(): void
    {
        Gate::authorize('notifications.send');
        $this->ensureEditable();
        $this->validate([
            'selected' => ['required', 'array', 'min:1'],
            'selected.*' => ['integer', 'min:1'],
            'subject' => $this->channel === 'email' ? ['required', 'string', 'min:1', 'max:200', 'not_regex:/[\r\n]/'] : ['nullable', 'string'],
            'message' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (in_array($this->channel, ['email', 'whatsapp'], true) && strlen($value) > 65535) {
                    $fail('The message must not exceed 65,535 bytes.');
                }
                if ($this->channel === 'phone' && mb_strlen($value) > 905) {
                    $fail('The SMS message must not exceed 905 characters.');
                }
            }],
        ]);
        $recipients = array_values($this->selectedRecipients());
        if (! $recipients) {
            throw ValidationException::withMessages(['selected' => 'Select at least one user with a valid contact.']);
        }
        $previous = $this->currentSend();
        $uncertain = $previous ? array_column(array_filter($previous->recipients, fn (array $recipient): bool => in_array($recipient['status'], ['sending', 'uncertain'], true)), 'contact') : [];
        if (! $this->reviewedUncertain && array_intersect($uncertain, array_column($recipients, 'contact'))) {
            throw ValidationException::withMessages(['reviewedUncertain' => 'Check the provider before resending uncertain contacts, then confirm that you have reviewed them.']);
        }
        $payload = ['subject' => $this->subject, 'message' => $this->message];
        if ($this->channel === 'email') {
            app(NotificationSender::class)->validateHosting();
        } elseif ($this->channel === 'phone') {
            $payload['parrotica'] = ParroticaSettings::get();
            ParroticaSettings::validate($payload['parrotica']);
        } else {
            $payload['whatsapp'] = app(NotificationSender::class)->whatsAppSettings();
        }
        $send = AdminNotificationSend::query()->create([
            'user_id' => auth()->id(), 'channel' => $this->channel, 'status' => 'draft', 'payload' => $payload, 'recipients' => $recipients,
        ]);
        $this->sendId = $send->id;
        session()->put($this->sessionKey(), $send->id);
        $this->notice = '';
        $this->reviewedUncertain = false;
    }

    public function cancelConfirmation(): void
    {
        $send = $this->currentSend();
        if ($send?->status === 'draft') {
            $send->update(['status' => 'cancelled']);
            $this->sendId = null;
            session()->forget($this->sessionKey());
        }
    }

    /** @return array<string, mixed> */
    public function confirmSending(): array
    {
        Gate::authorize('notifications.send');
        $send = $this->currentSend();
        abort_unless($send && $send->status === 'draft', 409);
        app(NotificationSender::class)->confirm($send);

        return $this->result($send->refresh());
    }

    /** @return array<string, mixed> */
    public function sendNextBatch(): array
    {
        Gate::authorize('notifications.send');
        $send = $this->currentSend();
        abort_unless($send && $send->confirmed_at, 409);
        $send = app(NotificationSender::class)->next($send);
        $this->removeAccepted($send);

        return $this->result($send);
    }

    /** @return array<string, mixed> */
    public function stopInterrupted(): array
    {
        Gate::authorize('notifications.send');
        $send = $this->currentSend();
        abort_unless($send, 404);
        $send = app(NotificationSender::class)->stop($send);
        $this->removeAccepted($send);

        return $this->result($send);
    }

    private function removeAccepted(AdminNotificationSend $send): void
    {
        $accepted = array_column(array_filter($send->recipients, fn (array $recipient): bool => $recipient['status'] === 'accepted'), 'contact');
        $remaining = User::query()->whereIn('id', $this->selected)->get()
            ->reject(fn (User $user): bool => in_array(NotificationContact::forUser($user, $this->channel), $accepted, true));
        $this->selected = $remaining->map(fn (User $user): string => (string) $user->id)->values()->all();
    }

    /** @return array<string, mixed> */
    private function result(AdminNotificationSend $send): array
    {
        return ['status' => $send->status, 'totals' => $send->totals(), 'batchSize' => NotificationSender::batchSize($this->channel)];
    }

    private function ensureEditable(): void
    {
        abort_if(in_array($this->currentSend()?->status, ['draft', 'ready', 'sending'], true), 409, 'Finish or cancel the current operation first.');
    }

    private function currentSend(): ?AdminNotificationSend
    {
        return $this->sendId ? AdminNotificationSend::query()->where('user_id', auth()->id())->where('channel', $this->channel)->findOrFail($this->sendId) : null;
    }

    private function sessionKey(): string
    {
        return 'admin_notification.'.auth()->id().'.'.$this->channel;
    }

    private function pageSize(): int
    {
        return in_array($this->perPage, [100, 200, 500], true) ? $this->perPage : 100;
    }

    /** @return Builder<User> */
    private function usersQuery(): Builder
    {
        $fields = ['name', 'first_name', 'surname', 'email', 'phone'];
        if (Schema::hasColumn('users', 'username')) {
            $fields[] = 'username';
        }

        return User::query()->select(['id', ...$fields, 'created_at', 'is_active'])->when(trim($this->search) !== '', function (Builder $query) use ($fields): void {
            $query->where(function (Builder $query) use ($fields): void {
                foreach ($fields as $field) {
                    $query->orWhereLike($field, '%'.trim($this->search).'%');
                }
            });
        })->orderByRaw('created_at IS NULL')->orderBy('created_at')->orderBy('id');
    }

    /** @return array<string, array{contact: string, users: list<string>, status: string}> */
    private function selectedRecipients(): array
    {
        $groups = [];
        foreach (User::query()->whereIn('id', array_filter($this->selected, fn (mixed $id): bool => ctype_digit((string) $id)))->get() as $user) {
            $contact = NotificationContact::forUser($user, $this->channel);
            if ($contact !== null) {
                $key = hash('sha256', $contact);
                $groups[$key] ??= ['contact' => $contact, 'users' => [], 'status' => 'pending'];
                $groups[$key]['users'][] = (string) $user->id;
            }
        }

        return $groups;
    }

    public function render(): View
    {
        $recipients = $this->selectedRecipients();
        $send = $this->currentSend();

        return view('livewire.admin.notifications', [
            'users' => $this->usersQuery()->paginate($this->pageSize()),
            'selectedCount' => array_sum(array_map(fn (array $recipient): int => count($recipient['users']), $recipients)),
            'uniqueCount' => count($recipients), 'send' => $send, 'totals' => $send?->totals(),
            'batchSize' => NotificationSender::batchSize($this->channel),
            'locked' => in_array($send?->status, ['draft', 'ready', 'sending'], true),
            'apiKeyConfigured' => $this->channel === 'phone' && Gate::allows('notifications.configure') && ParroticaSettings::get()['api_key'] !== '',
        ]);
    }
}
