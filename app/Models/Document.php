<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A product document uploaded for the AI to read (datasheet, architecture, manual).
 * Stored on the private disk; served only through an authorised controller.
 *
 * @property string $id
 * @property string $product_version_id
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $path
 * @property string|null $extracted_text
 * @property string|null $category heading of the technical documentation (App\Declaration\TechnicalFile::CATEGORIES)
 */
#[Fillable(['product_version_id', 'uploaded_by', 'original_name', 'mime', 'size', 'sha256', 'path', 'extracted_text', 'category'])]
#[Hidden(['path', 'extracted_text'])]
class Document extends Model
{
    use HasUlids, LogsActivity;

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['original_name', 'mime', 'size', 'category'])
            ->dontLogEmptyChanges()->useLogName('document');
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }
}
