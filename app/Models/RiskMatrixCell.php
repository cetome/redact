<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Impact × likelihood → level. The level is looked up, never computed from the score.
 */
#[Fillable(['product_version_id', 'impact', 'likelihood', 'level_rank'])]
class RiskMatrixCell extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['impact' => 'integer', 'likelihood' => 'integer', 'level_rank' => 'integer'];
    }
}
