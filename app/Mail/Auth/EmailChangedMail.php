<?php

namespace App\Mail\Auth;

/** Sent to the old address once the account's e-mail address has changed. */
class EmailChangedMail extends AuthMail
{
    public function __construct(string $recipientName, string $locale, public readonly string $newEmail)
    {
        parent::__construct($recipientName, $locale);
    }

    protected function subjectLine(): string
    {
        return __('auth.mail.email_changed_subject');
    }

    protected function body(): array
    {
        return [
            'intro' => [__('auth.mail.email_changed_intro', ['email' => self::mask($this->newEmail)])],
            'outro' => [__('auth.mail.email_changed_outro', ['contact' => (string) config('redact.security_contact')])],
        ];
    }

    /** j***@example.com: enough to recognise it, not enough to harvest it. */
    public static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
