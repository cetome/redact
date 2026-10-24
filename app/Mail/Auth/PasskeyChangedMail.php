<?php

namespace App\Mail\Auth;

/** A passkey was added to or removed from the account. */
class PasskeyChangedMail extends AuthMail
{
    public function __construct(
        string $recipientName,
        string $locale,
        public readonly string $passkeyName,
        public readonly bool $added,
    ) {
        parent::__construct($recipientName, $locale);
    }

    protected function subjectLine(): string
    {
        return $this->added ? __('auth.mail.passkey_added_subject') : __('auth.mail.passkey_removed_subject');
    }

    protected function body(): array
    {
        $key = $this->added ? 'auth.mail.passkey_added_intro' : 'auth.mail.passkey_removed_intro';

        return [
            'intro' => [__($key, ['name' => $this->passkeyName])],
            'button' => ['label' => __('auth.mail.review_account'), 'url' => route('account.show')],
            'outro' => [__('auth.mail.passkey_outro', ['contact' => (string) config('redact.security_contact')])],
        ];
    }
}
