<?php

namespace App\Listeners\Mail;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Every message leaves with the envelope sender (Return-Path, SMTP MAIL FROM, sendmail -f) set to
 * MAIL_FROM_ADDRESS (no-reply@cetome.com), whatever a Mailable sets.
 *
 * cetome.com's DMARC record asks for strict SPF alignment (aspf=s): the envelope sender must be
 * exactly the From domain, cetome.com. Symfony Mailer takes the envelope sender from the Sender,
 * then the Return-Path, then the From header; pinning Return-Path keeps it right even if a message
 * gains a Sender header one day, and the sendmail transport passes it as -f (see
 * docs/DEPLOY-shared-hosting.md, Mail). The receiving server replaces the header with the envelope
 * it saw, so nothing about it is visible to the reader.
 */
class AlignEnvelopeSender
{
    public function handle(MessageSending $event): void
    {
        $from = (string) config('mail.from.address');
        if ($from === '' || ! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $event->message->returnPath(new Address($from));
    }
}
