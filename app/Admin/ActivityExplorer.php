<?php

namespace App\Admin;

use App\History\ChangeSet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Filters and readable change sets for the activity log explorer.
 */
final class ActivityExplorer
{
    /** Validation rules of the explorer filters (shared by the page and the CSV export). */
    public static function rules(): array
    {
        return [
            'log' => ['nullable', 'string', 'max:64'],
            'causer' => ['nullable', 'string', 'max:191'],
            'subject' => ['nullable', 'string', 'max:191'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @param  array<string, string|null>  $filters
     * @return Builder<Activity>
     */
    public function query(array $filters): Builder
    {
        $query = Activity::query()->with(['causer']);

        if (! empty($filters['log'])) {
            $query->where('log_name', $filters['log']);
        }

        if (! empty($filters['subject'])) {
            $query->where('subject_type', $filters['subject']);
        }

        if (! empty($filters['causer'])) {
            $causer = trim((string) $filters['causer']);
            $ids = ctype_digit($causer)
                ? [(int) $causer]
                : User::query()->where('email', 'like', '%'.addcslashes($causer, '%_\\').'%')->limit(200)->pluck('id')->all();
            $query->where('causer_type', (new User)->getMorphClass())->whereIn('causer_id', $ids);
        }

        ChangeSet::period($query, $filters['from'] ?? null, $filters['to'] ?? null);

        if (! empty($filters['q'])) {
            $query->where('description', 'like', ChangeSet::like((string) $filters['q']));
        }

        return $query->orderByDesc('id');
    }

    /** @return list<string> */
    public function logNames(): array
    {
        return DB::table('activity_log')->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name')->all();
    }

    /** @return array<string, string> subject type => short name */
    public function subjectTypes(): array
    {
        return DB::table('activity_log')->whereNotNull('subject_type')->distinct()->orderBy('subject_type')
            ->pluck('subject_type')
            ->mapWithKeys(fn ($type) => [$type => class_basename((string) $type)])
            ->all();
    }

    /**
     * Attribute changes as rows "field: old → new" (shared with the customer histories).
     *
     * @return list<array{field: string, old: string, new: string}>
     */
    public static function changes(Activity $activity): array
    {
        return ChangeSet::rows($activity);
    }

    /**
     * Other properties (context of the event), without the change set.
     *
     * @return array<string, string>
     */
    public static function context(Activity $activity): array
    {
        $properties = $activity->properties?->toArray() ?? [];
        unset($properties['attributes'], $properties['old']);

        return array_map(self::display(...), $properties);
    }

    public static function display(mixed $value): string
    {
        return ChangeSet::display($value);
    }

    public static function causerLabel(Activity $activity): string
    {
        $causer = $activity->causer;
        if ($causer instanceof User) {
            return $causer->email;
        }

        return $activity->causer_id ? class_basename((string) $activity->causer_type).' #'.$activity->causer_id : '';
    }

    /** @return list<string> */
    public static function csvHeader(): array
    {
        return ['id', 'created_at', 'log', 'event', 'description', 'causer', 'subject_type', 'subject_id', 'changes', 'properties'];
    }

    /** @return \Generator<int, list<mixed>> */
    public function csvRows(array $filters): \Generator
    {
        foreach ($this->query($filters)->reorder()->lazyByIdDesc(500) as $activity) {
            yield [
                $activity->id,
                $activity->created_at,
                $activity->log_name,
                $activity->event,
                $activity->description,
                self::causerLabel($activity),
                $activity->subject_type ? class_basename($activity->subject_type) : null,
                $activity->subject_id,
                collect(self::changes($activity))->map(fn ($c) => $c['field'].': '.$c['old'].' -> '.$c['new'])->implode('; '),
                self::context($activity),
            ];
        }
    }
}
