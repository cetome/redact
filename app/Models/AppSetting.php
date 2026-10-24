<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A setting of the install changed in the staff console (one row per area, e.g. "ai"), overriding
 * the .env values. Read through get() (cached until put() or forget()); secrets inside a value are
 * stored encrypted by their owner (App\Ai\AiSettings), never in clear.
 *
 * @property string $key
 * @property array<string, mixed> $value
 * @property int|null $updated_by
 * @property Carbon|null $updated_at
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The stored value of one area with its last change ("_updated_at", "_updated_by"), [] when
     * nothing is stored (or the table does not exist yet: the .env values apply).
     *
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        try {
            return Cache::rememberForever(self::cacheKey($key), function () use ($key) {
                $row = self::query()->find($key);
                if (! $row) {
                    return [];
                }

                return (array) $row->value + [
                    '_updated_at' => $row->updated_at?->toIso8601String(),
                    '_updated_by' => $row->updated_by,
                ];
            });
        } catch (Throwable) {
            return [];
        }
    }

    /** @param  array<string, mixed>  $value */
    public static function put(string $key, array $value, ?User $by = null): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->getKey()]);
        Cache::forget(self::cacheKey($key));
    }

    public static function forget(string $key): void
    {
        self::query()->whereKey($key)->delete();
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return 'app-settings:'.$key;
    }
}
