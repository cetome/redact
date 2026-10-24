<?php

namespace App\Mail\Auth;

/**
 * A code only: confirming it is you (purpose "confirm"), a new e-mail address
 * ("email_change") or the address that keeps a guest sandbox ("guest_claim").
 */
class VerificationCodeMail extends AuthMail
{
    public function __construct(
        string $recipientName,
        string $locale,
        public readonly string $code,
        public readonly string $purpose,
    ) {
        parent::__construct($recipientName, $locale);
    }

    protected function subjectLine(): string
    {
        return match ($this->purpose) {
            'email_change' => __('auth.mail.email_change_subject'),
            'guest_claim' => __('guest.mail.subject'),
            default => __('auth.mail.confirm_subject'),
        };
    }

    protected function body(): array
    {
        return [
            'intro' => match ($this->purpose) {
                'email_change' => [__('auth.mail.email_change_intro')],
                'guest_claim' => [__('guest.mail.intro'), __('guest.mail.existing')],
                default => [__('auth.mail.confirm_intro')],
            },
            'code_label' => __('auth.mail.code_label'),
            'code' => $this->code,
            'outro' => [
                __('auth.mail.validity', ['minutes' => (int) config('redact.auth.token_ttl', 15)]),
                __('auth.mail.not_you'),
            ],
        ];
    }
}
