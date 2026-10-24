<?php

namespace App\Jobs\Contact;

use App\Mail\Contact\ContactMessageMail;
use App\Models\ContactMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mails a contact form message off the request (database queue, drained by the cron on shared
 * hosting) to cetome (config redact.contact.to, Reply-To the visitor). Nothing is ever mailed to
 * the address typed in the form (owner, 2026-10-23: a public form that mails any address gets the
 * domain blacklisted). sent_at is set once sent, so a retry never sends it twice; after the last
 * attempt the reason is kept on the row.
 */
class SendContactMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $messageId) {}

    public function handle(): void
    {
        $message = ContactMessage::find($this->messageId);
        if (! $message || $message->sent_at !== null) {
            return;
        }

        Mail::to((string) config('redact.contact.to'))->send(new ContactMessageMail($message));
        $message->forceFill(['sent_at' => now(), 'failure' => null])->save();
    }

    public function failed(?Throwable $exception): void
    {
        ContactMessage::whereKey($this->messageId)->update([
            'failure' => Str::limit(class_basename($exception ?? 'Error').': '.($exception?->getMessage() ?? ''), 250),
        ]);
    }
}
