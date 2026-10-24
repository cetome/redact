<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One threat of the STRIDE risk register.
 *
 * @property string $id
 * @property list<string>|null $stride
 * @property list<string>|null $articles
 * @property list<string>|null $requirements
 * @property int|null $impact
 * @property int|null $likelihood
 * @property int|null $residual_rank
 */
#[Fillable(['product_version_id', 'position', 'threat', 'description', 'stride', 'risk', 'impact', 'likelihood',
    'articles', 'requirements', 'remediation', 'implementation', 'residual_rank', 'updated_by'])]
class RiskEntry extends Model
{
    use HasUlids, LogsActivity;

    public const STRIDE = ['S', 'T', 'R', 'I', 'D', 'E'];

    protected function casts(): array
    {
        return [
            'stride' => 'array',
            'articles' => 'array',
            'requirements' => 'array',
            'impact' => 'integer',
            'likelihood' => 'integer',
            'residual_rank' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at', 'created_at'])->logOnlyDirty()
            ->dontLogEmptyChanges()->useLogName('risk');
    }

    /** @return BelongsTo<ProductVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProductVersion::class, 'product_version_id');
    }

    /** Informative only: the level comes from the matrix. */
    public function score(): ?int
    {
        return $this->impact && $this->likelihood ? $this->impact * $this->likelihood : null;
    }
}
