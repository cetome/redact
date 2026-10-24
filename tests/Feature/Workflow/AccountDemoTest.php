<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\AssessmentCases;
use App\Billing\Plan;
use App\Declaration\DeclarationData;
use App\Guest\DemoProduct;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsProducts;
use Tests\Concerns\UsesPackCopy;
use Tests\Concerns\WithFixtureDemo;
use Tests\Feature\Guest\GuestHelpers;
use Tests\TestCase;

/**
 * "Load the demo" for accounts (POST /demo, DemoController::account) on a copy of the fixture pack
 * with a tiny invented demo: the read-only demo product (products.is_demo, plan slot "demo"), one per
 * organisation, never counted by the plan, its declaration naming the demo's fictitious manufacturer.
 * The cetome demo itself is checked with the real pack (ContentPackDemoTest).
 */
class AccountDemoTest extends TestCase
{
    use BuildsProducts, GuestHelpers, RefreshDatabase, UsesPackCopy, WithFixtureDemo {
        BuildsProducts::signIn insteadof GuestHelpers;
    }

    private User $user;

    private Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuest();
        Storage::fake('local');
        $this->user = $this->owner();
        $this->organisation = $this->user->organisations()->sole();
        $this->organisation->forceFill(['legal_name' => 'Real Devices SAS', 'address' => '2 avenue du Réel', 'country' => 'France'])->save();
    }

    private function loadDemo(): Product
    {
        $this->signIn($this->user)->post(route('demo.load'))->assertRedirect();

        return Product::query()->where('organisation_id', $this->organisation->id)->where('is_demo', true)->sole();
    }

    public function test_without_demo_data_the_action_is_hidden_and_refused(): void
    {
        $this->signIn($this->user)->get(route('dashboard'))->assertOk()->assertDontSee(route('demo.load'), false)->assertDontSee(__('products.demo.load'));
        $this->post(route('demo.load'))->assertNotFound();
        $this->assertSame(0, Product::count());
    }

    public function test_a_free_account_loads_the_demo_without_using_its_free_product(): void
    {
        $this->useFixtureDemo();
        $this->assertSame(url('/demo'), route('demo.load'));

        $html = $this->signIn($this->user)->get(route('dashboard'))->assertOk()->assertSee(__('products.demo.load'))->getContent();
        $this->assertStringContainsString('action="'.route('demo.load').'"', $html);
        $this->assertDoesNotMatchRegularExpression('/(href|action)="[^"]*\?[^"]*"/', $html);

        $response = $this->post(route('demo.load'));
        $product = Product::query()->where('is_demo', true)->sole();
        $version = $product->versions()->sole();
        $response->assertRedirect(route('versions.show', $version))->assertSessionMissing('next');
        $this->assertSame(Plan::SLOT_DEMO, $product->plan_slot);
        $this->assertSame($this->organisation->id, $product->organisation_id);
        $this->assertSame($this->user->id, $product->owner_id);
        // The organisation's legal details are never touched.
        $this->assertSame('Real Devices SAS', $this->organisation->refresh()->legal_name);

        // The plan ignores it: the free product is still to take.
        $plan = Plan::fresh($this->organisation);
        $this->assertSame(0, $plan->productCount());
        $this->assertSame(Plan::SLOT_FREE, $plan->nextSlot());
        $this->assertFalse($plan->isLocked($product));
        $this->post(route('products.store'), ['organisation_id' => $this->organisation->id, 'name' => 'My real product', 'version_label' => '1.0'])->assertRedirect();
        $this->assertSame(Plan::SLOT_FREE, Product::query()->where('name', 'My real product')->sole()->plan_slot);
        $this->assertTrue(Plan::fresh($this->organisation)->productLimitReached());

        // Now the dashboard opens it; History says so.
        $this->get(route('dashboard'))->assertOk()->assertSee(__('products.demo.open'))->assertSee($product->homeUrl(), false)
            ->assertDontSee('action="'.route('demo.load').'"', false);
        $this->assertSame(1, Activity::query()->where('description', 'demo loaded')->where('subject_id', $version->id)->count());
        $this->get(route('products.history', $product))->assertOk()->assertSee(__('history.events.demo_loaded'));
    }

    public function test_one_demo_per_organisation(): void
    {
        $this->useFixtureDemo();
        $product = $this->loadDemo();

        $this->post(route('demo.load'))->assertRedirect($product->homeUrl())->assertSessionHas('status', __('products.demo.exists'));
        $this->assertSame(1, Product::query()->where('is_demo', true)->count());
    }

    public function test_the_demo_is_read_only_and_shows_no_edit_action(): void
    {
        $this->useFixtureDemo();
        $product = $this->loadDemo();
        $version = $product->versions()->sole();

        $this->get(route('versions.show', $version))->assertOk()->assertSee(__('products.demo.read_only'))
            ->assertDontSee(route('team.reviewer', $product), false);
        $this->get(route('versions.inventory', [$version, 'assets']))->assertOk()
            ->assertDontSee(route('versions.inventory.new', [$version, 'assets']), false)
            ->assertDontSee('action="'.route('versions.import.store', $version).'"', false);
        $this->get(route('versions.edit', $version))->assertOk()->assertDontSee('data-shortcut="mod+s"', false);
        $this->get(route('team.index', $product))->assertOk()->assertDontSee('action="'.route('team.invite', $product).'"', false);
        $this->get(route('versions.risks', $version))->assertOk()->assertDontSee('action="'.route('versions.risks.store', $version).'"', false);

        $this->put(route('versions.update', $version), ['description' => 'Changed'])->assertNotFound();
        $this->post(route('versions.risks.store', $version), ['threat' => 'x'])->assertNotFound();
        $this->post(route('team.invite', $product), ['email' => 'x@example.test', 'role' => 'contributor'])->assertNotFound();
        $this->post(route('versions.store', $product), ['label' => '2.0'])->assertNotFound();
        $this->put(route('products.update', $product), ['name' => 'Renamed'])->assertNotFound();
        $this->assertSame('Sample bulb (demo)', $product->refresh()->name);

        // Downloads stay, marked DEMO.
        $this->get(route('reports.download', ['version' => $version, 'kind' => 'assessment', 'format' => 'docx']))->assertOk();
        $this->get(route('versions.declaration.download', [$version, 'docx']))->assertOk();
    }

    public function test_the_demo_provides_every_assessment_unit_read_only(): void
    {
        $this->useFixtureDemo();
        $version = $this->loadDemo()->versions()->sole();
        $assessment = Assessment::for($version);

        // Every unit of the fixture criteria provided, PASS, by the person who loaded it: the step is done.
        $units = AssessmentCases::for($assessment)->progress();
        $this->assertSame(['total' => 5, 'recorded' => 5, 'inconclusive' => 0, 'fail' => 0], $units);
        $this->assertTrue(collect($assessment->steps())->firstWhere('key', 'units')['done']);
        $this->assertSame([$this->user->id], $version->caseResults()->pluck('recorded_by')->unique()->values()->all());

        // Read-only: shown, never recorded; the demo cannot be submitted nor self-reviewed.
        $this->get(route('versions.units', $version))->assertOk()
            ->assertSee('Sample evidence: checked on the sample product, as documented.')
            ->assertDontSee('data-case-form', false);
        $this->post(route('versions.units.record', $version), ['standard' => 'demo-1', 'requirement' => 'ACC-1', 'case' => 'conceptual',
            'verdict' => 'FAIL', 'evidence' => 'Trying to change the demo result.'])->assertNotFound();
        $this->post(route('versions.submit', $version))->assertNotFound();
    }

    public function test_the_declaration_names_the_fictitious_manufacturer_and_is_marked_demo(): void
    {
        $this->useFixtureDemo();
        $version = $this->loadDemo()->versions()->sole();

        $data = DeclarationData::for($version);
        $this->assertSame('Emotec', $data->manufacturerName());
        $this->assertStringContainsString('69000 Lyon', $data->manufacturerAddress());
        $this->assertTrue($data->hasManufacturer());
        $this->assertSame('demo', $data->openItems()[0]['key']);
        $this->assertSame(__('declaration.demo.watermark'), $data->watermark());

        $this->get(route('versions.declaration', $version))->assertOk()->assertSee('Emotec')->assertSee('Camille Martin')
            ->assertDontSee('Real Devices SAS')->assertSee(__('declaration.demo.watermark'))
            ->assertDontSee(route('organisations.legal.update', $this->organisation), false);
    }

    public function test_the_owner_or_an_organisation_admin_removes_it_and_it_can_be_loaded_again(): void
    {
        $this->useFixtureDemo();
        $product = $this->loadDemo();

        $this->get(route('products.edit', $product))->assertOk()->assertSee(__('products.demo.remove'));
        $this->delete(route('products.destroy', $product))->assertRedirect(route('dashboard'))->assertSessionHas('status', __('products.demo.removed'));
        $this->assertSame(0, Product::withTrashed()->where('is_demo', true)->count());

        $product = $this->loadDemo();
        $admin = User::factory()->create();
        $this->organisation->members()->attach($admin->id, ['role' => 'admin']);
        $this->signIn($admin)->delete(route('products.destroy', $product))->assertRedirect(route('dashboard'));
        $this->assertFalse(Product::withTrashed()->whereKey($product->id)->exists());
    }

    public function test_the_home_hero_loads_the_demo_then_opens_it(): void
    {
        $this->useFixtureDemo();

        $html = $this->signIn($this->user)->get('/')->assertOk()->assertSee(__('guest.demo.button'))->getContent();
        $this->assertSame(1, substr_count($html, 'action="'.route('demo.load').'"'));
        $this->assertStringNotContainsString('data-sandbox-action', $html);

        $product = $this->loadDemo();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('action="'.route('demo.load').'"', $html);
        $this->assertStringContainsString('href="'.$product->homeUrl().'"', $html);
    }

    public function test_guests_keep_their_own_demo_flow(): void
    {
        $this->useFixtureDemo();
        $guest = $this->startSandbox();

        $this->post(route('demo.load'))->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('demo.load'), false);

        $this->post(route('guest.demo'))->assertRedirect();
        $product = Product::query()->where('owner_id', $guest->id)->sole();
        $this->assertFalse($product->is_demo);
        $this->assertSame(Plan::SLOT_FREE, $product->plan_slot);
        $this->assertTrue(app(DemoProduct::class)->hasData());
    }
}
