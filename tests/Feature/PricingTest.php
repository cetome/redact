<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Guest\GuestHelpers;
use Tests\TestCase;

/**
 * /pricing: open source, web-based Free and Paid, self-hosted with cetome rules. Indexed, in the
 * sitemap, numbers from config('redact.plans'), linked from the header and from /about.
 */
class PricingTest extends TestCase
{
    use GuestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuest();
    }

    public function test_the_pricing_page_is_indexed_with_its_faq(): void
    {
        $html = $this->get(route('pricing'))->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="index, follow', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.route('pricing').'">', $html);
        $this->assertStringContainsString('<title>'.e(__('public.pricing.meta.title')).'</title>', $html);

        preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m);
        $data = json_decode($m[1] ?? '', true);
        $this->assertSame('FAQPage', $data['@graph'][1]['@type'] ?? null);
        $this->assertCount(count((array) __('public.pricing.faq')), $data['@graph'][1]['mainEntity']);

        $this->get('/sitemap.xml')->assertSee('<loc>'.route('pricing').'</loc>', false);
        $this->get('/robots.txt')->assertSee('Allow: /pricing$');
    }

    public function test_prices_and_allowances_come_from_the_configuration(): void
    {
        config([
            'redact.plans.paid.price_label' => '123 €',
            'redact.plans.paid.ai_units' => 777,
            'redact.plans.free.ai_units' => 0,
            'redact.plans.free.team_members' => 1,
        ]);

        config(['redact.plans.free.products' => 1]);
        $this->get(route('pricing'))->assertOk()
            ->assertSee('123 €')
            ->assertSee('777')
            // Owner: the paid offer is the price a month with 2 products included, +the price per extra product.
            ->assertSee(__('public.pricing.plans.paid.included', ['count' => 2]))
            ->assertSee(e(__('public.pricing.plans.paid.more', ['price' => '123 €'])), false)
            ->assertSee(e(__('public.pricing.cells.per_month', ['price' => '123 €', 'count' => 2])), false)
            ->assertSee(__('public.pricing.plans.free.no_ai'))
            ->assertSeeInOrder([
                __('public.pricing.plans.open.name'), __('public.pricing.plans.free.name'),
                __('public.pricing.plans.paid.name'), __('public.pricing.plans.self.name'),
            ]);

        // A free AI trial, when configured, replaces "No AI".
        config(['redact.plans.free.ai_units' => 50]);
        $this->get(route('pricing'))->assertSee(__('public.pricing.cells.trial', ['units' => 50]))
            ->assertDontSee(__('public.pricing.plans.free.no_ai'));
    }

    public function test_the_home_page_shows_the_same_offers_without_the_comparison(): void
    {
        config(['redact.plans.paid.price_label' => '123 €']);

        $this->get(route('home'))->assertOk()
            ->assertSee('id="pricing"', false)
            ->assertSeeInOrder([
                __('public.pricing.plans.open.name'), __('public.pricing.plans.free.name'),
                __('public.pricing.plans.paid.name'), __('public.pricing.plans.self.name'),
            ])
            ->assertSee('123 €')
            ->assertSee(__('public.pricing.plans.paid.included', ['count' => 2]))
            ->assertSee('href="'.route('pricing').'" class="c-btn c-btn-outline mt-8"', false)
            ->assertDontSee(__('public.pricing.compare_title').'</h2>', false);
    }

    public function test_the_open_source_button_waits_for_the_repository(): void
    {
        config(['redact.open_source_url' => '']);
        $this->get(route('pricing'))->assertSee(__('public.pricing.plans.open.soon'))
            ->assertSee('aria-disabled="true"', false);

        config(['redact.open_source_url' => 'https://example.test/redact']);
        $this->get(route('pricing'))->assertSee('href="https://example.test/redact"', false)
            ->assertSee(__('public.pricing.plans.open.cta'));
    }

    public function test_the_account_buttons_follow_who_is_looking(): void
    {
        // Visitor: sign-up. Self-hosted: talk to us (the sales link of the configuration).
        config(['redact.billing.contact_url' => 'https://example.test/contact']);
        $this->get(route('pricing'))->assertSee('href="'.route('register').'"', false)
            ->assertSee('href="https://example.test/contact"', false);

        // Sandbox guest: keep the work.
        $this->startSandbox();
        $this->get(route('pricing'))->assertSee('href="'.route('guest.claim').'"', false);
        auth()->guard('web')->logout();

        // Account: its dashboard.
        $user = $this->makeUser();
        Organisation::create(['name' => 'ACME', 'created_by' => $user->id])->members()->attach($user->id, ['role' => 'admin']);
        $this->signedIn($user)->get(route('pricing'))->assertSee('href="'.route('dashboard').'"', false)
            ->assertSee('Go to dashboard')->assertDontSee('public.');
        $this->get(route('about'))->assertSee('Go to dashboard')->assertSee(__('public.cards.dashboard_text'))->assertDontSee('public.');
    }

    public function test_without_stripe_the_paid_offer_is_talk_to_us(): void
    {
        // Billing off (no Stripe keys, as in phpunit.xml): no check-out, so no sign-up button on Paid.
        $html = $this->get(route('pricing'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('contact.topic', 'pricing').'" class="c-btn c-btn-primary r-plan-cta">'.__('plans.talk').'</a>', $html);
        $this->assertStringNotContainsString(__('public.pricing.plans.paid.checkout'), $html);
        $this->get('/')->assertSee('href="'.route('contact.topic', 'pricing').'"', false);

        // Billing on: the account button and the check-out line.
        config(['cashier.key' => 'pk_test_fake', 'cashier.secret' => 'sk_test_fake', 'redact.billing.price' => 'price_x']);
        $html = $this->get(route('pricing'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('register').'" class="c-btn c-btn-primary r-plan-cta"', $html);
        $this->assertStringContainsString(__('public.pricing.plans.paid.checkout'), $html);
    }

    public function test_pricing_is_linked_from_the_header_and_about(): void
    {
        $this->get(route('home'))->assertSee('href="'.route('pricing').'" class="c-nav-link"', false);
        $this->get(route('about'))->assertOk()
            ->assertSee('id="ways"', false)
            ->assertSee(__('public.about.ways.hosted.title'))
            ->assertSee(__('public.about.ways_pricing'));
        $this->assertSame(0, User::where('is_guest', true)->count());
    }
}
