<?php

namespace App\Models;

use App\Sbom\Vex\Statement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The manufacturer's analysis of one vulnerability for one component (or the whole product),
 * recorded from a VEX file: status, justification, detail, action, and where it came from.
 *
 * @property int $id
 * @property string $product_version_id
 * @property string $vulnerability_key
 * @property list<string>|null $aliases
 * @property string|null $vulnerability_item_id
 * @property string|null $inventory_item_id
 * @property string $status
 * @property string|null $justification
 * @property string|null $detail
 * @property string|null $action
 * @property string $format
 * @property string $source_file
 * @property string|null $vex_import_id
 * @property int|null $imported_by
 * @property Carbon|null $imported_at
 */
#[Fillable(['product_version_id', 'vulnerability_key', 'aliases', 'vulnerability_item_id', 'inventory_item_id', 'status', 'justification',
    'detail', 'action', 'format', 'source_file', 'vex_import_id', 'imported_by', 'imported_at'])]
class VexStatement extends Model
{
    protected function casts(): array
    {
        return ['aliases' => 'array', 'imported_at' => 'datetime'];
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_values(array_unique(array_merge([$this->vulnerability_key], $this->aliases ?? [])));
    }

    public function analysed(): bool
    {
        return Statement::analysed($this->status, $this->justification, $this->detail);
    }

    /** What the hover and the reports say: justification, detail, action. */
    public function explanation(): string
    {
        return implode(' · ', array_filter([Statement::justificationLabel($this->justification), $this->detail, $this->action]));
    }
}
