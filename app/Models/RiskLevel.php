<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A named risk level of a version's matrix. Rank 1 is the most severe.
 */
#[Fillable(['product_version_id', 'rank', 'name', 'color'])]
class RiskLevel extends Model
{
    protected function casts(): array
    {
        return ['rank' => 'integer'];
    }
}
