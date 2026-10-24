<?php

namespace Tests\Feature\Guest;

use App\Assessment\Assessment;
use App\Assessment\ScopingForm;
use App\Http\Controllers\PublicController;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Home: the hero ("Try REDact" → #start), the RED scoping tool at #start whose Start opens a
 * sandbox for a visitor (invisible ALTCHA, checked on the server) or creates the product for a
 * signed-in person, and "What happens next" with a picture of each step (tour).
 */
class HomeStartTest extends TestCase
{
    use GuestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuest();
    }

    /** @return list<string> two boolean scoping question keys of the fixture pack */
    private function twoQuestions(): array
    {
        $keys = array_keys(array_filter(
            app(ScopingForm::class)->questions(ScopingForm::SCOPING),
            fn (array $q) => ($q['type'] ?? 'boolean') === 'boolean',
        ));
        $this->assertGreaterThanOrEqual(2, count($keys));

        return array_slice($keys, 0, 2);
    }

    /** @return array<string, string> answers[] as the cards post them (hidden "0", ticked "1") */
    private function answers(array $ticked): array
    {
        $answers = [];
        foreach (array_keys(app(ScopingForm::class)->questions(ScopingForm::SCOPING)) as $key) {
            $answers[$key] = in_array($key, $ticked, true) ? '1' : '0';
        }

        return $answers;
    }

    public function test_the_home_page_has_the_hero_the_tool_and_the_tour(): void
    {
        [$first] = $this->twoQuestions();

        $html = $this->get('/')->assertOk()
            // Hero: two buttons, no other link. "Try REDact" opens the sandbox dashboard directly.
            ->assertSee('href="'.route('guest.start').'" class="c-btn c-btn-on-dark c-btn-lg"', false)
            ->assertSee(__('public.try_button'))
            ->assertDontSee(__('public.how_title'))
            // The tool at #start, with the invisible proof.
            ->assertSee('id="start"', false)
            ->assertSee('action="'.route('home.start').'"', false)
            ->assertSee('x-data="scopingLive"', false)
            ->assertSee('name="answers['.$first.']"', false)
            ->assertSeeInOrder([__('public.tool.step_name'), __('public.tool.step_tick'), __('scoping.in_scope_live'), __('public.tool.start')])
            ->assertSee('<altcha-widget challenge="'.route('guest.altcha').'" name="altcha" auto="onload" display="invisible"', false)
            // What happens next: the /about slider, the real steps in order, one screen per step.
            ->assertSeeInOrder(array_map(fn ($s) => Lang::has('workflow.steps.'.$s) ? __('workflow.steps.'.$s) : Str::headline($s), PublicController::TOUR))
            ->assertSee('data-slider', false)
            ->assertSee('role="tablist"', false)
            ->assertDontSee('data-tour-open', false)
            ->getContent();

        $this->assertSame([...Assessment::ORDER, 'reports'], PublicController::TOUR);
        // Every step has its pictures (scripts/tour-shots.py), 1x and 2x, light and dark. They are screens of
        // the private content pack: the open-source snapshot leaves them out and the slider shows none.
        if (! is_dir(public_path('images/tour'))) {
            return;
        }
        foreach (PublicController::TOUR as $step) {
            foreach (['light', 'dark'] as $theme) {
                $this->assertStringContainsString('images/tour/'.$step.'-'.$theme.'@2x.webp 2x', $html);
                foreach (['' => 150, '@2x' => 300] as $suffix => $kb) {
                    $file = public_path('images/tour/'.$step.'-'.$theme.$suffix.'.webp');
                    $this->assertFileExists($file);
                    $this->assertLessThanOrEqual($kb * 1024, filesize($file));
                }
            }
        }
    }

    public function test_the_public_header_for_a_visitor_a_sandbox_guest_and_an_account(): void
    {
        $dashboard = '>'.__('nav.dashboard').'</a>';

        // Visitor: Dashboard opens a sandbox from the page (data-sandbox-open; /try without script), Sign in, Create an account.
        $this->get('/about')->assertSee('href="'.route('guest.start').'" class="c-nav-link"', false)->assertSee($dashboard, false)
            ->assertSee('href="'.route('login').'"', false)->assertSee('href="'.route('register').'"', false);

        // Sandbox guest: its dashboard, and "Create an account" keeps the work (claim).
        $this->startSandbox();
        $this->get('/about')->assertSee('href="'.route('dashboard').'" class="c-nav-link"', false)
            ->assertSee('href="'.route('login').'"', false)->assertSee('href="'.route('guest.claim').'"', false);
        auth()->guard('web')->logout();

        // Account: Dashboard only.
        $this->signedIn($this->makeUser())->get('/about')->assertSee('href="'.route('dashboard').'" class="c-nav-link"', false)
            ->assertDontSee('href="'.route('login').'"', false);
    }

    public function test_the_about_page_shows_every_step_for_browsers_without_script(): void
    {
        $response = $this->get(route('about'))->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('aria-orientation="vertical"', false)
            ->assertSee(__('public.tour.texts.risks'));
        $pictures = is_dir(public_path('images/tour')); // not in the open-source snapshot (private pack screens)
        foreach (PublicController::TOUR as $step) {
            $response->assertSee('id="'.$step.'"', false)->assertSee('aria-controls="'.$step.'"', false);
            if ($pictures) {
                $response->assertSee('images/tour/'.$step.'-light@2x.webp 2x', false);
            }
        }
        // Only the first step's pictures load at once; the others when their step is shown.
        if ($pictures) {
            $this->assertSame(2, substr_count($response->getContent(), 'loading="eager"'));
        }

        // Full screen: one button per picture (labelled with its step), one native dialog.
        $response->assertSee('aria-label="'.e(__('public.tour.zoom.open', ['step' => __('workflow.steps.risks')])).'"', false)
            ->assertSee('<dialog class="r-zoom" aria-labelledby="slider-zoom-title" data-slider-zoom>', false)
            ->assertSee('aria-label="'.e(__('public.tour.zoom.previous')).'"', false)
            ->assertSee('aria-label="'.e(__('public.tour.zoom.next')).'"', false)
            ->assertSee('data-zoom-close aria-label="'.e(__('common.close')).'"', false);
        $this->assertSame(count(PublicController::TOUR), substr_count($response->getContent(), 'data-zoom-open'));
    }

    public function test_a_visitor_starts_a_sandbox_with_the_product_and_its_answers(): void
    {
        [$first, $second] = $this->twoQuestions();

        $response = $this->post(route('home.start'), [
            'name' => 'Smart lock X1',
            'answers' => $this->answers([$first, $second]),
            'altcha' => 'ok',
        ]);

        $guest = User::query()->where('is_guest', true)->firstOrFail();
        $this->assertAuthenticatedAs($guest);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'guest', 'description' => 'sandbox started']);

        $product = Product::query()->where('owner_id', $guest->id)->firstOrFail();
        $this->assertSame('Smart lock X1', $product->name);
        $this->assertSame($guest->organisations()->firstOrFail()->id, $product->organisation_id);
        $version = $product->versions()->firstOrFail();
        $this->assertSame('1.0', $version->label);

        $ticked = $version->scopingAnswers()->pluck('value', 'question')->all();
        $this->assertTrue($ticked[$first] ?? null);
        $this->assertTrue($ticked[$second] ?? null);

        // One message on the first screen, once: what was created and what to do next.
        $response->assertRedirect(route('versions.edit', $version))->assertSessionHas('next')->assertSessionMissing('status');
        $next = (string) session('next');
        $this->get(route('versions.edit', $version))->assertOk()->assertSee(e($next), false);
        $this->get(route('versions.edit', $version))->assertOk()->assertDontSee(e($next), false);
    }

    public function test_a_product_started_without_a_name_or_ticks_gets_a_default_name(): void
    {
        $this->post(route('home.start'), ['altcha' => 'ok'])->assertRedirect();

        $this->assertSame(__('products.default_name', ['n' => 1]), Product::query()->sole()->name);
    }

    public function test_a_visitor_without_a_valid_proof_is_refused_and_keeps_the_answers(): void
    {
        [$first] = $this->twoQuestions();
        $input = ['name' => 'Smart lock X1', 'answers' => $this->answers([$first])];

        // As a browser follows the redirect (asserting on the session first would consume the flash).
        $this->from('/')->post(route('home.start'), $input)->assertRedirect('/');
        $this->get('/')->assertSee(__('auth.register.altcha_required'));

        $this->from('/')->post(route('home.start'), $input + ['altcha' => 'forged'])->assertRedirect('/');
        $html = $this->get('/')->assertSee(__('auth.register.altcha_failed'))
            ->assertSee('value="Smart lock X1"', false)->getContent();
        $this->assertMatchesRegularExpression('/name="answers\['.preg_quote($first, '/').'\]" value="1"\s+checked/', $html);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Product::count());
        $this->assertGuest();
    }

    public function test_the_home_start_shares_the_sandbox_limit_per_network(): void
    {
        config(['redact.guest.per_ip_per_day' => 1]);
        $this->startSandbox();
        auth()->guard('web')->logout();

        $this->post(route('home.start'), ['name' => 'Second', 'altcha' => 'ok'])
            ->assertSessionHasErrors(['altcha' => __('guest.start.too_many')]);
        $this->assertSame(1, User::where('is_guest', true)->count());
        $this->assertSame(0, Product::count());
    }

    public function test_an_account_in_one_organisation_gets_the_product_directly(): void
    {
        [$first] = $this->twoQuestions();
        $user = $this->makeUser();
        $organisation = Organisation::create(['name' => 'ACME', 'created_by' => $user->id]);
        $organisation->members()->attach($user->id, ['role' => 'admin']);

        $this->signedIn($user)->get('/')->assertOk()
            ->assertSee(__('public.tool.start'))
            ->assertDontSee('altcha-widget', false);

        $response = $this->post(route('home.start'), ['name' => 'Gateway', 'answers' => $this->answers([$first])]);

        $product = Product::query()->where('owner_id', $user->id)->firstOrFail();
        $this->assertSame($organisation->id, $product->organisation_id);
        $response->assertRedirect(route('versions.edit', $product->versions()->firstOrFail()))->assertSessionHas('next')->assertSessionMissing('status');
        $this->assertSame(0, User::where('is_guest', true)->count());
    }

    public function test_an_account_in_several_organisations_chooses_on_new_product(): void
    {
        [$first] = $this->twoQuestions();
        $user = $this->makeUser();
        foreach (['ACME', 'Globex'] as $name) {
            Organisation::create(['name' => $name, 'created_by' => $user->id])->members()->attach($user->id, ['role' => 'admin']);
        }

        $this->signedIn($user)->post(route('home.start'), ['name' => 'Gateway', 'answers' => $this->answers([$first])])
            ->assertRedirect(route('products.create'))
            ->assertSessionHasInput('name', 'Gateway');
        $this->assertSame(0, Product::count());
    }

    public function test_a_guest_who_already_has_a_product_is_told_about_the_limit(): void
    {
        $guest = $this->startSandbox();
        $this->guestProduct($guest);

        $this->post(route('home.start'), ['name' => 'Another'])
            ->assertRedirect(route('dashboard'))->assertSessionHas('error', __('guest.product_limit'));
        $this->assertSame(1, Product::count());
    }

    public function test_with_the_sandbox_off_a_visitor_is_sent_to_sign_up(): void
    {
        config(['redact.guest.enabled' => false]);

        $this->get('/')->assertOk()
            ->assertSee(__('public.tool.signup'))
            ->assertDontSee('altcha-widget', false);
        $this->post(route('home.start'), ['name' => 'X', 'altcha' => 'ok'])->assertNotFound();
    }

    public function test_try_starts_by_itself_and_keeps_its_fallback_button(): void
    {
        $this->get('/try')->assertOk()
            ->assertSee('data-sandbox-start', false)
            ->assertSee('data-auto-submit', false)
            ->assertSee('display="invisible"', false)
            ->assertSee(__('guest.start.submit'))
            ->assertSee(__('guest.start.no_ai'));

        // After a refusal the page does not send itself again (no loop), the button remains.
        $this->from('/try')->post(route('guest.create'), ['altcha' => 'forged'])->assertRedirect('/try');
        $this->get('/try')->assertOk()
            ->assertSee(__('auth.register.altcha_failed'))
            ->assertDontSee('data-auto-submit', false)
            ->assertSee(__('guest.start.submit'));

        // Without script (no proof at all): back to /try, which says what is missing.
        $this->from('/')->post(route('guest.create'), [])->assertRedirect(route('guest.start'));

        // The plain form post with a proof still opens the sandbox.
        $this->startSandbox();
    }
}
