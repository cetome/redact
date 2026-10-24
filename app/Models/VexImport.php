<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uploaded VEX statements (CycloneDX VEX, OpenVEX, CSAF VEX, or the VEX an SBOM embeds),
 * shown in a preview until a person records the ticked ones (App\Sbom\Vex\VexImporter).
 *
 * @property string $id
 * @property string $product_version_id
 * @property int|null $user_id
 * @property string|null $sbom_import_id
 * @property string $format
 * @property list<array{name: string, path: string, size: int, sha256: string, mime: string}> $files
 * @property list<array<string, mixed>> $statements
 * @property int $statement_count
 * @property string $status
 * @property array<string, int>|null $summary
 * @property Carbon|null $imported_at
 * @property Carbon $created_at
 */
#[Fillable(['product_version_id', 'user_id', 'sbom_import_id', 'format', 'files', 'statements', 'statement_count', 'status', 'summary', 'imported_at'])]
class VexImport extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'statements' => 'array',
            'summary' => 'array',
            'imported_at' => 'datetime',
            'statement_count' => 'integer',
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
