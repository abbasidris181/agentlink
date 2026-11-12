<?php

namespace App;

use App\Models\BvnVerificationRecord;
use App\Models\PersonalizationRequest;
use App\Models\ServicePrice;
use App\Models\SiteSetting;
use App\Models\SlipPurchase;
use App\Models\User;
use App\Models\VerificationRecord;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SlipDownloadService
{
    /** @return array<string, array{label: string, preview: string, sample: string, price: ?int, purchased: bool}> */
    public function optionsFor(VerificationRecord $verification): array
    {
        $service = $this->priceService($verification);
        $prices = ServicePrice::query()->where('service', $service)->get()->keyBy('method');
        $purchased = SlipPurchase::query()->where($this->verificationColumn($verification), $verification->id)->pluck('slip')->all();
        $options = [];
        foreach (ServiceCatalog::slips()[$service]['slips'] as $key => $definition) {
            $price = $prices->get($key);
            $unavailable = SiteSetting::enabled(ServiceAvailability::unavailableSettingKey($service, $key));
            $options[$key] = [...$definition, 'price' => $unavailable ? null : ($price === null ? 0 : $price->amount), 'purchased' => in_array($key, $purchased, true)];
        }

        return $options;
    }

    public function canDownload(VerificationRecord $verification, string $slip): bool
    {
        $option = $this->optionsFor($verification)[$slip] ?? null;

        return $option !== null && ($option['purchased'] || $option['price'] === 0);
    }

    public function purchase(User $user, VerificationRecord $verification, string $slip, int $expectedPrice): void
    {
        $service = $this->priceService($verification);
        $column = $this->verificationColumn($verification);
        abort_unless(isset(ServiceCatalog::slips()[$service]['slips'][$slip]), 404);

        DB::transaction(function () use ($user, $verification, $slip, $expectedPrice, $service, $column): void {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($owner->is_active && ! $owner->needsEmailVerification(), 403);
            $record = $verification::query()->lockForUpdate()->findOrFail($verification->id);
            abort_unless($record->user_id === $owner->id && $record->status === 'successful', 404);
            $existing = SlipPurchase::query()->where($column, $record->id)->where('slip', $slip)->first();
            if ($existing !== null) {
                abort_unless($existing->user_id === $owner->id, 404);

                return;
            }
            if (SiteSetting::enabled(ServiceAvailability::unavailableSettingKey($service, $slip))) {
                throw ValidationException::withMessages(['slipDownload' => 'This slip is currently unavailable.']);
            }
            $pricing = ServicePrice::query()->where('service', $service)->where('method', $slip)->lockForUpdate()->first();
            $price = $pricing === null ? 0 : $pricing->amount;
            if ($price === null) {
                throw ValidationException::withMessages(['slipDownload' => 'This slip is currently unavailable.']);
            }
            if ($price !== $expectedPrice) {
                throw ValidationException::withMessages(['slipDownload' => 'The slip price has changed. Review the updated price before downloading.']);
            }
            if ($price === 0) {
                return;
            }
            if ($owner->wallet_balance < $price) {
                throw ValidationException::withMessages(['slipDownload' => 'Your wallet balance is insufficient for this slip. Fund your wallet before downloading.']);
            }
            $label = ($service === 'bvn-slips' ? 'BVN' : 'NIN').' slip: '.ServiceCatalog::slips()[$service]['slips'][$slip]['label'];
            $oldBalance = $owner->wallet_balance;
            $owner->wallet_balance -= $price;
            $owner->save();
            $charge = WalletTransaction::create([
                'reference' => (string) Str::ulid(), 'request_key' => (string) Str::uuid(), 'user_id' => $owner->id, 'actor_id' => $owner->id,
                'service' => $label, 'type' => 'service_debit', 'status' => 'completed', 'amount' => $price,
                'old_balance' => $oldBalance, 'new_balance' => $owner->wallet_balance,
                'reason' => 'Slip purchase for verification '.$record->reference,
            ]);
            SlipPurchase::create([
                'user_id' => $owner->id, $column => $record->id, 'wallet_transaction_id' => $charge->id, 'slip' => $slip, 'price' => $price,
            ]);
        }, 3);
    }

    private function priceService(VerificationRecord $verification): string
    {
        return $verification instanceof BvnVerificationRecord ? 'bvn-slips' : 'nin-slips';
    }

    private function verificationColumn(VerificationRecord $verification): string
    {
        return match (true) {
            $verification instanceof BvnVerificationRecord => 'bvn_verification_record_id',
            $verification instanceof PersonalizationRequest => 'personalization_request_id',
            default => 'nin_verification_record_id',
        };
    }
}
