<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Support\Str;

trait NormalizesPayload
{
    /**
     * Normalize an input array converting camelCase keys to snake_case.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    protected function normalizeInput(array $input): array
    {
        $normalized = [];
        foreach ($input as $key => $value) {
            $snakeKey = Str::snake($key);
            if (is_array($value) && ! empty($value)) {
                // If associative array, recursively normalize
                if (array_keys($value) !== range(0, count($value) - 1)) {
                    $value = $this->normalizeInput($value);
                } elseif (isset($value[0]) && is_array($value[0])) {
                    // Array of associative objects (e.g. requirements, course_allocations)
                    $value = array_map(fn ($item) => is_array($item) ? $this->normalizeInput($item) : $item, $value);
                }
            }
            $normalized[$snakeKey] = $value;
        }

        return $normalized;
    }
}
