<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The consent given before a paid action (App\Billing\Billing::recordConsent): the person asks for
 * the service to start at once and acknowledges the EU consumer withdrawal rules (terms, "Consumers").
 *
 * @property int $id
 * @property string $organisation_id
 * @property int|null $user_id
 * @property string $action one of ACTIONS
 * @property int $quantity
 * @property string $terms_version
 * @property string|null $ip_hash keyed hash (App\Http\Middleware\LogRequest::hashIp)
 * @property Carbon $created_at
 */
class BillingConsent extends Model
{
    public const ACTIONS = ['checkout', 'add_product', 'reactivate'];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
