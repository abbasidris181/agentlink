<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

abstract class VerificationRecord extends Model
{
    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->field('firstName'), $this->field('middleName'), $this->field('lastName'),
        ])));
    }

    public function field(string $key): string
    {
        $value = data_get($this->result, $key);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function image(string $field): ?string
    {
        $value = preg_replace('/\s+/', '', $this->field($field));
        if ($value === '') {
            return null;
        }
        $encoded = preg_replace('#^data:image/(?:jpg|jpeg|png|webp);base64,#i', '', $value);
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) > 5 * 1024 * 1024) {
            return null;
        }
        $info = @getimagesizefromstring($decoded);
        if ($info === false || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$info['mime'].';base64,'.base64_encode($decoded);
    }

    /** @return array<string, string> */
    public function details(): array
    {
        $details = [];
        foreach (Arr::dot($this->result ?? []) as $key => $value) {
            if (in_array(explode('.', $key)[0], ['photo', 'signature'], true)) {
                continue;
            }
            $label = Str::headline(str_replace(['.', '_'], ' ', $key));
            $details[$label] = is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) && trim((string) $value) !== '' ? (string) $value : '—');
        }

        return $details;
    }
}
