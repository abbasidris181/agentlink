<?php

namespace App\Livewire;

use App\Models\PersonalizationRequest;
use App\Models\ServicePrice;
use App\PersonalizationService;
use App\ServiceAvailability;
use App\SlipDownloadService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Personalization extends Component
{
    public string $trackingId = '';

    #[Locked]
    public string $selectedReference = '';

    #[Locked]
    public string $notice = '';

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
        ServiceAvailability::assertEnabled('personalization');
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function submit(PersonalizationService $provider): void
    {
        abort_unless(Auth::check(), 403);
        $this->resetValidation();
        $this->notice = '';
        $this->selectedReference = '';
        $this->trackingId = trim($this->trackingId);
        $this->validate(['trackingId' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{15}\z/']], [
            'trackingId.regex' => 'Enter a tracking ID with exactly 15 letters or numbers.',
        ], ['trackingId' => 'tracking ID']);
        $record = $provider->submit(Auth::user(), $this->trackingId);
        $this->notice = $record->last_error === null ? 'submission successful' : '';
        if ($record->last_error !== null) {
            $this->addError('personalization', $record->last_error);
        }
    }

    public function checkResult(string $reference, PersonalizationService $provider): void
    {
        abort_unless(Auth::check(), 403);
        $this->resetValidation();
        $this->notice = '';
        $record = $provider->check(Auth::user(), $reference);
        $this->selectedReference = $record->reference;
        if ($record->last_error !== null) {
            $this->addError('personalization', $record->last_error);
        } else {
            $this->notice = $record->provider_status ?? $record->statusLabel();
        }
    }

    public function viewResult(string $reference): void
    {
        abort_unless(Auth::check() && Auth::user()->fresh()->is_active, 403);
        $record = PersonalizationRequest::query()->where('user_id', Auth::id())->where('reference', $reference)->where('status', 'successful')->whereNotNull('result')->firstOrFail();
        $this->selectedReference = $record->reference;
    }

    public function render(SlipDownloadService $downloads): View
    {
        $selected = $this->selectedReference === '' ? null : PersonalizationRequest::query()
            ->where('user_id', Auth::id())->where('reference', $this->selectedReference)->first();

        return view('livewire.personalization', ['canSubmit' => ServiceAvailability::canSubmit('personalization', 'tracking'), 'price' => ServicePrice::amountFor('personalization', 'tracking'),
            'history' => PersonalizationRequest::query()->where('user_id', Auth::id())->latest('id')->limit(20)->get(),
            'selected' => $selected,
            'slips' => $selected?->status === 'successful' && $selected->result !== null ? $downloads->optionsFor($selected) : [],
        ]);
    }
}
