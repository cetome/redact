<?php

namespace App\Mail\Auth;

/** The sign-in (or sign-up) e-mail: a magic link and a 6-digit code. */
class SignInMail extends AuthMail
{
    public function __construct(
        string $recipientName,
        string $locale,
        public readonly string $url,
        public readonly string $code,
        public readonly bool $newAccount = false,
    ) {
        parent::__construct($recipientName, $locale);
    }

    protected function subjectLine(): string
    {
        return $this->newAccount ? __('auth.mail.signup_subject') : __('auth.mail.signin_subject');
    }

    protected function body(): array
    {
        $minutes = (int) config('redact.auth.token_ttl', 15);

        return [
            'intro' => [$this->newAccount ? __('auth.mail.signup_intro') : __('auth.mail.signin_intro')],
            'button' => ['label' => __('auth.mail.signin_button'), 'url' => $this->url],
            'code_label' => __('auth.mail.signin_code_label'),
            'code' => $this->code,
            'outro' => [
                __('auth.mail.validity', ['minutes' => $minutes]),
                __('auth.mail.not_you'),
            ],
        ];
    }
}
