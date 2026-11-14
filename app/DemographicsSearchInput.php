<?php

namespace App;

use Illuminate\Support\Facades\Validator;

class DemographicsSearchInput
{
    public const FIELDS = [
        'first_name' => 'First name', 'last_name' => 'Last name', 'middle_name' => 'Middle name',
        'dob' => 'Date of birth', 'gender' => 'Gender',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function validate(array $input, string $attribute = 'input'): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = trim($value);
            }
        }
        $input['middle_name'] ??= '';
        $validated = Validator::make([$attribute => $input], [
            $attribute => ['required', 'array:'.implode(',', array_keys(self::FIELDS))],
            $attribute.'.first_name' => ['required', 'string', 'max:100'],
            $attribute.'.last_name' => ['required', 'string', 'max:100'],
            $attribute.'.middle_name' => ['nullable', 'string', 'max:100'],
            $attribute.'.dob' => ['required', 'string', 'date_format:d-m-Y', 'before_or_equal:today'],
            $attribute.'.gender' => ['required', 'in:Male,Female'],
        ], [
            $attribute.'.dob.date_format' => 'Enter a valid date of birth in DD-MM-YYYY format.',
            $attribute.'.dob.before_or_equal' => 'Date of birth cannot be in the future.',
        ], array_combine(
            array_map(fn (string $field): string => $attribute.'.'.$field, array_keys(self::FIELDS)),
            array_values(self::FIELDS),
        ))->validate()[$attribute];

        return array_replace(array_fill_keys(array_keys(self::FIELDS), ''), $validated);
    }
}
