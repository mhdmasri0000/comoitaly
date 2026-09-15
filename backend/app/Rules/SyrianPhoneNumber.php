<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts Syrian mobile numbers in one of two forms:
 *  - with country code: +9639XXXXXXXX  (e.g. +963934128426)
 *  - local:             09XXXXXXXX     (e.g. 0934128426)
 */
class SyrianPhoneNumber implements ValidationRule
{
    public const PATTERN = '/^(?:\+9639\d{8}|09\d{8})$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match(self::PATTERN, trim($value))) {
            $fail('The :attribute must be a valid Syrian number, like +963934128426 or 0934128426.');
        }
    }
}
