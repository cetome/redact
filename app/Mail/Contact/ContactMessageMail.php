<?php

namespace App\Mail\Contact;

use App\Billing\Plan;
use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A contact form message, to cetome (config redact.contact.to), in English. From MAIL_FROM_* like
 * every REDact mail; the visitor is in Reply-To only, never in From (cetome.com DMARC, aspf=s).
 * A signed-in sender's account, organisations and plans are added for cetome (never shown to them).
 * Sent by App\Jobs\Contact\SendContactMessage.
 */
class ContactMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContactMessage $contact)
    {
        $this->locale('en');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->contact->email, $this->contact->name)],
            subject: $this->subjectLine(),
        );
    }

    public function content(): Content
    {
        $c = $this->contact;
        $fields = [
            [__('contact.fields.name'), $c->name, null],
            [__('contact.fields.email'), $c->email, 'mailto:'.$c->email],
            [__('contact.fields.company'), $c->company ?: '—', null],
            [__('contact.fields.topic'), $c->topicLabel(), null],
            [__('contact.mail.language'), strtoupper($c->locale), null],
            ...$this->account(),
        ];

        return new Content(
            html: 'mail.contact.message',
            text: 'mail.contact.message-text',
            with: [
                'subjectLine' => $this->subjectLine(),
                'greeting' => null,
                'paragraphs' => [],
                'fields' => $fields,
                'body' => $c->message,
                'bodyLabel' => __('contact.fields.message'),
                'button' => ['label' => __('contact.mail.open_console'), 'url' => route('admin.contact.show', $c)],
                'footer' => __('contact.mail.staff_footer'),
            ],
        );
    }

    private function subjectLine(): string
    {
        return __('contact.mail.staff_subject', ['topic' => $this->contact->topicLabel(), 'name' => $this->contact->name]);
    }

    /**
     * The sender's account when signed in: id and address, then each organisation with its plan.
     *
     * @return list<array{0: string, 1: string, 2: null}>
     */
    private function account(): array
    {
        $user = $this->contact->user;
        if ($user === null) {
            return [[__('contact.mail.account'), __('contact.mail.no_account'), null]];
        }

        $rows = [[__('contact.mail.account'), '#'.$user->getKey().' · '.$user->email, null]];
        foreach ($user->organisations()->orderBy('name')->get() as $organisation) {
            $rows[] = [__('contact.mail.organisation'), $organisation->name.' · '.Plan::fresh($organisation)->label(), null];
        }

        return $rows;
    }
}
