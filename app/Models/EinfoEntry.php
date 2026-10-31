<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The documentation text written for one required E.Info item of one object.
 *
 * @property string $id
 * @property string $product_version_id
 * @property string $einfo
 * @property string|null $inventory_item_id
 * @property string|null $text
 * @property bool $recheck to confirm again after a significant change (App\Assessment\SignificantChange)
 */
#[Fillable(['product_version_id', 'einfo', 'inventory_item_id', 'text', 'updated_by'])]
class EinfoEntry extends Model
{
    use HasUlids, LogsActivity;

    protected function casts(): array
    {
        return ['recheck' => 'boolean'];
    }

    /** A new text is a re-check done: the mark goes. */
    protected static function booted(): void
    {
        static::saving(function (EinfoEntry $entry) {
            if ($entry->recheck && $entry->isDirty('text')) {
                $entry->recheck = false;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['einfo', 'inventory_item_id', 'text'])
            ->logOnlyDirty()->dontLogEmptyChanges()->useLogName('einfo');
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
