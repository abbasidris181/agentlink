<?php

namespace App;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BvnLicenseOnboardingInput
{
    public const FIELDS = [
        'last_name' => 'Last name', 'first_name' => 'First name', 'middle_name' => 'Middle name',
        'dob' => 'Date of birth', 'email' => 'Email', 'account_name' => 'Account name',
        'bank_name' => 'Bank name', 'account_number' => 'Account number', 'bvn' => 'BVN',
        'address' => 'Address', 'phone' => 'Phone number', 'city' => 'City',
        'location' => 'Location', 'state' => 'State', 'lga' => 'LGA', 'region' => 'Region',
    ];

    /** @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function validate(array $input, string $attribute = 'input'): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }
        if (is_string($input['phone'] ?? null)) {
            $input['phone'] = preg_replace('/[\\s()-]+/', '', $input['phone']);
        }
        $state = is_string($input['state'] ?? null) ? $input['state'] : '';
        $rules = [$attribute => ['required', 'array:'.implode(',', array_keys(self::FIELDS))]];
        $labels = [];
        foreach (self::FIELDS as $field => $label) {
            $labels[$attribute.'.'.$field] = $label;
            $rules[$attribute.'.'.$field] = ['required', 'string', ...match ($field) {
                'dob' => ['date_format:Y-m-d', 'before_or_equal:today'],
                'email' => ['email:rfc', 'max:254'],
                'account_number' => ['regex:/\\A[0-9]{10}\\z/'],
                'bvn' => ['regex:/\\A[0-9]{11}\\z/'],
                'phone' => ['regex:/\\A(?:0[1-9][0-9]{9}|(?:\\+?234)?[1-9][0-9]{9})\\z/'],
                'state' => [Rule::in(array_keys(NigeriaLocations::states()))],
                'lga' => [Rule::in(NigeriaLocations::lgas($state))],
                'region' => [Rule::in([NigeriaLocations::region($state)])],
                'address' => ['max:1000'],
                'account_name' => ['max:200'],
                default => ['max:150'],
            }];
        }

        return Validator::make([$attribute => $input], $rules, [
            $attribute.'.lga.in' => 'Select an LGA belonging to the selected state.',
            $attribute.'.state.in' => 'Select a Nigerian state or FCT.',
            $attribute.'.account_number.regex' => 'Enter a 10-digit account number.',
            $attribute.'.bvn.regex' => 'Enter an 11-digit BVN.',
        ], $labels)->validate()[$attribute];
    }
}
