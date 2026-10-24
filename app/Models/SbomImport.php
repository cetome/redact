<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One uploaded bill of materials (SBOM / HBOM): its original file(s) on the private disk and
 * the components parsed from it, shown in a preview until a person imports the ticked ones.
 *
 * @property string $id
 * @property string $product_version_id
 * @property int|null $user_id
 * @property string $format
 * @property string $kind
 * @property list<array{name: string, path: string, size: int, sha256: string, mime: string}> $files
 * @property list<array<string, mixed>> $components
 * @property int $component_count
 * @property list<string>|null $warnings
 * @property string $status
 * @property array<string, int>|null $summary
 * @property Carbon|null $imported_at
 * @property Carbon $created_at
 */
#[Fillable(['product_version_id', 'user_id', 'format', 'kind', 'files', 'components', 'component_count', 'warnings', 'status', 'summary', 'imported_at'])]
class SbomImport extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'components' => 'array',
            'warnings' => 'array',
            'summary' => 'array',
            'imported_at' => 'datetime',
            'component_count' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
