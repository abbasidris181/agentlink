<?php

namespace App;

use App\Models\AdminAudit;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubmitServiceRequest
{
    /**
     * @param  array<string, string>  $input
     * @return list<string>
     */
    public static function priceMethods(string $service, string $method, array $input): array
    {
        $methods = [$method];
        if (in_array($service, ['nin-modification', 'bvn-modification'], true)) {
            $types = explode(', ', $input['types'] ?? '');
            $allowed = $service === 'nin-modification' ? array_keys(ServiceCatalog::modificationTypes()) : ['name', 'dob', 'phone'];
            if ($types === [] || array_diff($types, $allowed) !== [] || count($types) !== count(array_unique($types))) {
                return [];
            }
            $methods = array_map(fn (string $type): string => $method.'_'.$type, $types);
        } elseif ($service === 'delinking') {
            $methods = match ($input['types'] ?? 'Delinking') {
                'Delinking' => ['nin'],
                'Email retrive' => ['email_retrieve'],
                'Delinking, Email retrive' => ['nin', 'email_retrieve'],
                default => [],
            };
        }

        return $methods;
    }

    /** @param array<string, string> $input */
    public static function priceFor(string $service, string $method, array $input): ?int
    {
        return ServicePrice::totalFor($service, self::priceMethods($service, $method, $input));
    }

    /** @param array<string, string> $input
     * @return array<string, array<int, mixed>>
     */
    private function manualInputRules(string $service, string $method, array $input): array
    {
        if ($method === 'demographics') {
            return [
                'input' => ['required', 'array:first_name,surname,middle_name,dob,gender'],
                'input.first_name' => ['required', 'string', 'max:100'],
                'input.surname' => ['required', 'string', 'max:100'],
                'input.middle_name' => ['nullable', 'string', 'max:100'],
                'input.dob' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'input.gender' => ['required', 'in:male,female'],
            ];
        }
        $types = explode(', ', $input['types'] ?? '');
        $allowed = $service === 'nin-modification' ? array_keys(ServiceCatalog::modificationTypes()) : ['name', 'dob', 'phone'];
        $validTypes = count($types) === count(array_unique($types)) && array_diff($types, $allowed) === [];
        $rules = [
            'input.nin' => ['required', 'string', 'regex:/\\A[0-9]{11}\\z/'],
            'input.types' => ['required', 'string', Rule::in($validTypes ? [$input['types']] : [])],
        ];
        if ($service === 'bvn-modification') {
            $rules['input.bvn'] = ['required', 'string', 'regex:/\\A[0-9]{11}\\z/'];
        }
        if (in_array('name', $types, true)) {
            foreach (['surname', 'first_name', 'middle_name'] as $field) {
                $required = $field !== 'middle_name' || $service === 'bvn-modification';
                $rules['input.'.$field] = [$required ? 'required' : 'nullable', 'string', 'max:100'];
                if ($service === 'nin-modification' && $method === 'second') {
                    $rules['input.old_'.$field] = [$required ? 'required' : 'nullable', 'string', 'max:100'];
                }
            }
        }
        if (array_intersect($types, ['dob', 'dob_above_5', 'dob_above_10', 'dob_above_15']) !== []) {
            $rules['input.dob'] = ['required', 'date_format:Y-m-d', 'before_or_equal:today'];
            if ($service === 'nin-modification' && $method === 'second') {
                $rules['input.old_dob'] = ['required', 'date_format:Y-m-d', 'before_or_equal:today'];
            }
        }
        if (in_array('phone', $types, true)) {
            $rules['input.phone'] = ['required', 'string', 'regex:/\\A(?:0[1-9][0-9]{9}|(?:\\+?234)?[1-9][0-9]{9})\\z/'];
        }
        if (in_array('address', $types, true)) {
            $rules['input.address'] = ['required', 'string', 'max:1000'];
        }
        if (in_array('gender', $types, true)) {
            $rules['input.gender'] = ['required', 'in:male,female'];
        }
        $keys = array_map(fn (string $key): string => substr($key, strlen('input.')), array_keys($rules));
        $rules['input'] = ['required', 'array:'.implode(',', $keys)];

        return $rules;
    }

    /** @param array<string, string> $input */
    public function create(User $user, string $service, string $method, array $input, string $requestKey, ?UploadedFile $proof = null): ServiceRequest
    {
        if ($service === 'demographics-search') {
            $input = DemographicsSearchInput::validate($input);
        }
        if ($service === 'bvn-license-onboarding') {
            $input = BvnLicenseOnboardingInput::validate($input);
        }
        $inputRules = match ($method) {
            'nin' => ['input' => ['required', 'array:nin'], 'input.nin' => ['required', 'string', 'regex:/\A[0-9]{11}\z/']],
            'tracking' => ['input' => ['required', 'array:tracking_id'], 'input.tracking_id' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{15}\z/']],
            'phone' => ['input' => ['required', 'array:phone,full_name'], 'input.phone' => ['required', 'string', 'regex:/\A(?:0[1-9][0-9]{9}|(?:\+?234)?[1-9][0-9]{9})\z/'], 'input.full_name' => ['required', 'string', 'max:200']],
            'crm' => ['input' => ['required', 'array:agent_code,ticket_id,bms_ticket'], 'input.agent_code' => ['required', 'string', 'max:100'], 'input.ticket_id' => ['required', 'string', 'max:100'], 'input.bms_ticket' => ['required', 'string', 'max:100']],
            default => [],
        };
        if ($service === 'delinking' && $method === 'nin') {
            $inputRules['input'] = ['required', 'array:nin,types,email'];
            $inputRules['input.types'] = ['sometimes', 'required', Rule::in(['Delinking', 'Email retrive', 'Delinking, Email retrive'])];
            $inputRules['input.email'] = ['nullable', 'email:rfc', 'max:254', Rule::prohibitedIf(($input['types'] ?? 'Delinking') === 'Email retrive')];
        }
        $modification = in_array($service, ['nin-modification', 'bvn-modification'], true);
        $needsPhoto = $method === 'crm' || ($service === 'nin-modification' && ($input['types'] ?? '') !== 'gender');
        if ($modification || $method === 'demographics') {
            $inputRules = $this->manualInputRules($service, $method, $input);
        }
        $methods = match ($service) {
            'nin-modification' => ['first', 'second'],
            'bvn-modification' => array_keys(ServiceCatalog::bvnKinds()),
            'nin-v1', 'nin-v2', 'nin-v3' => ['demographics'],
            'delinking' => ['nin'],
            default => array_keys(ServiceCatalog::all()[$service]['methods'] ?? []),
        };
        Validator::make(compact('service', 'method', 'requestKey', 'proof', 'input'), [
            ...$inputRules,
            'service' => ['required', Rule::in(ServiceRequest::SERVICES)],
            'method' => ['required', Rule::in($methods)],
            'requestKey' => ['required', 'uuid'],
            'proof' => $needsPhoto ? ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'] : ['prohibited'],
        ])->validate();
        if ($proof !== null) {
            $input['proof_sha256'] = hash_file('sha256', $proof->getRealPath());
        }
        $createdPaths = [];
        try {
            $record = DB::transaction(function () use ($user, $service, $method, $input, $requestKey, $proof, &$createdPaths): ServiceRequest {
                $owner = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_unless($owner->is_active && ! $owner->needsEmailVerification(), 403);
                ServiceAvailability::validateSubmission($service, self::priceMethods($service, $method, $input));
                $existing = ServiceRequest::query()->where('request_key', $requestKey)->first();
                if ($existing !== null) {
                    if ($existing->user_id !== $owner->id || $existing->service !== $service || $existing->method !== $method || $existing->payload !== $input) {
                        throw ValidationException::withMessages(['serviceAvailability' => 'This request reference has already been used. Reload the page before submitting another request.']);
                    }

                    return $existing;
                }
                $price = self::priceFor($service, $method, $input);
                if ($price === null) {
                    throw ValidationException::withMessages(['serviceAvailability' => 'Pricing is not available for this service yet. Please try again later.']);
                }
                $oldBalance = $owner->wallet_balance;
                if ($oldBalance < $price) {
                    throw ValidationException::withMessages(['serviceAvailability' => 'Your wallet balance is insufficient. Fund your wallet before submitting this request.']);
                }
                $path = null;
                if ($proof !== null) {
                    $path = $proof->store('service-request-proofs/'.$owner->id, 'local');
                    if (! is_string($path) || $path === '') {
                        throw ValidationException::withMessages(['screenshotProof' => 'The screenshot could not be saved. Please try again.']);
                    }
                    $createdPaths[] = $path;
                }
                $record = ServiceRequest::create([
                    'reference' => (string) Str::ulid(), 'request_key' => $requestKey, 'user_id' => $owner->id,
                    'service' => $service, 'method' => $method, 'payload' => $input, 'screenshot_path' => $path,
                    'quoted_price' => $price, 'status' => 'pending',
                ]);
                $owner->wallet_balance = $oldBalance - $price;
                $owner->save();
                WalletTransaction::create([
                    'service_request_id' => $record->id,
                    'reference' => $record->reference,
                    'request_key' => (string) Str::uuid(),
                    'user_id' => $owner->id,
                    'actor_id' => $owner->id,
                    'service' => $record->serviceLabel(),
                    'type' => 'service_debit',
                    'status' => 'completed',
                    'amount' => $price,
                    'old_balance' => $oldBalance,
                    'new_balance' => $owner->wallet_balance,
                    'reason' => 'Service request '.$record->reference,
                ]);
                AdminAudit::create(['actor_id' => $owner->id, 'user_id' => $owner->id, 'event' => 'service_request.submitted', 'details' => ['reference' => $record->reference, 'service' => $service, 'amount' => $price]]);

                return $record;
            }, 3);
        } catch (Throwable $exception) {
            foreach ($createdPaths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        foreach ($createdPaths as $path) {
            if ($path !== $record->screenshot_path) {
                Storage::disk('local')->delete($path);
            }
        }

        return $record;
    }
}
