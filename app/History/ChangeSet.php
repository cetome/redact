<?php

namespace App\History;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * What an activity entry changed, and the filters both audit views share: the console's activity
 * explorer (App\Admin\ActivityExplorer) and the customer histories (App\History\Feed).
 */
final class ChangeSet
{
    /**
     * Old and new attribute values (spatie v5 keeps them in attribute_changes; older entries and
     * explicit entries such as the console's "plan changed" carry them in properties).
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public static function raw(Activity $activity): array
    {
        $changes = $activity->attribute_changes?->toArray() ?? [];
        if ($changes === []) {
            $properties = $activity->properties?->toArray() ?? [];
            $changes = array_intersect_key($properties, ['attributes' => true, 'old' => true]);
        }

        return ['old' => (array) ($changes['old'] ?? []), 'new' => (array) ($changes['attributes'] ?? [])];
    }

    /**
     * Attribute changes as rows "field: old → new", values shortened for display.
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function rows(Activity $activity): array
    {
        ['old' => $old, 'new' => $new] = self::raw($activity);

        $rows = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $rows[] = [
                'field' => (string) $field,
                'old' => array_key_exists($field, $old) ? self::display($old[$field]) : '',
                'new' => array_key_exists($field, $new) ? self::display($new[$field]) : '',
            ];
        }

        return $rows;
    }

    public static function display(mixed $value): string
    {
        return match (true) {
            $value === null => '∅',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => mb_strimwidth((string) $value, 0, 300, '…'),
            default => mb_strimwidth((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 300, '…'),
        };
    }

    /**
     * Date range (Y-m-d, inclusive) read in the given time zone.
     *
     * @param  Builder<Activity>  $query
     */
    public static function period(Builder $query, ?string $from, ?string $to, ?string $timezone = null): void
    {
        $timezone = $timezone ?: (string) config('app.timezone');
        if (! empty($from)) {
            $query->where('created_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $from, $timezone)->startOfDay()->utc());
        }
        if (! empty($to)) {
            $query->where('created_at', '<=', CarbonImmutable::createFromFormat('Y-m-d', $to, $timezone)->endOfDay()->utc());
        }
    }

    public static function like(string $text): string
    {
        return '%'.addcslashes($text, '%_\\').'%';
    }
}
