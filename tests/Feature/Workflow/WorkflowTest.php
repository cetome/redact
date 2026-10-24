<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Enums\ProductRole;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Mail\ProductInvitation;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * The assessment workflow on the demo content pack (standard demo-1,
 * requirement ACC-1 per asset with a two-node tree, UPD-1 product-level).
 */
class WorkflowTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_creating_a_product_creates_a_first_version_with_the_pack_risk_matrix(): void
    {
        $owner = $this->owner();

        $this->signIn($owner)->post(route('products.store'), [
            'organisation_id' => $owner->organisations()->first()->id,
            'name' => 'Smart lock',
            'version_label' => '1.0',
        ])->assertRedirect();

        $version = $owner->organisations()->first()->products()->first()->versions()->first();
        $this->assertSame('1.0', $version->label);
        $this->assertSame('demo@0.1.0', $version->content_pack);
        $this->assertSame(5, $version->riskLevels()->count());
        $this->assertSame(25, $version->riskMatrixCells()->count());
    }

    public function test_scoping_brings_the_standard_into_scope(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);

        $this->signIn($owner)->put(route('versions.scoping.update', $version), ['answers' => ['connected' => '1']])
            ->assertRedirect();

        $this->assertTrue(Assessment::for($version)->hasStandard('demo-1'));
        $this->signIn($owner)->get(route('versions.scoping', $version))->assertOk()->assertSee('DEMO-1');
    }

    public function test_walking_a_tree_records_the_last_node_and_the_verdict(): void
    {
        [$owner, $version, $asset] = $this->assessedProduct();

        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id,
            'answers' => ['No', 'Yes'],
            'justification' => 'Access is controlled by the app pairing.',
        ])->assertRedirect();

        $evaluation = Evaluation::firstOrFail();
        $this->assertSame('DN-2', $evaluation->decision_node);
        $this->assertSame(Verdict::Pass, $evaluation->verdict);
        $this->assertSame('DT.ACC-1.DN-2', $evaluation->nodeReference());
    }

    public function test_an_outcome_the_tree_cannot_produce_is_refused(): void
    {
        [$owner, $version, $asset] = $this->assessedProduct();

        // DN-1 can only lead to NOT APPLICABLE.
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id,
            'decision_node' => 'DN-1',
            'verdict' => 'PASS',
        ])->assertSessionHasErrors('verdict');

        $this->assertSame(0, Evaluation::count());
    }

    public function test_objects_outside_the_requirement_cannot_be_evaluated(): void
    {
        [$owner, $version] = $this->assessedProduct();
        $interface = $version->inventoryItems()->create(['list' => 'interfaces', 'code' => 'IF-01']);

        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $interface->id,
            'answers' => ['Yes'],
        ])->assertSessionHasErrors('target');
    }

    public function test_einfo_is_required_once_the_tree_is_walked_and_only_required_items_are_saved(): void
    {
        [$owner, $version, $asset] = $this->assessedProduct();
        $this->walk($owner, $version, $asset);

        $this->signIn($owner)->put(route('versions.einfo.update', [$version, 'demo-1']), [
            'entries' => [
                $asset->id => ['E.Info.ACC-1.Asset' => 'Paired app only.', 'E.Info.Unknown' => 'nope'],
            ],
        ])->assertRedirect();

        $this->assertSame(['E.Info.ACC-1.Asset'], $version->einfoEntries()->pluck('einfo')->all());
    }

    public function test_roles_are_enforced_with_404(): void
    {
        [$owner, $version, $asset] = $this->assessedProduct();
        $reviewer = $this->member($version->product, ProductRole::Reviewer);
        $contributor = $this->member($version->product, ProductRole::Contributor, internal: true);
        $stranger = User::factory()->create();

        $payload = ['target' => $asset->id, 'answers' => ['No', 'Yes']];
        $url = route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']);

        $this->signIn($stranger)->get(route('versions.show', $version))->assertNotFound();
        $this->signIn($reviewer)->get(route('versions.show', $version))->assertOk();
        $this->signIn($reviewer)->put($url, $payload)->assertNotFound();
        $this->signIn($contributor)->put($url, $payload)->assertRedirect();
        $this->signIn($contributor)->post(route('team.invite', $version->product), ['email' => 'x@example.com', 'role' => 'reviewer'])->assertNotFound();
    }

    public function test_review_cycle_and_new_version_copies_everything_but_reviews(): void
    {
        Mail::fake();
        [$owner, $version, $asset] = $this->assessedProduct();
        $this->walk($owner, $version, $asset);
        $reviewer = $this->member($version->product, ProductRole::Reviewer);

        $this->provideUnits($version);
        $this->signIn($owner)->post(route('versions.submit', $version))->assertRedirect();
        $this->assertSame(VersionStatus::InReview, $version->fresh()->status);

        // Locked while in review.
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), ['target' => $asset->id, 'answers' => ['Yes']])
            ->assertNotFound();

        $evaluation = Evaluation::firstOrFail();
        $this->signIn($reviewer)->post(route('versions.review.evaluation', [$version, $evaluation]), ['verdict' => 'agree'])->assertRedirect();
        $this->signIn($reviewer)->post(route('versions.review.decide', $version), ['verdict' => 'approved'])->assertRedirect();
        $this->assertSame(VersionStatus::Approved, $version->fresh()->status);

        $this->signIn($owner)->post(route('versions.store', $version->product), ['label' => '2.0', 'from' => $version->id])->assertRedirect();
        $next = $version->product->versions()->where('label', '2.0')->firstOrFail();
        $this->assertSame(1, $next->evaluations()->count());
        $this->assertSame(1, $next->inventoryItems()->count());
        $this->assertSame(0, $next->evaluations()->first()->reviews()->count());
        $this->assertNotSame($asset->id, $next->evaluations()->first()->inventory_item_id);
    }

    public function test_invitations_are_accepted_only_by_the_invited_address(): void
    {
        Mail::fake();
        [$owner, $version] = $this->assessedProduct();
        $invitee = User::factory()->create(['email' => 'lab@example.com']);
        $other = User::factory()->create();

        $this->signIn($owner)->post(route('team.invite', $version->product), ['email' => 'lab@example.com', 'role' => 'reviewer'])
            ->assertSessionHas('status');
        Mail::assertQueued(ProductInvitation::class, function ($mail) use (&$token) {
            $token = $mail->token;

            return true;
        });

        $this->signIn($other)->post(route('invitations.accept', $token))->assertNotFound();
        $this->signIn($invitee)->post(route('invitations.accept', $token))->assertRedirect();
        $this->assertSame(ProductRole::Reviewer, $version->product->roleOf($invitee));
        $this->assertTrue($version->product->members()->where('user_id', $invitee->id)->first()->external);
    }

    public function test_tree_viewer_and_every_workflow_page_render(): void
    {
        [$owner, $version, $asset] = $this->assessedProduct();
        $this->walk($owner, $version, $asset);
        $this->signIn($owner);

        foreach ([
            route('dashboard'), route('products.edit', $version->product), route('team.index', $version->product),
            route('versions.show', $version), route('versions.edit', $version), route('versions.scoping', $version),
            route('versions.risks', $version), route('versions.inventory', [$version, 'assets']),
            route('versions.evaluate', [$version, 'demo-1']), route('versions.evaluate.requirement', [$version, 'demo-1', 'ACC-1']),
            route('versions.einfo', [$version, 'demo-1']), route('versions.review', $version),
            route('trees.index'), route('trees.standard', 'demo-1'), route('trees.show', ['demo-1', 'ACC-1']),
        ] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('trees.show', ['demo-1', 'NOPE-1']))->assertNotFound();
        // The product page is the latest version's Overview.
        $this->get(route('products.show', $version->product))->assertRedirect(route('versions.show', $version));
    }

    public function test_risk_register_and_matrix(): void
    {
        [$owner, $version] = $this->assessedProduct();

        $this->signIn($owner)->post(route('versions.risks.store', $version), [
            'threat' => 'Fake firmware', 'stride' => ['T', 'D'], 'impact' => 5, 'likelihood' => 3,
            'articles' => ['A1'], 'requirements' => 'UPD-1, NOT-A-REQ', 'residual_rank' => 4,
        ])->assertRedirect();

        $risk = $version->riskEntries()->firstOrFail();
        $this->assertSame(['UPD-1'], $risk->requirements);
        $this->assertSame(15, $risk->score());

        $this->signIn($owner)->post(route('versions.risks.store', $version), ['threat' => 'x', 'stride' => ['Z']])
            ->assertSessionHasErrors('stride.0');
    }

    /** @return array{0: User, 1: ProductVersion, 2: InventoryItem} */
    private function assessedProduct(): array
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $asset = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Door key', 'attributes' => ['type' => 'security']]);

        return [$owner, $version, $asset];
    }

    private function walk(User $owner, $version, $asset): void
    {
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $asset->id, 'answers' => ['No', 'Yes'], 'justification' => 'Access needs the paired owner app.',
        ])->assertRedirect();
    }
}
