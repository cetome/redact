<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message sent with the contact form (/contact). Read-only in the staff console; deleted after
 * the configured retention (redact:prune-tracking).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string $email
 * @property string|null $company
 * @property string $topic one of TOPICS
 * @property string $message
 * @property string $locale
 * @property string|null $ip_hash keyed hash (App\Http\Middleware\LogRequest::hashIp)
 * @property string|null $user_agent
 * @property Carbon|null $sent_at
 * @property string|null $failure
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'name', 'email', 'company', 'topic', 'message', 'locale', 'ip_hash', 'user_agent'])]
class ContactMessage extends Model
{
    /** The topic select, in this order; /contact/{topic} preselects one (pricing, self-hosted...). */
    public const TOPICS = ['pricing', 'self-hosted', 'expert', 'support', 'partnership', 'privacy', 'other'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The topic as shown in the form, in the current locale. */
    public function topicLabel(): string
    {
        return in_array($this->topic, self::TOPICS, true) ? (string) __('contact.topics.'.$this->topic) : $this->topic;
    }
}
