<?php

namespace Tests\Feature\Portfolio;

use App\Admin\WorkActivity;
use App\Assessment\SignificantChange;
use App\Assessment\VersionFactory;
use App\Enums\ProductRole;
use App\Enums\VersionStatus;
use App\Models\InventoryItem;
use App\Models\Organisation;
use App\Models\ProductVersion;
use App\Models\User;
use App\Portfolio\Portfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/** An organisation's portfolio (PortfolioController, App\Portfolio\Portfolio): its admins, paid plans. */
class PortfolioTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private User $owner;

    private Organisation $organisation;

    private ProductVersion $v1;

    private ProductVersion $v2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->owner();
        $this->organisation = $this->owner->organisations()->first();
        $this->v1 = $this->product($this->owner);
        $this->v1->forceFill(['status' => VersionStatus::Approved, 'approved_at' => now()->subMonth()])->save();
        InventoryItem::create(['product_version_id' => $this->v1->id, 'list' => 'assets', 'code' => 'AS-1', 'name' => 'Contacts', 'attributes' => ['type' => 'secret']]);
        $this->v2 = app(VersionFactory::class)->create($this->v1->product, '2.0', $this->owner, $this->v1->fresh());
        $this->v2->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-2', 'name' => 'Camera feed', 'attributes' => ['type' => 'secret']]);
    }

    private function paid(): void
    {
        $this->organisation->forceFill(['plan' => 'enterprise'])->save();
    }

    public function test_an_admin_on_a_paid_plan_sees_every_product_and_version(): void
    {
        $this->paid();
        activity('evaluation')->causedBy($this->owner)->performedOn($this->v2)->log('updated');

        $this->signIn($this->owner)->get(route('organisations.portfolio', $this->organisation))->assertOk()
            ->assertSee(__('portfolio.products'))
            ->assertSee('Smart lock')
            ->assertSee(route('versions.show', $this->v1), false)
            ->assertSee(route('versions.show', $this->v2), false)
            ->assertSee(__('changes.state_short.to_decide'))
            ->assertSee(route('versions.changes', $this->v2), false)
            ->assertSee(__('portfolio.work_title', ['days' => 30]))
            ->assertSee(route('organisations.team.person', [$this->organisation, $this->owner->id]), false)
            ->assertSee(route('organisations.portfolio.period', [$this->organisation, 90]), false)
            ->assertDontSee(__('portfolio.paid_title'));

        $totals = (new Portfolio($this->organisation))->totals();
        $this->assertSame(['products' => 1, 'draft' => 1, 'in_review' => 0, 'approved' => 1, 'rechecks' => 0, 'to_decide' => 1], $totals);

        $this->get(route('organisations.portfolio.period', [$this->organisation, 90]))->assertOk()->assertSee(__('portfolio.work_title', ['days' => 90]));
        $this->get('/organisations/'.$this->organisation->id.'/portfolio/12')->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertSee(route('organisations.portfolio', $this->organisation), false);
    }

    public function test_the_figures_follow_the_decision_and_the_re_checks(): void
    {
        $this->paid();
        $this->v2->evaluations()->create(['standard' => 'demo-1', 'requirement' => 'ACC-1', 'inventory_item_id' => $this->v2->inventoryItems()->where('code', 'AS-2')->value('id'), 'decision_node' => 'DN-1']);
        $this->v2->evaluations()->first()->forceFill(['recheck' => true])->saveQuietly();
        SignificantChange::for($this->v2)->decide($this->owner, SignificantChange::SIGNIFICANT, 'A camera feed is new: reassess.');
        $this->v2->evaluations()->update(['recheck' => true]);

        $totals = (new Portfolio($this->organisation))->totals();
        $this->assertSame(0, $totals['to_decide']);
        $this->assertSame(1, $totals['rechecks']);
        $this->signIn($this->owner)->get(route('organisations.portfolio', $this->organisation))->assertOk()
            ->assertSee(__('changes.state_short.significant'));
    }

    public function test_the_free_plan_shows_what_it_would_bring(): void
    {
        $this->signIn($this->owner)->get(route('organisations.portfolio', $this->organisation))->assertOk()
            ->assertSee(__('portfolio.paid_title'))
            ->assertDontSee(__('portfolio.products'))
            ->assertDontSee(route('versions.show', $this->v2), false);
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('organisations.portfolio', $this->organisation), false);
    }

    public function test_only_the_organisation_admins_see_it(): void
    {
        $this->paid();
        $member = $this->member($this->v1->product, ProductRole::Contributor, internal: true);
        $this->signIn($member)->get(route('organisations.portfolio', $this->organisation))->assertNotFound();

        $stranger = $this->owner();
        $this->signIn($stranger)->get(route('organisations.portfolio', $this->organisation))->assertNotFound();

        // A sandbox guest, even made admin, never sees it.
        $guest = User::factory()->create();
        $guest->forceFill(['is_guest' => true])->save();
        $this->organisation->members()->attach($guest->id, ['role' => 'admin']);
        $this->signIn($guest)->get(route('organisations.portfolio', $this->organisation))->assertNotFound();
    }

    public function test_an_empty_organisation_shows_nothing_of_another(): void
    {
        $this->paid();
        activity('evaluation')->causedBy($this->owner)->performedOn($this->v2)->log('updated');
        $other = $this->owner();
        $empty = $other->organisations()->first();
        $empty->forceFill(['plan' => 'enterprise'])->save();

        $this->signIn($other)->get(route('organisations.portfolio', $empty))->assertOk()
            ->assertSee(__('portfolio.no_products'))
            ->assertSee(__('portfolio.no_work', ['days' => 30]))
            ->assertDontSee('Smart lock')
            ->assertDontSee($this->owner->email)
            ->assertDontSee(route('versions.show', $this->v1), false);
        $this->assertSame(['products' => 0, 'draft' => 0, 'in_review' => 0, 'approved' => 0, 'rechecks' => 0, 'to_decide' => 0], (new Portfolio($empty))->totals());
    }

    public function test_who_does_what_counts_this_organisation_only(): void
    {
        $other = $this->owner();
        $elsewhere = $this->product($other);
        activity('evaluation')->causedBy($this->owner)->performedOn($this->v2)->log('updated');
        activity('evaluation')->causedBy($other)->performedOn($elsewhere)->log('updated');

        $this->assertSame(1, WorkActivity::for(30, $this->organisation)->totals()['actions']);
        $this->assertSame(2, WorkActivity::for(30)->totals()['actions']);
    }
}
