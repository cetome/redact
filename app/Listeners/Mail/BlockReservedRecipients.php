<?php

namespace App\Listeners\Mail;

use App\Guest\Sandbox;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * Last line of defence: no message ever leaves for a reserved address
 * (sandbox guests are guest-<ulid>@guest.invalid). Reserved recipients are
 * dropped; when none is left the message is cancelled (returning false from
 * a MessageSending listener stops Laravel's mailer). Covers every Mailable,
 * queued or not, and mail notifications.
 */
class BlockReservedRecipients
{
    public function handle(MessageSending $event): ?bool
    {
        $message = $event->message;
        $blocked = 0;

        $keep = function (array $addresses) use (&$blocked): array {
            return array_values(array_filter($addresses, function (Address $address) use (&$blocked) {
                if (Sandbox::isReservedAddress($address->getAddress())) {
                    $blocked++;

                    return false;
                }

                return true;
            }));
        };

        $to = $keep($message->getTo());
        $cc = $keep($message->getCc());
        $bcc = $keep($message->getBcc());

        if ($blocked === 0) {
            return null;
        }

        Log::info('Mail to a reserved address was not sent.', ['blocked' => $blocked]);

        if ($to === [] && $cc === [] && $bcc === []) {
            return false;
        }

        foreach (['To' => $to, 'Cc' => $cc, 'Bcc' => $bcc] as $header => $addresses) {
            $message->getHeaders()->remove($header);
            if ($addresses !== []) {
                $message->getHeaders()->addMailboxListHeader($header, $addresses);
            }
        }

        return null;
    }
}
