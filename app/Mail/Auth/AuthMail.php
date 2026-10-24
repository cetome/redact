<?php

namespace App\Mail\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Base of the account e-mails: one cetome-branded HTML layout and a plain
 * text twin, in the recipient's language (Mailable::send renders inside
 * that locale). Sender: MAIL_FROM_*.
 */
abstract class AuthMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $recipientName, string $locale)
    {
        $this->locale($locale);
    }

    abstract protected function subjectLine(): string;

    /**
     * Paragraphs, the call to action, the code and the closing note.
     *
     * @return array{intro: list<string>, button?: array{label: string, url: string}, code?: string, code_label?: string, outro: list<string>}
     */
    abstract protected function body(): array;

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(
            html: 'mail.auth.message',
            text: 'mail.auth.message-text',
            with: [
                'subjectLine' => $this->subjectLine(),
                'greeting' => __('auth.mail.greeting', ['name' => $this->recipientName]),
                'body' => $this->body(),
            ],
        );
    }
}
