<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A mapping between two inventory items (asset ↔ interface, asset ↔ entity, ...),
 * the web equivalent of the Excel "Mapping_*" matrices.
 */
#[Fillable(['product_version_id', 'relation', 'from_item_id', 'to_item_id', 'attributes'])]
class InventoryLink extends Model
{
    use LogsActivity;

    protected function casts(): array
    {
        return ['attributes' => 'array'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['relation', 'from_item_id', 'to_item_id'])
            ->dontLogEmptyChanges()->useLogName('inventory');
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'from_item_id');
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'to_item_id');
    }
}
