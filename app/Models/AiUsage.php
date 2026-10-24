<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'organisation_id', 'product_version_id', 'feature', 'units', 'provider', 'model',
    'tokens_in', 'tokens_out', 'duration_ms', 'outcome'])]
class AiUsage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ai_usage';
}
