<?php
declare(strict_types=1);

namespace Walkie\Kernel;

final class Validator
{
    public static function email(mixed $value): string
    {
        if (!is_string($value)) {
            throw ApiException::badRequest('Email is required', 'invalid_email');
        }
        $email = strtolower(trim($value));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL);
        if ($email === false || strlen($email) > 255) {
            throw ApiException::badRequest('Invalid email address', 'invalid_email');
        }
        return $email;
    }

    public static function code(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{6}$/', $value)) {
            throw ApiException::badRequest('Invalid code format', 'invalid_code');
        }
        return $value;
    }

    public static function displayName(mixed $value): string
    {
        if (!is_string($value)) {
            throw ApiException::badRequest('Invalid name', 'invalid_name');
        }
        $name = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        // Strip control characters.
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        if ($name === '' || mb_strlen($name) > 60) {
            throw ApiException::badRequest('Name must be 1-60 characters', 'invalid_name');
        }
        return $name;
    }

    public static function positiveInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $n = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $n = (int) $value;
        } else {
            throw ApiException::badRequest("Invalid $field", 'invalid_id');
        }
        if ($n <= 0) {
            throw ApiException::badRequest("Invalid $field", 'invalid_id');
        }
        return $n;
    }
}
