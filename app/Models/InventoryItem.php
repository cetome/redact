<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One object of a root list (asset, interface, entity, software part, ...).
 * The list and the meaning of its attributes are defined by the content pack.
 *
 * An item with "members" is a group: EN 18031 lets objects with the same use case and
 * protection level be handled as one, so a group is ONE object for the decision trees,
 * E.Info and the risks. Each member keeps its name, and may keep its description, former
 * code and attributes (so ungrouping gives them back).
 *
 * @property string $id
 * @property string $product_version_id
 * @property string $list
 * @property string $code
 * @property string|null $name
 * @property string|null $description
 * @property array<string, mixed>|null $attributes
 * @property list<array{name: string, description?: string|null, code?: string|null, attributes?: array<string, mixed>}>|null $members
 * @property int $position
 * @property string|null $source_id the item of the earlier version it was copied from
 */
#[Fillable(['product_version_id', 'list', 'code', 'name', 'description', 'attributes', 'members', 'position', 'updated_by', 'source_id'])]
class InventoryItem extends Model
{
    use HasUlids, LogsActivity;

    protected function casts(): array
    {
        return ['attributes' => 'array', 'members' => 'array', 'position' => 'integer'];
    }

    /** An object added to a list marked "This product has none" lifts the mark (whatever added it). */
    protected static function booted(): void
    {
        static::created(function (InventoryItem $item) {
            $version = ProductVersion::query()->find($item->product_version_id, ['id', 'inventory_none']);
            if ($version && in_array($item->list, $version->noneLists(), true)) {
                $version->inventory_none = array_values(array_diff($version->noneLists(), [$item->list])) ?: null;
                $version->saveQuietly();
                activity('inventory')->performedOn($version)->causedBy(auth()->user())
                    ->withProperties(['list' => $item->list, 'item' => $item->code])->log('none cleared');
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['list', 'code', 'name', 'description', 'attributes', 'members'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('inventory');
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }

    /** @return HasMany<InventoryLink, $this> */
    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(InventoryLink::class, 'from_item_id');
    }

    /** @return HasMany<InventoryLink, $this> */
    public function incomingLinks(): HasMany
    {
        return $this->hasMany(InventoryLink::class, 'to_item_id');
    }

    public function attr(string $key, mixed $default = null): mixed
    {
        return data_get($this->attributes['attributes'] ? $this->getAttribute('attributes') : [], $key, $default);
    }

    public function isGroup(): bool
    {
        return $this->memberList() !== [];
    }

    /** @return list<array{name: string, description?: string|null, code?: string|null, attributes?: array<string, mixed>}> */
    public function memberList(): array
    {
        $members = $this->getAttribute('members');

        return is_array($members) ? array_values(array_filter($members, fn ($m) => is_array($m) && trim((string) ($m['name'] ?? '')) !== '')) : [];
    }

    /** @return list<string> */
    public function memberNames(): array
    {
        return array_map(fn (array $m) => (string) $m['name'], $this->memberList());
    }

    public function label(): string
    {
        return $this->name ? $this->code.' · '.$this->name : $this->code;
    }
}
