<?php
// Small checks used by every class. Every check either returns a clean value
// or throws InvalidArgumentException with a clear message.

class Validator
{
    // Required or optional text. Returns the trimmed text (or null if optional and empty).
    public static function text($value, string $label, int $max, bool $required = true): ?string
    {
        if ($value !== null && !is_string($value) && !is_numeric($value)) {
            throw new InvalidArgumentException("$label must be text.");
        }
        $value = trim((string)$value);

        if ($value === "") {
            if ($required) {
                throw new InvalidArgumentException("$label is required.");
            }
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("$label must be at most $max characters.");
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            throw new InvalidArgumentException("$label has characters that are not allowed.");
        }
        return $value;
    }

    // A person's name: letters, spaces, dot, apostrophe and hyphen (Ma. Cristina, De la Cruz, O'Neil).
    public static function name($value, string $label, bool $required = true): ?string
    {
        $value = self::text($value, $label, 100, $required);
        if ($value !== null && !preg_match("/^[\p{L}\p{M}][\p{L}\p{M}\s.'\-]*$/u", $value)) {
            throw new InvalidArgumentException("$label can only have letters, spaces, dots, apostrophes and hyphens.");
        }
        return $value;
    }

    public static function email($value): string
    {
        $value = strtolower((string)self::text($value, "Email", 191));
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Email is not a valid email address.");
        }
        return $value;
    }

    // Philippine mobile number. Accepts 09171234567, +639171234567, 639171234567, 9171234567
    // and always returns the 09XXXXXXXXX form, so login by phone always matches.
    public static function phone($value, string $label = "Phone number"): string
    {
        $value = (string)self::text($value, $label, 20);
        $digits = self::normalizePhone($value);

        if ($digits === null) {
            throw new InvalidArgumentException("$label must be a Philippine mobile number, like 09171234567.");
        }
        return $digits;
    }

    // Same as phone(), but gives back null instead of an error (used when searching).
    public static function normalizePhone(string $value): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', $value);

        if (strpos($digits, "+63") === 0) {
            $digits = "0" . substr($digits, 3);
        } elseif (strpos($digits, "63") === 0 && strlen($digits) === 12) {
            $digits = "0" . substr($digits, 2);
        } elseif (strlen($digits) === 10 && $digits[0] === "9") {
            $digits = "0" . $digits;
        }
        return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
    }

    // A real ID number: a whole number above 0. Text like "abc" or "-5" is rejected.
    public static function id($value, string $label): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int)$value > 0) {
            return (int)$value;
        }
        throw new InvalidArgumentException("$label must be a whole number greater than 0.");
    }

    public static function password($value): string
    {
        if (!is_string($value) || $value === "") {
            throw new InvalidArgumentException("Password is required.");
        }
        if (strlen($value) < 8) {
            throw new InvalidArgumentException("Password must be at least 8 characters.");
        }
        if (strlen($value) > 72) {
            throw new InvalidArgumentException("Password must be at most 72 characters.");
        }
        if (!preg_match('/[A-Za-z]/', $value) || !preg_match('/\d/', $value)) {
            throw new InvalidArgumentException("Password must have at least one letter and one number.");
        }
        return $value;
    }

    public static function oneOf($value, array $allowed, string $label): string
    {
        $value = (string)self::text($value, $label, 100);
        foreach ($allowed as $item) {
            if (strtolower($item) === strtolower($value)) {
                return $item;
            }
        }
        throw new InvalidArgumentException("$label must be one of: " . implode(", ", $allowed) . ".");
    }

    // A date written as YYYY-MM-DD that is real, not in the future and not before 1900.
    public static function pastDate($value, string $label): ?string
    {
        $value = self::text($value, $label, 10, false);
        if ($value === null) {
            return null;
        }
        $date = DateTime::createFromFormat("Y-m-d", $value);
        if (!$date || $date->format("Y-m-d") !== $value) {
            throw new InvalidArgumentException("$label must be a real date written as YYYY-MM-DD.");
        }
        if ($value > date("Y-m-d") || $value < "1900-01-01") {
            throw new InvalidArgumentException("$label is not a possible date.");
        }
        return $value;
    }

    // A web address (http or https) or a simple file path like uploads/id/front.jpg.
    public static function fileUrl($value, string $label, bool $required = true): ?string
    {
        $value = self::text($value, $label, 500, $required);
        if ($value === null) {
            return null;
        }
        $isWebAddress = preg_match('#^https?://#i', $value) && filter_var($value, FILTER_VALIDATE_URL);
        $isFilePath   = preg_match('#^[A-Za-z0-9_\-./]+$#', $value) && strpos($value, "..") === false;

        if (!$isWebAddress && !$isFilePath) {
            throw new InvalidArgumentException("$label must be a web address (http or https) or a file path.");
        }
        return $value;
    }

    public static function has(array $data, string $key): bool
    {
        return array_key_exists($key, $data);
    }
}
