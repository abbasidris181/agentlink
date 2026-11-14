<?php

namespace App\Models;

use App\BvnLicenseOnboardingInput;
use App\Casts\DecimalMoney;
use App\DemographicsSearchInput;
use App\ServiceCatalog;
use Database\Factories\ServiceRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['reference', 'request_key', 'user_id', 'service', 'method', 'payload', 'screenshot_path', 'quoted_price', 'status', 'reply', 'handled_by', 'handled_at', 'revision'])]
#[Hidden(['payload', 'screenshot_path', 'reply'])]
class ServiceRequest extends Model
{
    /** @use HasFactory<ServiceRequestFactory> */
    use HasFactory;

    public const ADMIN_SERVICES = ['bvn-retrieval', 'delinking', 'validations', 'mod-ipe', 'ipe-clearing', 'nin-modification', 'bvn-modification', 'bvn-license-onboarding', 'demographics-search', 'complaints'];

    public const SERVICES = [...self::ADMIN_SERVICES, 'nin-v1', 'nin-v2', 'nin-v3'];

    public const STATUSES = ['pending' => 'Pending', 'in_progress' => 'In progress', 'completed' => 'Completed', 'rejected' => 'Rejected', 'refunded' => 'Refunded'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'reply' => 'encrypted', 'quoted_price' => DecimalMoney::class, 'handled_at' => 'datetime', 'revision' => 'integer'];
    }

    public function walletTransaction(): HasOne
    {
        return $this->hasOne(WalletTransaction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function serviceLabel(): string
    {
        return ServiceCatalog::requestServices()[$this->service]['label'] ?? ServiceCatalog::all()[$this->service]['label'] ?? $this->service;
    }

    public function methodLabel(): string
    {
        return match ($this->service) {
            'nin-modification' => ucfirst($this->method).' modification',
            'bvn-modification' => ServiceCatalog::bvnKinds()[$this->method] ?? $this->method,
            default => ServiceCatalog::requestServices()[$this->service]['methods'][$this->method] ?? $this->method,
        };
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function inputSummary(): string
    {
        $input = $this->payload;

        if ($this->service === 'delinking' && isset($input['types'])) {
            return $input['nin'].' | '.$input['types'].(! empty($input['email']) ? ' | '.$input['email'] : '');
        }

        if (in_array($this->service, ['nin-modification', 'bvn-modification'], true)) {
            return ($input['bvn'] ?? $input['nin']).' | '.$input['types'];
        }

        return match ($this->method) {
            'search' => $input['first_name'].' '.$input['last_name'].' | '.$input['dob'],
            'enrollment' => $input['bvn'].' | '.$input['first_name'].' '.$input['last_name'],
            'demographics' => $input['first_name'].' '.$input['surname'].' | '.$input['dob'],
            'complaint' => $input['types'].' | '.$input['complaint'],
            'nin' => $input['nin'],
            'tracking' => $input['tracking_id'],
            'phone' => $input['phone'].' | '.$input['full_name'],
            'crm' => 'Agent: '.$input['agent_code'].' | Ticket: '.$input['ticket_id'].' | BMS: '.$input['bms_ticket'],
            default => '',
        };
    }

    /** @return array<string, string> */
    public static function inputColumnsFor(?string $service): array
    {
        return match ($service) {
            'demographics-search' => DemographicsSearchInput::FIELDS,
            'bvn-license-onboarding' => BvnLicenseOnboardingInput::FIELDS,
            'nin-modification', 'bvn-modification' => ['nin' => 'NIN number', 'bvn' => 'BVN number', 'types' => 'Type', 'first_name' => 'First name', 'surname' => 'Surname', 'middle_name' => 'Middle name', 'old_first_name' => 'Old first name', 'old_surname' => 'Old surname', 'old_middle_name' => 'Old middle name', 'dob' => 'Date of birth', 'old_dob' => 'Old date of birth', 'phone' => 'New phone number', 'address' => 'Address', 'gender' => 'Gender', 'screenshot_proof' => 'Clear photo'],
            'nin-v1', 'nin-v2', 'nin-v3' => ['first_name' => 'First name', 'surname' => 'Surname', 'middle_name' => 'Middle name', 'dob' => 'Date of birth', 'gender' => 'Gender'],
            'complaints' => ['types' => 'Type', 'complaint' => 'Complain'],
            'validations' => ['nin' => 'NIN number'],
            'delinking' => ['nin' => 'NIN number', 'types' => 'Type', 'email' => 'Email'],
            'ipe-clearing', 'mod-ipe' => ['tracking_id' => 'Tracking ID'],
            'bvn-retrieval' => ['phone' => 'Registered phone number', 'full_name' => 'Full name (as on record)', 'agent_code' => 'Agent code', 'ticket_id' => 'Ticket ID', 'bms_ticket' => 'BMS ticket', 'screenshot_proof' => 'Screenshot proof'],
            default => [],
        };
    }

    /** @return array<string, string> */
    public function inputDetails(): array
    {
        $labels = ['complaint' => 'Complain', 'types' => 'Type', 'email' => 'Email', 'nin' => 'NIN number', 'tracking_id' => 'Tracking ID', 'phone' => 'Registered phone', 'full_name' => 'Full name', 'agent_code' => 'Agent code', 'ticket_id' => 'Ticket ID', 'bms_ticket' => 'BMS ticket'];
        $labels = self::inputColumnsFor($this->service) + $labels;
        $details = [];
        foreach ($this->payload as $key => $value) {
            if (isset($labels[$key])) {
                if ($key === 'types' && in_array($this->service, ['nin-modification', 'bvn-modification'], true)) {
                    $value = implode(', ', array_map(fn (string $type): string => ServiceCatalog::modificationTypes()[$type] ?? $type, explode(', ', $value)));
                }
                $details[$labels[$key]] = $value;
            }
        }

        return $details;
    }
}
