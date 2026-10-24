<?php

namespace App\Mail;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** To the new owner of a product (App\Actions\TransferOwnership). */
class ProductOwnershipTransferred extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Product $product, public User $by) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('transfer.mail.subject', ['product' => $this->product->name]));
    }

    public function content(): Content
    {
        $replace = ['product' => $this->product->name, 'by' => $this->by->name, 'organisation' => $this->product->organisation->name];

        return new Content(
            view: 'mail.auth.message',
            text: 'mail.auth.message-text',
            with: [
                'subjectLine' => __('transfer.mail.subject', $replace),
                'greeting' => __('transfer.mail.greeting'),
                'body' => [
                    'intro' => [__('transfer.mail.intro', $replace), __('roles.owner.help')],
                    'button' => ['url' => route('team.index', $this->product), 'label' => __('transfer.mail.action')],
                    'outro' => [],
                ],
            ],
        );
    }
}
