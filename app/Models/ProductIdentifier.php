<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One name or identifier as listed in the declaration of conformity or the
 * EU-type examination certificate. The models ("model" kind) belong to the
 * product (product_id, shown under its description); every other kind (trade
 * name, brand, HVIN...) belongs to one version (product_version_id).
 *
 * @property int $id
 * @property string|null $product_id
 * @property string|null $product_version_id
 * @property string $kind
 * @property string $value
 * @property string|null $notes
 * @property int $position
 */
#[Fillable(['product_id', 'product_version_id', 'kind', 'value', 'notes', 'position'])]
class ProductIdentifier extends Model
{
    use LogsActivity;

    public const KINDS = ['trade_name', 'brand', 'model', 'type', 'reference', 'hvin', 'fvin', 'hardware_version', 'software_version', 'other'];

    /** Product-level kind: the models, under the product description. */
    public const MODEL = 'model';

    /**
     * Kinds offered for a version's identifiers in the Product identification (with the models).
     * Never what the product card already holds (owner, 2026-10-23: no duplicated information): the
     * reference (Product::reference) and the hardware and software versions ("Product version", which
     * is what HVIN and FVIN identify). Older rows of those kinds stay listed with their kind.
     */
    public const VERSION_KINDS = ['trade_name', 'brand', 'type', 'other'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['kind', 'value', 'notes'])->logOnlyDirty()
            ->dontLogEmptyChanges()->useLogName('version');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }

    public function kindLabel(): string
    {
        return __('identification.kinds.'.$this->kind);
    }
}
