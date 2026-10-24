<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One e-mail for an organisation invitation and the products offered with it (its children).
 */
class OrganisationInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('orgteam.mail.subject', ['organisation' => $this->invitation->organisation->name]));
    }

    public function content(): Content
    {
        $organisation = $this->invitation->organisation;
        $replace = [
            'inviter' => $this->invitation->inviter->name,
            'organisation' => $organisation->name,
            'role' => __('orgteam.role.'.($this->invitation->organisation_role === 'admin' ? 'admin' : 'member')),
        ];
        $products = $this->invitation->children()->with('product')->get()
            ->filter(fn (Invitation $child) => $child->product && $child->role)
            ->map(fn (Invitation $child) => $child->product->name.' ('.$child->role->label().')')
            ->implode(', ');

        $intro = [__('orgteam.mail.intro', $replace)];
        if ($products !== '') {
            $intro[] = __('orgteam.mail.products', ['products' => $products]);
        }

        return new Content(
            view: 'mail.auth.message',
            text: 'mail.auth.message-text',
            with: [
                'subjectLine' => __('orgteam.mail.subject', $replace),
                'greeting' => __('team.accept_title'),
                'body' => [
                    'intro' => $intro,
                    'button' => ['url' => route('invitations.show', $this->token), 'label' => __('team.mail_action')],
                    'outro' => [__('team.mail_footer')],
                ],
            ],
        );
    }
}
