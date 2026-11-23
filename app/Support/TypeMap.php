<?php

namespace App\Support;

class TypeMap
{
    /**
     * Parse a "type:value,type:value" env string into [type => value].
     *
     * Malformed pairs are skipped rather than failing config load, so a typo
     * in .env degrades to "no override" instead of taking the app down.
     *
     * @return array<string, string>
     */
    public static function parse(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $map = [];

        foreach (explode(',', $value) as $pair) {
            [$type, $setting] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');

            if ($type === '' || $setting === '' || ! is_numeric($setting)) {
                continue;
            }

            $map[$type] = $setting;
        }

        return $map;
    }
}
