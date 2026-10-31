<?php

namespace App\Models;

use App\Content\ContentPack;
use App\Content\Text;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * A named risk level of a version's matrix. Rank 1 is the most severe.
 *
 * A new version stores the content pack's level names in its creator's language. While a name is
 * still the pack's own (in any language), it reads in the viewer's language; a name the team wrote
 * itself stays as written.
 */
#[Fillable(['product_version_id', 'rank', 'name', 'color'])]
class RiskLevel extends Model
{
    protected function casts(): array
    {
        return ['rank' => 'integer'];
    }

    /** @return Attribute<string|null, never> */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn (?string $value, array $attributes) => self::translated(
            $value, isset($attributes['rank']) ? (int) $attributes['rank'] : null,
        ));
    }

    /** The pack's name in the current language when $stored is a pack level name (of $rank when known). */
    public static function translated(?string $stored, ?int $rank = null): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return $stored;
        }
        $wanted = mb_strtolower(trim($stored));
        foreach (self::packLevels() as $level) {
            if ($rank !== null && (int) ($level['rank'] ?? 0) !== $rank) {
                continue;
            }
            if (in_array($wanted, array_map(fn (string $n) => mb_strtolower(trim($n)), self::packNames($level)), true)) {
                return Text::of($level['name']);
            }
        }

        return $stored;
    }

    /**
     * Every language's pack name of a rank (to read a level written in another language).
     *
     * @return list<string>
     */
    public static function packNamesOf(int $rank): array
    {
        foreach (self::packLevels() as $level) {
            if ((int) ($level['rank'] ?? 0) === $rank) {
                return self::packNames($level);
            }
        }

        return [];
    }

    /** @return list<array<string, mixed>> */
    private static function packLevels(): array
    {
        try {
            return array_values(array_filter((array) (app(ContentPack::class)->risk()['levels'] ?? []), 'is_array'));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $level
     * @return list<string>
     */
    private static function packNames(array $level): array
    {
        $name = $level['name'] ?? null;

        return array_values(array_filter(array_map('strval', is_array($name) ? $name : [$name]), fn (string $n) => $n !== ''));
    }
}
