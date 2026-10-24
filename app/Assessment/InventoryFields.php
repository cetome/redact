<?php

namespace App\Assessment;

use Illuminate\Validation\Rule;

/**
 * Validation and casting of inventory attributes from the field schema of a
 * content-pack list. Supported field types: text, textarea, select,
 * multiselect, boolean, yesno (yes / no / na).
 */
final class InventoryFields
{
    /**
     * @param  array<string, mixed>  $list
     * @return array<string, mixed> Laravel validation rules for attributes.*
     */
    public static function rules(array $list): array
    {
        $rules = [];
        foreach ($list['fields'] ?? [] as $field) {
            $key = 'attributes.'.$field['key'];
            $required = ! empty($field['required']) ? 'required' : 'nullable';
            $options = array_column($field['options'] ?? [], 'value');

            $rules += match ($field['type']) {
                'text' => [$key => [$required, 'string', 'max:500']],
                'textarea' => [$key => [$required, 'string', 'max:10000']],
                'select' => [$key => [$required, Rule::in($options)]],
                'multiselect' => [$key => [$required, 'array'], $key.'.*' => [Rule::in($options)]],
                'boolean' => [$key => ['nullable', 'boolean']],
                'yesno' => [$key => [$required, Rule::in(['yes', 'no', 'na'])]],
                default => [],
            };
        }

        return $rules;
    }

    /**
     * Keep only the list's fields, with proper types.
     *
     * @param  array<string, mixed>  $list
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function cast(array $list, array $input): array
    {
        $attributes = [];
        foreach ($list['fields'] ?? [] as $field) {
            $value = $input[$field['key']] ?? null;
            $attributes[$field['key']] = match ($field['type']) {
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'multiselect' => array_values(array_filter((array) $value, 'is_string')),
                default => $value === '' ? null : $value,
            };
        }

        return $attributes;
    }

    /**
     * Standard names as one short phrase: "EN 18031-1, -2 and -3" (names sharing what comes before
     * their last dash keep only the end after the first one).
     *
     * @param  list<string>  $names
     */
    public static function partsList(array $names): string
    {
        // The edition year ("EN 18031-1:2024") is noise in this phrase.
        $names = array_map(fn (string $name) => (string) preg_replace('/:\d{4}$/', '', $name), $names);
        if ($names === []) {
            return '';
        }
        $cut = strrpos($names[0], '-');
        $stem = $cut === false ? null : substr($names[0], 0, $cut);
        $short = array_map(fn (string $name, int $i) => $i > 0 && $stem !== null && str_starts_with($name, $stem.'-')
            ? substr($name, strlen($stem)) : $name, $names, array_keys($names));
        $last = array_pop($short);

        return $short === [] ? $last : implode(', ', $short).' '.__('inventory.and').' '.$last;
    }
}
