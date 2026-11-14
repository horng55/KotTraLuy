<?php

namespace App\Services;

class ContactNormalizer
{
    public static function email(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    public static function phone(?string $value): ?string
    {
        $value = preg_replace('/[\s().-]+/', '', trim((string) $value));
        if (str_starts_with($value, '00')) $value = '+' . substr($value, 2);
        if (preg_match('/^0[1-9]\d{7,9}$/', $value)) $value = '+855' . substr($value, 1);
        return preg_match('/^\+[1-9]\d{7,14}$/', $value) ? $value : null;
    }

    public static function parse(string $contact): array
    {
        $email = self::email($contact);
        if ($email) return ['email' => $email, 'phone' => null, 'channel' => 'email'];
        $phone = self::phone($contact);
        if ($phone) return ['email' => null, 'phone' => $phone, 'channel' => 'phone'];
        return ['email' => null, 'phone' => null, 'channel' => null];
    }
}
