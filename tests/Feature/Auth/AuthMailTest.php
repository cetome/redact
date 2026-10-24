<?php

namespace Tests\Feature\Auth;

use App\Mail\Auth\EmailChangedMail;
use App\Mail\Auth\PasskeyChangedMail;
use App\Mail\Auth\SignInMail;
use App\Mail\Auth\VerificationCodeMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthMailTest extends TestCase
{
    public function test_the_sign_in_mail_has_html_and_text_with_link_and_code(): void
    {
        $mail = new SignInMail('Ada', 'en', 'https://redact.test/login/link/abc', '012345');

        $mail->assertHasSubject('Your REDact sign-in link');
        $mail->assertSeeInHtml('https://redact.test/login/link/abc');
        $mail->assertSeeInHtml('012345');
        $mail->assertSeeInText('https://redact.test/login/link/abc');
        $mail->assertSeeInText('012345');
        $mail->assertSeeInText('Hello Ada,');
    }

    public function test_mails_follow_the_recipients_language(): void
    {
        $mail = new SignInMail('Ada', 'fr', 'https://redact.test/x', '111111', newAccount: true);

        $mail->assertHasSubject(__('auth.mail.signup_subject', [], 'fr'));
        $mail->assertSeeInText('Bonjour Ada,');
    }

    public function test_the_other_account_mails_render(): void
    {
        (new VerificationCodeMail('Ada', 'en', '222222', 'email_change'))->assertSeeInHtml('222222');
        (new EmailChangedMail('Ada', 'en', 'jane@example.test'))->assertSeeInText('j***@example.test');
        (new PasskeyChangedMail('Ada', 'en', 'Work laptop', false))->assertSeeInHtml('Work laptop');
    }

    public function test_mails_come_from_cetome_redact_never_laravel(): void
    {
        $this->assertSame('cetome REDact', config('mail.from.name'));

        config(['mail.default' => 'array']);
        Mail::to('ada@example.test')->send(new SignInMail('Ada', 'en', 'https://redact.test/x', '333333'));
        $sent = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        $this->assertSame('cetome REDact', $sent->getFrom()[0]->getName());
        $this->assertSame('no-reply@cetome.com', $sent->getFrom()[0]->getAddress());
    }
}
