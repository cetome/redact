<?php

namespace App\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Per-day series for the console charts. DATE() works on SQLite and MySQL/MariaDB.
 * Missing days are filled with 0 so every chart has one point per day.
 */
final class DailySeries
{
    /**
     * @param  string  $aggregate  SQL aggregate, e.g. COUNT(*), COUNT(DISTINCT user_id), SUM(units)
     * @return array<string, int> Y-m-d => value, oldest first
     */
    public static function of(Builder $query, int $days, string $aggregate = 'COUNT(*)', string $column = 'created_at'): array
    {
        $from = self::start($days);

        $rows = $query
            ->where($column, '>=', $from)
            ->selectRaw("DATE({$column}) as d, {$aggregate} as n")
            ->groupByRaw("DATE({$column})")
            ->pluck('n', 'd');

        return self::fill($rows->all(), $days);
    }

    public static function start(int $days): CarbonImmutable
    {
        return CarbonImmutable::today()->subDays($days - 1);
    }

    /**
     * @param  array<string, int|float|string|null>  $values
     * @return array<string, int>
     */
    public static function fill(array $values, int $days): array
    {
        $series = [];
        $day = self::start($days);
        for ($i = 0; $i < $days; $i++) {
            $key = $day->toDateString();
            $series[$key] = (int) ($values[$key] ?? 0);
            $day = $day->addDay();
        }

        return $series;
    }

    /**
     * Payload for the chart wrapper's data-chart attribute.
     *
     * @param  array<string, int>  $series
     * @return array{labels: list<string>, values: list<int>, label: string, type: string}
     */
    public static function chart(array $series, string $label, string $type = 'bar'): array
    {
        return [
            'labels' => array_keys($series),
            'values' => array_values($series),
            'label' => $label,
            'type' => $type,
        ];
    }
}
