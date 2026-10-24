<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProductInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('team.mail_subject', ['product' => $this->invitation->product->name]));
    }

    public function content(): Content
    {
        $replace = [
            'inviter' => $this->invitation->inviter->name,
            'product' => $this->invitation->product->name,
            'role' => $this->invitation->role->label(),
        ];

        return new Content(
            view: 'mail.auth.message',
            text: 'mail.auth.message-text',
            with: [
                'subjectLine' => __('team.mail_subject', $replace),
                'greeting' => __('team.accept_title'),
                'body' => [
                    'intro' => [__('team.mail_intro', $replace), $this->invitation->role->help()],
                    'button' => ['url' => route('invitations.show', $this->token), 'label' => __('team.mail_action')],
                    'outro' => [__('team.mail_footer')],
                ],
            ],
        );
    }
}
