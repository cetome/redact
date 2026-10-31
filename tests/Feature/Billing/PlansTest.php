<?php

namespace Tests\Feature\Billing;

use App\Actions\CreateAccount;
use App\Actions\CreateProduct;
use App\Ai\Availability;
use App\Ai\Feature;
use App\Ai\Quota;
use App\Billing\Plan;
use App\Billing\PlanLimitReached;
use App\Enums\ProductRole;
use App\Models\AiUsage;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsProducts;
use Tests\Feature\Guest\GuestHelpers;
use Tests\TestCase;

/**
 * Plans without Stripe (billing off, the default of the test suite): the first product is
 * free, the limits come from config redact.plans and the staff overrides, and the UI says
 * "Talk to us". Stripe itself: BillingTest.
 */
class PlansTest extends TestCase
{
    use BuildsProducts, GuestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'redact.plans.free.products' => 1,
            'redact.plans.free.team_members' => 1,
            // redact.plans.free.ai_units: the shipped default (0, no AI on the free plan).
            'redact.ai.enabled' => true,
            'prism.providers.gemini.api_key' => 'test-key',
            'redact.plans.paid.ai_units' => 500,
            'redact.plans.paid.ai_period' => 'month',
        ]);
        Mail::fake();
    }

    private function create(User $owner, string $name = 'Smart lock'): TestResponse
    {
        return $this->signIn($owner)->post(route('products.store'), [
            'organisation_id' => $owner->organisations()->first()->id,
            'name' => $name,
        ]);
    }

    public function test_every_new_account_starts_on_the_free_plan(): void
    {
        $user = app(CreateAccount::class)(['name' => 'Ada', 'email' => 'ada@example.test', 'organisation' => 'Ada Ltd']);
        $organisation = $user->organisations()->first();

        $this->assertSame('free', $organisation->plan);
        $this->assertNull($organisation->limits);
        $plan = Plan::fresh($organisation);
        $this->assertSame(Plan::SLOT_FREE, $plan->nextSlot());
        $this->assertSame(1, $plan->productLimit());
        $this->assertFalse($plan->support());
        // No AI on the free plan.
        $this->assertSame(['units' => 0, 'period' => 'once', 'used' => 0, 'left' => 0, 'trial' => true, 'renews' => null, 'own' => false], $plan->aiAllowance());
        $this->assertFalse($plan->aiIncluded());
        $this->assertSame(__('plans.name.free'), $plan->label());
    }

    public function test_the_first_product_is_free_and_a_second_one_is_refused_with_talk_to_us(): void
    {
        $owner = $this->owner();
        $this->create($owner)->assertRedirect();
        $product = Product::query()->sole();
        $this->assertSame(Plan::SLOT_FREE, $product->plan_slot);

        $message = 'Your free plan includes 1 product. Talk to us to add more.';
        $this->create($owner, 'Second')->assertRedirect(route('products.create'))->assertSessionHas('error', $message);
        $this->assertSame(1, Product::query()->count());

        // New product shows the limit and the way out, not the form.
        $this->get(route('products.create'))->assertOk()
            ->assertSee($message)->assertSee(__('plans.talk'))->assertSee(Plan::contactUrl())
            ->assertDontSee(__('scoping.what'));

        // The dashboard: "Upgrade" instead of New product, and the plan line.
        $this->get(route('dashboard'))->assertOk()
            ->assertSee(__('plans.upgrade'))->assertDontSee(__('products.new'))
            ->assertSee(__('plans.name.free'))->assertSee('1 of 1 product')->assertSee(__('plans.ai.none'))
            ->assertSee(__('plans.line.talk'));

        // The account page shows the same plan line.
        $this->get(route('account.show'))->assertOk()->assertSee(__('plans.name.free'))->assertSee('1 of 1 product');
    }

    public function test_the_shared_creation_path_enforces_the_limit_for_every_caller(): void
    {
        $owner = $this->owner();
        $organisation = $owner->organisations()->first();
        $create = app(CreateProduct::class);

        $create($owner, ['organisation_id' => $organisation->id, 'name' => 'One']);

        try {
            $create($owner, ['organisation_id' => $organisation->id, 'name' => 'Two']);
            $this->fail('The second product should be refused.');
        } catch (PlanLimitReached $e) {
            $this->assertSame('Your free plan includes 1 product. Talk to us to add more.', $e->getMessage());
            $this->assertSame(route('products.create'), $e->render()->getTargetUrl());
        }
        $this->assertSame(1, $organisation->products()->count());
    }

    public function test_a_deleted_free_product_frees_the_slot_and_versions_are_unlimited(): void
    {
        $owner = $this->owner();
        $this->create($owner);
        $product = Product::query()->sole();

        $this->post(route('versions.store', $product), ['label' => '2.0'])->assertRedirect();
        $this->assertSame(2, $product->versions()->count());

        $this->delete(route('products.destroy', $product))->assertRedirect(route('dashboard'));
        $this->create($owner, 'Next')->assertRedirect();
        $this->assertSame(1, Product::query()->count());
    }

    public function test_the_free_product_team_is_the_owner_and_one_member_suggested_as_reviewer(): void
    {
        $owner = $this->owner();
        $this->create($owner);
        $product = Product::query()->sole();

        $this->get(route('team.index', $product))->assertOk()
            ->assertSee('Your free plan includes one team member: invite your reviewer.')
            ->assertSee('value="reviewer" checked', false);

        $this->post(route('team.invite', $product), ['email' => 'rev@example.test', 'role' => 'reviewer'])
            ->assertSessionHas('status', __('team.invite_sent'));
        $this->assertSame(1, $product->invitations()->count());

        // Re-inviting the same person never takes a second seat.
        $this->post(route('team.invite', $product), ['email' => 'REV@example.test', 'role' => 'reviewer'])->assertSessionMissing('error');

        $this->post(route('team.invite', $product), ['email' => 'second@example.test', 'role' => 'contributor'])
            ->assertSessionHas('error', 'Your free product includes its owner and 1 team member. Talk to us to add more.');
        $this->assertSame(1, $product->invitations()->count());

        // Full: the form gives way to the explanation.
        $this->get(route('team.index', $product))->assertOk()
            ->assertSee('Your free product includes its owner and 1 team member. Talk to us to add more.')
            ->assertDontSee(__('team.send'));

        // An accepted member counts the same way.
        $product->invitations()->delete();
        $this->member($product, ProductRole::Contributor);
        $this->post(route('team.invite', $product), ['email' => 'third@example.test', 'role' => 'reviewer'])->assertSessionHas('error');
    }

    public function test_overrides_grant_products_team_ai_and_support(): void
    {
        $owner = $this->owner();
        $organisation = $owner->organisations()->first();
        $organisation->forceFill(['limits' => ['extra_products' => 1, 'team_members' => 3, 'ai_units' => 70, 'ai_period' => 'month', 'support' => true]])->save();

        $this->create($owner, 'One')->assertRedirect();
        $this->create($owner, 'Two')->assertRedirect();
        $this->create($owner, 'Three')->assertSessionHas('error');
        $this->assertSame(['free', 'granted'], Product::query()->orderBy('created_at')->orderBy('id')->pluck('plan_slot')->all());

        $plan = Plan::fresh($organisation);
        $free = Product::query()->where('plan_slot', 'free')->sole();
        $granted = Product::query()->where('plan_slot', 'granted')->sole();
        $this->assertSame(3, $plan->teamLimit($free));
        $this->assertNull($plan->teamLimit($granted));
        $this->assertSame(70, $plan->aiAllowance()['units']);
        $this->assertSame('month', $plan->aiAllowance()['period']);
        $this->assertTrue($plan->support());
        $this->assertSame(__('plans.name.paid'), $plan->label());

        // Staff take the granted product back: it becomes read-only, nothing is deleted.
        $organisation->forceFill(['limits' => null])->save();
        $this->assertTrue(Plan::fresh($organisation)->isLocked($granted));
        $this->assertFalse(Plan::fresh($organisation)->isLocked($free));
        $this->assertSame(2, Product::query()->count());
    }

    public function test_enterprise_has_no_product_or_team_limit(): void
    {
        $owner = $this->owner();
        $owner->organisations()->first()->forceFill(['plan' => 'enterprise'])->save();

        foreach (['A', 'B', 'C'] as $name) {
            $this->create($owner, $name)->assertRedirect();
        }
        $this->assertSame(3, Product::query()->count());
        $plan = Plan::fresh($owner->organisations()->first());
        $this->assertNull($plan->productLimit());
        $this->assertNull($plan->teamLimit(Product::query()->first()));
        $this->assertSame(5000, $plan->aiAllowance()['units']);
    }

    public function test_organisations_above_the_limits_are_grandfathered(): void
    {
        $owner = $this->owner();
        // Three products from before plans existed (plan_slot null), with a large team.
        $versions = [$this->product($owner), $this->product($owner), $this->product($owner)];
        $product = $versions[0]->product;
        $this->member($product, ProductRole::Contributor);
        $this->member($product, ProductRole::Reviewer);

        $organisation = $owner->organisations()->first();
        $plan = Plan::fresh($organisation);
        $this->assertSame([], $plan->locked);
        $this->assertSame(3, $plan->productLimit());
        $this->assertNull($plan->teamLimit($product));

        // Everything stays editable and visible; only new creations are refused.
        foreach ($versions as $version) {
            $this->assertTrue(ProductAccess::for($owner, $version->product)->canEdit($version));
        }
        $this->create($owner, 'Fourth')->assertSessionHas('error');
        $this->assertSame(3, Product::query()->count());
        $this->signIn($owner)->get(route('dashboard'))->assertOk()->assertSee('3 of 3 products');
        $this->post(route('team.invite', $product), ['email' => 'more@example.test', 'role' => 'reviewer'])->assertSessionMissing('error');
    }

    public function test_the_free_plan_has_no_ai_and_says_it_comes_with_a_paid_plan(): void
    {
        $owner = $this->owner();
        $this->create($owner);
        $version = Product::query()->sole()->versions()->firstOrFail();
        $organisation = $owner->organisations()->first();
        $message = 'AI comes with a paid plan: it is turned on as soon as your organisation adds a paid product.';

        $this->assertSame($message, Plan::fresh($organisation)->aiRefusal());
        $this->assertSame($message, Availability::reason($owner, $version));
        $this->assertSame($message, Availability::reasonForOrganisation($owner, $organisation));
        // Billing off: the organisation admin is pointed to cetome; other people get no link.
        $this->assertSame(Plan::contactUrl(), Availability::upgradeUrl($owner, $version));
        $this->assertNull(Availability::upgradeUrl($this->member($version->product, ProductRole::Contributor), $version));

        // The pages say why, with the way to turn it on, never "unavailable right now".
        $this->get(route('versions.scoping', $version))->assertOk()
            ->assertSee(__('plans.ai.short'))->assertSee(Plan::contactUrl())->assertDontSee(__('ai.unavailable.not_configured'));
        $this->get(route('versions.ai', $version))->assertOk()->assertSee($message)->assertSee(__('plans.ai.not_included'));

        // And the server refuses the call before any AI is reached.
        $this->expectExceptionMessage($message);
        app(Quota::class)->reserve($owner, $version, Feature::ImproveText, 1, 'gemini', 'test-model');
    }

    public function test_a_granted_product_turns_the_ai_on_and_grandfathered_organisations_stay_free(): void
    {
        $owner = $this->owner();
        $this->product($owner);
        $this->product($owner);
        $organisation = $owner->organisations()->first();
        // Grandfathered products (from before plans): free for the AI unless staff grant a product.
        $this->assertFalse(Plan::fresh($organisation)->aiIncluded());

        $organisation->forceFill(['limits' => ['extra_products' => 1]])->save();
        $plan = Plan::fresh($organisation);
        $this->assertTrue($plan->aiIncluded());
        $this->assertSame(500, $plan->aiAllowance()['units']);
        $this->assertSame('month', $plan->aiAllowance()['period']);
        $this->assertNull(Availability::upgradeUrl($owner, $organisation));
    }

    public function test_a_configured_ai_trial_is_a_one_time_allowance_of_the_organisation(): void
    {
        config(['redact.plans.free.ai_units' => 50, 'redact.plans.free.ai_period' => 'once']);
        $owner = $this->owner();
        $organisation = $owner->organisations()->first();
        $version = $this->product($owner);
        // Usage a long time ago still counts: the trial is once, not per month.
        $this->usage($owner, $organisation, 45, now()->subMonths(3));

        $plan = Plan::fresh($organisation);
        $this->assertSame(5, $plan->aiAllowance()['left']);
        $this->assertNull($plan->aiRefusal(5));
        $this->assertSame('Your organisation has used its 50 AI trial units. A paid product comes with a monthly AI allowance.', $plan->aiRefusal(6));

        $this->expectExceptionMessage('Your organisation has used its 50 AI trial units.');
        app(Quota::class)->reserve($owner, $version, Feature::ImproveText, 6, 'gemini', 'test-model');
    }

    public function test_paid_products_give_a_monthly_allowance_per_product(): void
    {
        $owner = $this->owner();
        $organisation = $owner->organisations()->first();
        $organisation->forceFill(['limits' => ['extra_products' => 2]])->save();
        $this->usage($owner, $organisation, 900, now()->startOfMonth()->subMonthNoOverflow()->addDay());
        $this->usage($owner, $organisation, 990, now()->startOfMonth()->addMinute());

        $ai = Plan::fresh($organisation)->aiAllowance();
        $this->assertSame(1000, $ai['units']);
        $this->assertSame(990, $ai['used']);
        $this->assertSame(10, $ai['left']);
        $this->assertFalse($ai['trial']);
        $this->assertEquals(now()->startOfMonth()->addMonth(), $ai['renews']);
        $this->assertStringContainsString('It renews on', (string) Plan::fresh($organisation)->aiRefusal(11));
    }

    public function test_staff_set_the_plan_in_the_console_and_it_is_logged(): void
    {
        $owner = $this->owner();
        $organisation = $owner->organisations()->first();
        $staff = User::factory()->create();
        $staff->assignRole(Role::findOrCreate('staff', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Customers never reach the console.
        $this->signIn($owner)->get(route('admin.organisations.index'))->assertNotFound();

        $this->signIn($staff, 'passkey');
        $this->get(route('admin.organisations.index'))->assertOk()->assertSee('ACME');
        $this->get(route('admin.organisations.show', $organisation))->assertOk()->assertSee(__('plans.admin.overrides'));

        $this->put(route('admin.organisations.plan', $organisation), [
            'plan' => 'enterprise', 'extra_products' => '2', 'team_members' => '', 'ai_units' => '1200', 'ai_period' => 'month', 'support' => 'yes',
        ])->assertRedirect(route('admin.organisations.show', $organisation))->assertSessionHas('status', __('plans.admin.saved'));

        $organisation->refresh();
        $this->assertSame('enterprise', $organisation->plan);
        $this->assertSame(['extra_products' => 2, 'ai_units' => 1200, 'ai_period' => 'month', 'support' => true], $organisation->limits);

        $log = Activity::query()->where('log_name', 'admin')->where('description', 'plan changed')->sole();
        $this->assertEquals($staff->id, $log->causer_id);
        $this->assertSame('free', $log->properties['old']['plan']);
        $this->assertSame('enterprise', $log->properties['attributes']['plan']);

        $this->put(route('admin.organisations.plan', $organisation), ['plan' => 'gold'])->assertSessionHasErrors('plan');
    }

    public function test_guests_keep_the_sandbox_rules(): void
    {
        $this->setUpGuest();
        $guest = $this->startSandbox();
        $this->guestProduct($guest);

        $this->assertSame(Plan::SLOT_FREE, Product::query()->sole()->plan_slot);
        // The second product: the sandbox message, not the plan's.
        $this->post(route('products.store'), ['organisation_id' => $guest->organisations()->first()->id, 'name' => 'Two'])
            ->assertRedirect(route('dashboard'))->assertSessionHas('error', __('guest.product_limit'));
        $this->get(route('dashboard'))->assertOk()->assertDontSee(__('plans.line.talk'));
    }

    private function usage(User $user, Organisation $organisation, int $units, \DateTimeInterface $at): void
    {
        $usage = AiUsage::create([
            'user_id' => $user->id, 'organisation_id' => $organisation->id, 'feature' => Feature::ImproveText->value,
            'units' => $units, 'provider' => 'gemini', 'model' => 'test', 'outcome' => 'ok',
        ]);
        $usage->forceFill(['created_at' => $at])->save();
    }

    public function test_a_self_hosted_install_has_no_product_or_team_limit(): void
    {
        config(['redact.self_hosted' => true]);
        $owner = $this->makeUser();
        $organisation = Organisation::create(['name' => 'Self', 'created_by' => $owner->id]);
        $organisation->members()->attach($owner->id, ['role' => 'admin']);

        foreach (range(1, 3) as $n) {
            app(CreateProduct::class)($owner, ['organisation_id' => $organisation->id, 'name' => "Product $n"]);
        }
        $plan = Plan::fresh($organisation);
        $this->assertSame(3, $plan->productCount());
        $this->assertNull($plan->productLimit());
        $this->assertFalse($plan->productLimitReached());
        $this->assertSame([], $plan->locked);
        $this->assertNull($plan->teamLimit(Product::query()->firstOrFail()));
        $this->assertSame(__('plans.name.self_hosted'), $plan->label());

        // The hosted service before Stripe (REDACT_SELF_HOSTED=false): the free limits.
        config(['redact.self_hosted' => false]);
        $this->assertSame(1, Plan::fresh($organisation)->productLimit());
    }
}
