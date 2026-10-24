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
 */
#[Fillable(['product_version_id', 'einfo', 'inventory_item_id', 'text', 'updated_by'])]
class EinfoEntry extends Model
{
    use HasUlids, LogsActivity;

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
