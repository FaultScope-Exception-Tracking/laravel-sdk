<?php

namespace FaultScope\Laravel\Support;

class ConfigHelpers
{
    public static function pipeList(?string $value, array $default = []): array
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return array_values(array_filter(array_map('trim', explode('|', $value))));
    }

    public static function bool(mixed $value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
