<?php

namespace Tests\Feature;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Auth\Altcha;
use App\Auth\ContactAltcha;
use App\Http\Middleware\LogRequest;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Feature\Admin\AdminTestHelpers;
use Tests\TestCase;

/**
 * The contact form (/contact): the page, the defences (honeypot, ALTCHA, limits), what is stored,
 * and the two e-mails as they leave (From no-reply@cetome.com, envelope sender aligned, visitor in
 * Reply-To only, no header injection, nothing mailed to the visitor).
 */
class ContactFormTest extends TestCase
{
    use AdminTestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'array',
            'mail.from.address' => 'no-reply@cetome.com',
            'mail.from.name' => 'cetome REDact',
            'redact.contact.to' => 'info@cetome.com',
            'redact.contact.per_ip' => 10,
            'redact.contact.per_email' => 3,
        ]);
        // ALTCHA that accepts the payload "ok" only (the real check has its own test below).
        $this->app->instance(ContactAltcha::class, new class extends ContactAltcha
        {
            public function verify(mixed $payload): bool
            {
                return $payload === 'ok';
            }
        });
    }

    /** @param  array<string, mixed>  $overrides */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Maker',
            'email' => 'jane@maker.test',
            'company' => 'Maker Ltd',
            'topic' => 'pricing',
            'message' => "We make 12 smart plugs.\nWhat would it cost?",
            'website' => '',
            'altcha' => 'ok',
        ], $overrides);
    }

    private function send(array $overrides = [], array $headers = []): TestResponse
    {
        return $this->from(route('contact'))->withHeaders($headers)->post(route('contact.send'), $this->form($overrides));
    }

    /** @return Collection<int, SymfonySentMessage> */
    private function sent(): Collection
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages()->values();
    }

    private function email(SymfonySentMessage $sent): Email
    {
        $message = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);

        return $message;
    }

    /** @param  list<Address>  $addresses  @return list<string> */
    private function addresses(array $addresses): array
    {
        return array_map(fn (Address $a) => $a->getAddress(), $addresses);
    }

    public function test_the_page_is_public_indexed_and_in_both_languages(): void
    {
        $html = $this->get(route('contact'))->assertOk()
            ->assertSee(__('contact.title'))
            ->assertSee('name="website"', false)
            ->assertSee(route('contact.altcha'), false)
            ->assertSee('display="invisible"', false)
            ->assertSee(route('legal.privacy'), false)
            ->getContent();
        $this->assertStringContainsString('<meta name="robots" content="index, follow', $html);
        $this->assertStringContainsString('"@type":"ContactPage"', $html);
        foreach (ContactMessage::TOPICS as $topic) {
            $this->assertStringContainsString('value="'.$topic.'"', $html);
        }

        $this->withHeaders(['Accept-Language' => 'fr'])->get(route('contact'))->assertOk()
            ->assertSee('Parlons-en')->assertSee('Tarifs / version en ligne');

        $this->getJson(route('contact.altcha'))->assertOk()->assertJsonStructure(['parameters' => ['algorithm', 'nonce', 'salt'], 'signature']);
    }

    public function test_topic_is_preselected_from_the_address(): void
    {
        $this->get(route('contact.topic', 'self-hosted'))->assertOk()
            ->assertSee('<option value="self-hosted" selected>', false);
        $this->get('/contact/nonsense')->assertNotFound();
        $html = $this->get(route('contact'))->assertOk()->getContent();
        $this->assertStringNotContainsString('" selected>', str_replace('<option value="" disabled selected>', '', $html));
        // Older links: /contact?topic=… arrives at the topic's own address.
        $this->get('/contact?topic=self-hosted')->assertStatus(301)->assertRedirect(route('contact.topic', 'self-hosted'));
    }

    public function test_signed_in_people_are_prefilled_and_cetome_gets_their_account(): void
    {
        $data = $this->seedSampleData();
        $customer = $data['customer'];

        $this->signIn($customer)->get(route('contact'))->assertOk()
            ->assertSee('value="'.$customer->email.'"', false)
            ->assertSee('value="'.$customer->name.'"', false)
            ->assertDontSee('#'.$customer->id);

        $this->signIn($customer)->from(route('contact'))->post(route('contact.send'), $this->form(['name' => $customer->name, 'email' => $customer->email]))
            ->assertRedirect(route('contact'));

        $row = ContactMessage::sole();
        $this->assertSame($customer->id, $row->user_id);

        $staffMail = $this->email($this->sent()->first());
        $html = (string) $staffMail->getHtmlBody();
        $this->assertStringContainsString('#'.$customer->id.' · '.$customer->email, $html);
        $this->assertStringContainsString('Acme Radio · '.__('plans.name.free', [], 'en'), $html);
    }

    public function test_invalid_input_is_refused_inline_and_nothing_is_kept(): void
    {
        $this->send(['name' => '', 'email' => 'not-an-address', 'topic' => 'bananas', 'message' => ''])
            ->assertRedirect(route('contact'))
            ->assertSessionHasErrors(['name', 'email', 'topic', 'message']);
        $this->send(['name' => 'Buy now https://spam.test'])->assertSessionHasErrors(['name' => __('contact.errors.name_link')]);
        $this->send(['message' => str_repeat('x', 5001)])->assertSessionHasErrors('message');

        $this->assertSame(0, ContactMessage::count());
        $this->assertCount(0, $this->sent());

        // The errors show on the page, next to their field.
        $this->send(['email' => 'nope']);
        $this->get(route('contact'))->assertSee('id="contact-email-error"', false)->assertSee('aria-invalid="true"', false)
            ->assertDontSee(__('common.fix_errors'));
    }

    public function test_the_honeypot_is_answered_as_a_success_and_dropped(): void
    {
        $this->send(['website' => 'https://bot.test'])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('contact_sent', true)
            ->assertSessionHasNoErrors();

        $this->assertSame(0, ContactMessage::count());
        $this->assertCount(0, $this->sent());
    }

    public function test_a_missing_or_invalid_altcha_is_refused_and_the_widget_shown(): void
    {
        $this->send(['altcha' => ''])->assertSessionHasErrors(['altcha' => __('contact.errors.altcha_missing')]);
        $this->send(['altcha' => 'forged'])->assertSessionHasErrors(['altcha' => __('contact.errors.altcha_failed')]);
        $this->assertSame(0, ContactMessage::count());

        // After a refusal the widget is a visible checkbox (the fallback), with the person's text kept.
        // (A session assertion reloads the test session: send once more before reading the page.)
        $this->send(['altcha' => 'forged']);
        $this->get(route('contact'))
            ->assertSee(__('contact.errors.altcha_failed'))
            ->assertDontSee('display="invisible"></altcha-widget>', false)
            ->assertSee('What would it cost?');
    }

    public function test_contact_proofs_are_their_own_and_spent_once(): void
    {
        $contact = new ContactAltcha;
        $signUp = new Altcha;
        $solve = function (Altcha $altcha): string {
            $lib = $altcha->lib();
            $challenge = $lib->createChallenge(new CreateChallengeOptions(algorithm: new Pbkdf2, cost: 10, counter: 3, expiresAt: time() + 600));
            $solution = $lib->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: new Pbkdf2));
            $this->assertNotNull($solution);

            return (new Payload($challenge, $solution))->toBase64();
        };

        $this->assertFalse($contact->verify($solve($signUp)), 'A sign-up proof must not open the contact form.');
        $this->assertFalse($signUp->verify($solve($contact)), 'A contact proof must not open the sign-up.');
        $payload = $solve($contact);
        $this->assertTrue($contact->verify($payload));
        $this->assertFalse($contact->verify($payload), 'A proof is spent once.');
    }

    public function test_sends_are_limited_per_ip_and_per_address(): void
    {
        config(['redact.contact.per_ip' => 3]);
        $this->send(['email' => 'a@maker.test'])->assertSessionHasNoErrors();
        $this->send(['email' => 'b@maker.test', 'name' => ''])->assertSessionHasErrors('name'); // every attempt counts
        $this->send(['email' => 'c@maker.test'])->assertSessionHasNoErrors();
        $this->send(['email' => 'd@maker.test'])->assertSessionHasErrors(['form' => __('contact.errors.throttled')]);
        $this->assertSame(2, ContactMessage::count());

        // Per address, from different networks.
        config(['redact.contact.per_ip' => 100, 'redact.contact.per_email' => 2]);
        foreach (['10.0.0.1', '10.0.0.2'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->send(['email' => 'same@maker.test'])->assertSessionHasNoErrors();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.3'])->send(['email' => 'SAME@maker.test'])
            ->assertSessionHasErrors(['form' => __('contact.errors.throttled')]);
        $this->assertSame(4, ContactMessage::count());

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.4'])->send(['email' => 'same@maker.test']);
        $this->get(route('contact'))->assertSee(__('contact.errors.throttled'));
    }

    public function test_the_message_is_stored_and_mailed_from_no_reply_with_the_visitor_in_reply_to(): void
    {
        $this->send([], ['User-Agent' => 'TestBrowser/1.0'])
            ->assertRedirect(route('contact'))
            ->assertSessionHas('contact_sent', true);
        $this->get(route('contact'))->assertSee(__('contact.sent_title'))->assertDontSee('data-contact-form', false);

        $row = ContactMessage::sole();
        $this->assertSame(['Jane Maker', 'jane@maker.test', 'Maker Ltd', 'pricing', 'en', 'TestBrowser/1.0'],
            [$row->name, $row->email, $row->company, $row->topic, $row->locale, $row->user_agent]);
        $this->assertSame("We make 12 smart plugs.\nWhat would it cost?", $row->message);
        $this->assertSame(LogRequest::hashIp('127.0.0.1'), $row->ip_hash);
        $this->assertNull($row->user_id);
        $this->assertNotNull($row->sent_at);

        $sent = $this->sent();
        $this->assertCount(1, $sent);

        // 1. To cetome.
        $staff = $this->email($sent[0]);
        $this->assertSame(['info@cetome.com'], $this->addresses($staff->getTo()));
        $this->assertSame(['no-reply@cetome.com'], $this->addresses($staff->getFrom()));
        $this->assertSame('cetome REDact', $staff->getFrom()[0]->getName());
        $this->assertSame(['jane@maker.test'], $this->addresses($staff->getReplyTo()));
        $this->assertSame('no-reply@cetome.com', $staff->getReturnPath()?->getAddress());
        $this->assertSame('no-reply@cetome.com', $sent[0]->getEnvelope()->getSender()->getAddress());
        $this->assertSame(['info@cetome.com'], $this->addresses($sent[0]->getEnvelope()->getRecipients()));
        $this->assertSame('Contact: Pricing / Web-based, Jane Maker', $staff->getSubject());
        $this->assertStringContainsString('What would it cost?', (string) $staff->getTextBody());
        $this->assertStringContainsString('Maker Ltd', (string) $staff->getHtmlBody());
        $this->assertStringContainsString(route('admin.contact.show', $row), (string) $staff->getHtmlBody());
        $this->assertStringContainsString('Not signed in', (string) $staff->getTextBody());

        // 2. Nothing is mailed to the address typed in the form (owner: no acknowledgement, never
        // a public form that sends to any address).
        $this->assertCount(1, $sent);
    }

    public function test_no_header_can_be_injected_and_the_subject_is_encoded(): void
    {
        $this->send([
            'name' => "Zoé\r\nBcc: victim@else.test",
            'email' => "zoe@maker.test\r\nBcc: victim@else.test",
            'company' => "Acme\nX-Evil: 1",
        ])->assertSessionHasErrors('email');

        $this->send([
            'name' => "Zoé\r\nBcc: victim@else.test",
            'email' => 'zoe@maker.test',
            'company' => "Acme\nX-Evil: 1",
        ])->assertSessionHasNoErrors();

        $row = ContactMessage::sole();
        $this->assertSame('Zoé Bcc: victim@else.test', $row->name);
        $this->assertSame('Acme X-Evil: 1', $row->company);

        foreach ($this->sent() as $sent) {
            $raw = $sent->toString();
            $this->assertDoesNotMatchRegularExpression('/^(Bcc|X-Evil):/mi', $raw);
            $this->assertNotContains('victim@else.test', $this->addresses($sent->getEnvelope()->getRecipients()));
            $email = $this->email($sent);
            $this->assertSame([], $email->getBcc());
            foreach ($email->getHeaders()->all() as $header) {
                $this->assertStringNotContainsString("\n", $header->getBodyAsString());
            }
        }

        $staffRaw = $this->sent()[0]->toString();
        $this->assertMatchesRegularExpression('/^Subject: .*=\?utf-8\?/mi', $staffRaw, 'A non-ASCII subject is MIME-encoded.');
        $this->assertMatchesRegularExpression('/^Reply-To: .*<zoe@maker\.test>/mi', $staffRaw);
    }

    public function test_a_message_that_only_repeats_the_name_is_dropped(): void
    {
        $this->send(['name' => 'Bot', 'message' => 'Bot'])->assertSessionHas('contact_sent', true);
        $this->assertSame(0, ContactMessage::count());
    }

    public function test_staff_read_messages_in_the_console_and_nobody_else(): void
    {
        $this->send()->assertSessionHasNoErrors();
        $row = ContactMessage::sole();

        $this->get(route('admin.contact.index'))->assertRedirect(route('login'));
        $customer = $this->makeUser();
        $this->signIn($customer)->get(route('admin.contact.index'))->assertNotFound();
        $this->signIn($customer)->get(route('admin.contact.show', $row))->assertNotFound();

        $staff = $this->staff();
        $this->signIn($staff)->get(route('admin.contact.index'))->assertOk()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertSee('Jane Maker')->assertSee('Pricing / Web-based')->assertSee(__('contact.admin.sent'));
        $this->signIn($staff)->post(route('admin.contact.filter'), ['topic' => 'support']);
        $this->get(route('admin.contact.index'))->assertOk()->assertDontSee('Jane Maker');
        $this->post(route('admin.contact.filter'), ['q' => 'maker.test']);
        $this->get(route('admin.contact.index'))->assertOk()->assertSee('Jane Maker');
        $this->signIn($staff)->get(route('admin.contact.show', $row))->assertOk()
            ->assertSee('What would it cost?')->assertSee('mailto:jane@maker.test', false);
        $this->signIn($staff)->get(route('admin.dashboard'))->assertSee(route('admin.contact.index'), false);
    }

    public function test_old_messages_are_pruned(): void
    {
        config(['redact.retention.contact_messages_days' => 365]);
        $this->send()->assertSessionHasNoErrors();
        ContactMessage::query()->update(['created_at' => now()->subDays(366)]);
        $this->send(['email' => 'new@maker.test'])->assertSessionHasNoErrors();

        $this->artisan('redact:prune-tracking')->assertSuccessful();

        $this->assertSame(['new@maker.test'], ContactMessage::pluck('email')->all());
    }

    public function test_redact_links_to_its_own_contact_page(): void
    {
        $this->assertStringContainsString('href="'.route('contact').'"', $this->get(route('home'))->getContent(), 'Footer link.');
        $about = $this->get(route('about'))->assertOk()->getContent();
        $this->assertStringContainsString(e(route('contact.topic', 'expert')), $about);
        $this->assertStringContainsString(e(route('contact.topic', 'self-hosted')), $about);
        $this->assertStringContainsString(e(route('contact.topic', 'self-hosted')), $this->get(route('pricing'))->getContent());
        $this->assertStringContainsString('href="'.route('contact').'"', $this->get(route('legal.terms'))->getContent());

        foreach ([route('home'), route('about'), route('pricing'), route('legal.terms'), route('contact')] as $page) {
            $this->assertStringNotContainsString('cetome.com/contact', $this->get($page)->getContent(), $page);
        }
        $this->get('/no-such-page')->assertNotFound()->assertSee(url('/contact'), false)->assertDontSee('cetome.com/contact');

        $views = collect(glob(resource_path('views/**/*.blade.php')) ?: [])
            ->merge(glob(resource_path('views/*/*/*.blade.php')) ?: [])
            ->filter(fn (string $file) => str_contains((string) file_get_contents($file), 'cetome.com/contact'));
        $this->assertSame([], $views->values()->all());
    }

    public function test_the_sitemap_and_robots_include_the_contact_page(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>'.route('contact').'</loc>', false);
        $this->get('/robots.txt')->assertOk()->assertSee('Allow: /contact$');
    }
}
