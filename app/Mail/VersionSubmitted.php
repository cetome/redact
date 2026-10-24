<?php

namespace App\Mail;

use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VersionSubmitted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ProductVersion $version, public User $reviewer) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('review.mail_subject', ['product' => $this->version->displayName()]));
    }

    public function content(): Content
    {
        $replace = ['product' => $this->version->displayName(), 'name' => $this->reviewer->name];

        return new Content(
            view: 'mail.auth.message',
            text: 'mail.auth.message-text',
            with: [
                'subjectLine' => __('review.mail_subject', $replace),
                'greeting' => __('review.title'),
                'body' => [
                    'intro' => [__('review.mail_intro', $replace)],
                    'button' => ['url' => route('versions.review', $this->version), 'label' => __('review.mail_action')],
                    'outro' => [],
                ],
            ],
        );
    }
}
