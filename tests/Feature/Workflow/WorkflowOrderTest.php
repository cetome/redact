<?php

namespace Tests\Feature\Workflow;

use App\Assessment\ArticlesCheck;
use App\Assessment\Assessment;
use App\Assessment\VersionFactory;
use App\Enums\ProductRole;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\WorkflowSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Workflow order (owner's figures): product, essential requirements (the scoping, confirmed),
 * risk assessment (product risks, checked against the essential requirements in scope), assets &
 * interfaces, decision trees, E.Info, conformity, review, declaration & technical file.
 * On the demo pack: one article (A1), one part (demo-1) with ACC-1 (family ACC) and UPD-1 (UPD);
 * the questions "connected" and "updates" both bring A1 in.
 */
class WorkflowOrderTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private const ORDER = ['description', 'scoping', 'risks', 'inventory', 'evaluate', 'einfo', 'units', 'conformity', 'review', 'declaration'];

    private function risk(ProductVersion $version, array $articles = [], array $requirements = [], string $threat = 'Door opened by a stranger'): string
    {
        return $version->riskEntries()->create([
            'position' => (int) $version->riskEntries()->max('position') + 1,
            'threat' => $threat, 'articles' => $articles, 'requirements' => $requirements,
        ])->id;
    }

    private function scope(User $owner, ProductVersion $version, bool $connected, array $extra = []): void
    {
        $this->signIn($owner)->put(route('versions.scoping.update', $version), ['answers' => ['connected' => $connected ? '1' : '0', 'updates' => '0'], ...$extra])
            ->assertRedirect();
    }

    public function test_the_steps_run_product_essential_requirements_risks_then_assets(): void
    {
        $version = $this->product($this->owner());

        // "Changes" (significant change) comes after the inventory, only on a version made from an earlier one.
        $this->assertSame(['description', 'scoping', 'risks', 'inventory', 'changes', 'evaluate', 'einfo', 'units', 'conformity', 'review', 'declaration'], Assessment::ORDER);
        $this->assertSame(self::ORDER, array_values(array_unique(array_column(Assessment::for($version)->steps(), 'key'))));
        $this->assertSame(self::ORDER, array_column(WorkflowSummary::for($version)->groups, 'key'));
    }

    public function test_the_next_step_after_each_step(): void
    {
        $version = $this->product($this->owner());
        $assessment = Assessment::for($version);

        $this->assertSame(['key' => 'scoping', 'label' => 'Essential requirements', 'href' => route('versions.scoping', $version)], $assessment->nextStepAfter('description'));
        $this->assertSame(route('versions.risks', $version), $assessment->nextStepAfter('scoping')['href']);
        $this->assertSame('Risk assessment', $assessment->nextStepAfter('scoping')['label']);
        $this->assertSame('inventory', $assessment->nextStepAfter('risks')['key']);
        $this->assertStringContainsString('/inventory/', $assessment->nextStepAfter('risks')['href']);
        $this->assertSame(route('versions.evaluate', [$version, 'demo-1']), $assessment->nextStepAfter('inventory')['href']);
        $this->assertSame(route('versions.einfo', [$version, 'demo-1']), $assessment->nextStepAfter('evaluate')['href']);
        $this->assertSame(['key' => 'units', 'label' => 'Assessment units', 'href' => route('versions.units', $version)], $assessment->nextStepAfter('einfo'));
        $this->assertSame(route('versions.conformity', $version), $assessment->nextStepAfter('units')['href']);
        $this->assertSame(route('versions.review', $version), $assessment->nextStepAfter('conformity')['href']);
        $this->assertSame(['key' => 'declaration', 'label' => 'Declaration & technical file', 'href' => route('versions.declaration', $version)], $assessment->nextStepAfter('review'));
        $this->assertNull($assessment->nextStepAfter('declaration'));
        $this->assertNull($assessment->nextStepAfter('reports'));
    }

    public function test_save_and_continue_links_follow_the_order(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);

        // Product → Essential requirements.
        $this->signIn($owner)->get(route('versions.edit', $version))->assertOk()->assertSee('name="continue"', false)
            ->assertSee('title="Continue to Essential requirements"', false);
        $this->put(route('versions.update', $version), ['description' => 'A door lock.', 'continue' => '1'])
            ->assertRedirect(route('versions.scoping', $version));
        $this->put(route('versions.update', $version), ['description' => 'A door lock.'])->assertRedirect();

        // Essential requirements → Risk assessment.
        $this->put(route('versions.scoping.update', $version), ['answers' => ['connected' => '1'], 'continue' => '1'])
            ->assertRedirect(route('versions.risks', $version));
        $this->put(route('versions.scoping.update', $version), ['answers' => ['connected' => '1']])
            ->assertRedirect(route('versions.scoping', $version));
        $this->get(route('versions.scoping', $version))->assertOk()->assertSee('title="Continue to Risk assessment"', false);

        // Risk assessment → Assets & interfaces.
        $this->get(route('versions.risks', $version))->assertOk()
            ->assertSee('Continue to Assets &amp; interfaces', false)
            ->assertSee('href="'.Assessment::for($version)->stepUrl('inventory').'"', false);
    }

    public function test_the_sidebar_and_overview_list_the_steps_in_order(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);

        $labels = ['Product', 'Essential requirements', 'Risk assessment', 'Assets & interfaces', 'Decision trees', 'E.Info', 'Conformity', 'Review', 'Declaration & technical file'];
        $this->signIn($owner)->get(route('versions.show', $version))->assertOk()
            ->assertSeeInOrder($labels)
            ->assertSee('data-shortcut="g s" data-shortcut-label="Essential requirements"', false)
            ->assertDontSee('Articles &amp; standards', false);

        // Nothing done yet: the next step is the product.
        $this->assertSame('Product', WorkflowSummary::for($version)->next()['label']);

        // French: "Exigences essentielles".
        app()->setLocale('fr');
        $this->assertSame('Exigences essentielles', Assessment::for($version)->nextStepAfter('description')['label']);
    }

    public function test_the_essential_requirements_page_has_no_risk_table(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $this->risk($version, ['A1']);
        $this->scope($owner, $version, false);

        // The questions and the confirmation only: the risks come after, on their own page.
        $this->signIn($owner)->get(route('versions.scoping', $version))->assertOk()
            ->assertSee('Essential requirements')
            ->assertSee('id="scope-q-connected"', false)
            ->assertSee('name="confirm"', false)
            ->assertDontSee('Risks against the essential requirements')
            ->assertDontSee('requirements named by a risk');
    }

    public function test_the_risk_step_works_with_an_empty_inventory(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'updates', 'value' => true]);
        $this->assertSame(0, $version->inventoryItems()->count());

        $this->signIn($owner)->get(route('versions.risks', $version))->assertOk()->assertSee('Add the suggested threat');
        $this->get(route('versions.risks.new', $version))->assertOk()->assertSee('name="articles[]"', false);
        $this->post(route('versions.risks.library', $version), ['threats' => [1]])->assertRedirect();
        $this->post(route('versions.risks.store', $version), ['threat' => 'Stolen phone', 'articles' => ['A1'], 'requirements' => ['ACC']])->assertRedirect();

        $this->assertSame(2, $version->riskEntries()->count());
        $this->get(route('versions.risks', $version))->assertOk()->assertSee('Stolen phone');
        $this->get(route('versions.risks.edit', [$version, $version->riskEntries()->first()->id]))->assertOk();
    }

    public function test_the_risk_form_marks_what_is_not_in_scope(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);

        // Not answered yet: nothing is marked.
        $this->signIn($owner)->get(route('versions.risks.new', $version))->assertOk()
            ->assertDontSee('Parts of EN 18031 not in scope')->assertDontSee('a-chip-out', false);

        // A1 out of scope: neither its chip nor the mechanisms of demo-1 can be picked (owner, 2026-10-23:
        // nothing out of scope is selectable), unless the risk already has them.
        $this->scope($owner, $version, false);
        $this->get(route('versions.risks.new', $version))->assertOk()
            ->assertDontSee('Parts of EN 18031 not in scope')->assertDontSee('a-chip-out', false)
            ->assertDontSee('name="articles[]" value="A1"', false);

        // A1 in scope: nothing is marked.
        $this->scope($owner, $version, true);
        $this->get(route('versions.risks.new', $version))->assertOk()
            ->assertDontSee('Parts of EN 18031 not in scope')->assertDontSee('a-chip-out', false);
    }

    public function test_the_risk_page_checks_the_risks_against_the_essential_requirements(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $id = $this->risk($version, ['A1']);

        // Before any answer: nothing to compare, a link to the step before.
        $this->assertNull(ArticlesCheck::for($version)->articles()[0]['in_scope']);
        $this->assertSame([], ArticlesCheck::for($version)->mismatches());
        $this->signIn($owner)->get(route('versions.risks', $version))->assertOk()
            ->assertSee('Risks against the essential requirements')
            ->assertSee('Answer the questions of Essential requirements')
            ->assertSee('href="'.route('versions.scoping', $version).'"', false);

        // A risk concerns A1, the answers leave A1 out: flagged, with the cards that bring it in.
        $this->scope($owner, $version, false);
        $row = ArticlesCheck::for($version)->articles()[0];
        $this->assertSame('risks_out_of_scope', $row['issue']);
        $this->assertSame(['connected', 'updates'], array_column($row['questions'], 'key'));
        $this->get(route('versions.risks', $version))->assertOk()
            ->assertSee('1 risk concerns Sample article A1 but it is not in scope.')
            ->assertSee('href="'.route('versions.scoping', $version).'#scope-q-connected"', false)
            ->assertSee(route('versions.risks.edit', [$version, $id]), false)
            ->assertSee('1 mismatch');

        // A1 in scope, no risk concerns it: add one from the threat library.
        $version->riskEntries()->update(['articles' => []]);
        $this->scope($owner, $version, true);
        $this->assertSame('no_risk', ArticlesCheck::for($version)->articles()[0]['issue']);
        $this->get(route('versions.risks', $version))->assertOk()
            ->assertSee('Sample article A1 is in scope but no risk concerns it.')
            ->assertSee(route('versions.risks.library.show', $version), false);

        // In scope and concerned: no mismatch.
        $this->risk($version, ['A1']);
        $this->assertSame([], ArticlesCheck::for($version)->mismatches());
        $this->get(route('versions.risks', $version))->assertOk()->assertSee('No mismatch')->assertSee('In scope and concerned by the risks');
    }

    public function test_the_requirements_the_risks_name_per_part(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $this->scope($owner, $version, true);
        $first = $this->risk($version, ['A1'], ['UPD-1'], 'Rogue update');

        $part = ArticlesCheck::for($version)->coverage()[0];
        $this->assertSame('demo-1', $part['code']);
        $this->assertSame(2, $part['total']);
        $this->assertSame(['UPD-1'], array_column($part['covered'], 'code'));
        $this->assertSame($first, $part['covered'][0]['risks'][0]['id']);
        $this->assertSame(['ACC-1'], array_column($part['uncovered'], 'code'));
        $this->signIn($owner)->get(route('versions.risks', $version))->assertOk()
            ->assertSee('1 of 2 requirements named by a risk')->assertSee('Not named by any risk');

        // A family covers each of its requirements ("through the family").
        $this->risk($version, ['A1'], ['ACC'], 'Stolen phone');
        $part = ArticlesCheck::for($version)->coverage()[0];
        $this->assertSame(['ACC-1', 'UPD-1'], array_column($part['covered'], 'code'));
        $this->assertSame('ACC', $part['covered'][0]['risks'][0]['via']);
        $this->assertSame(2, $part['covered'][0]['risks'][0]['n']);
        $this->get(route('versions.risks', $version))->assertOk()->assertSee('2 of 2 requirements named by a risk')->assertSee('through the family ACC');

        // Out of scope: no part to cover, the named mechanisms are listed as outside the scope.
        $this->scope($owner, $version, false);
        $this->assertSame([], ArticlesCheck::for($version)->coverage());
        $this->assertSame(['UPD-1', 'ACC'], ArticlesCheck::for($version)->namedOutsideScope());
        $this->get(route('versions.risks', $version))->assertOk()->assertSee('Named by the risks, in no part in scope');
    }

    public function test_the_confirmation_depends_on_the_scoping_only(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $step = fn () => collect(Assessment::for($version->fresh())->steps())->firstWhere('key', 'scoping');

        $this->assertSame('unanswered', ArticlesCheck::for($version)->state());
        $this->assertFalse($step()['done']);

        // Confirmed with no risk at all: the risks come after this step.
        $this->scope($owner, $version, true, ['confirm' => '1']);
        $version->refresh();
        $this->assertSame(0, $version->riskEntries()->count());
        $this->assertNotNull($version->articles_confirmed_at);
        $this->assertSame($owner->id, $version->articles_confirmed_by);
        $this->assertSame('confirmed', ArticlesCheck::for($version)->state());
        $this->assertTrue($step()['done']);
        $this->assertTrue(Activity::where('log_name', 'version')->where('description', 'articles_confirmed')->where('subject_id', $version->id)->exists());
        $this->signIn($owner)->get(route('versions.scoping', $version))->assertOk()->assertSee('Confirmed by '.$owner->name);

        // Risks added, re-tagged or deleted: still confirmed.
        $id = $this->risk($version, ['A1'], ['UPD-1']);
        $this->assertSame('confirmed', ArticlesCheck::for($version->fresh())->state());
        $version->riskEntries()->update(['articles' => []]);
        $this->assertSame('confirmed', ArticlesCheck::for($version->fresh())->state());
        $version->riskEntries()->whereKey($id)->delete();
        $this->assertSame('confirmed', ArticlesCheck::for($version->fresh())->state());

        // A scoping change that takes A1 (and demo-1) out: to re-confirm.
        $this->scope($owner, $version, false);
        $this->assertSame('stale', ArticlesCheck::for($version->fresh())->state());
        $this->assertFalse($step()['done']);
        $this->get(route('versions.scoping', $version))->assertOk()->assertSee('To re-confirm')->assertSee('Confirm again');

        // Back to the confirmed scope: confirmed again without a new confirmation.
        $this->scope($owner, $version, true);
        $this->assertSame('confirmed', ArticlesCheck::for($version->fresh())->state());

        // Another answer that brings the same article in: same essential requirements, still confirmed.
        $this->signIn($owner)->put(route('versions.scoping.update', $version), ['answers' => ['connected' => '1', 'updates' => '1']])->assertRedirect();
        $this->assertSame('confirmed', ArticlesCheck::for($version->fresh())->state());
    }

    public function test_only_editors_confirm_and_a_new_version_confirms_again(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $this->scope($owner, $version, true, ['confirm' => '1']);
        $this->assertSame('confirmed', ArticlesCheck::for($version->fresh())->state());

        $reviewer = $this->member($version->product, ProductRole::Reviewer);
        $this->signIn($reviewer)->put(route('versions.scoping.update', $version), ['answers' => ['connected' => '1'], 'confirm' => '1'])->assertNotFound();
        $this->signIn($reviewer)->get(route('versions.scoping', $version))->assertOk()->assertDontSee('name="confirm"', false);

        $next = app(VersionFactory::class)->create($version->product, '2.0', $owner, $version->fresh());
        $this->assertNull($next->articles_confirmed_at);
        $this->assertSame('unconfirmed', ArticlesCheck::for($next)->state());
    }
}
