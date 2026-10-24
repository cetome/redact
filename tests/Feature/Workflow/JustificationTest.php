<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\Justification;
use App\Assessment\JustificationCheck;
use App\Content\ContentPack;
use App\Enums\ProductRole;
use App\Enums\VersionStatus;
use App\Models\EinfoEntry;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * "Justified before it counts" (App\Assessment\Justification) on the fixture criteria
 * (tests/Fixtures/content-pack/assessments/demo-1):
 *   ACC-1  DN-1 → NOT APPLICABLE: all of E.Info.ACC-1.Asset, E.Info.UPD-1.Product
 *          DN-2 → FAIL:           any of E.Info.ACC-1.Asset, E.Info.UPD-1.Product
 *          DN-2 (any outcome):    all of E.Info.DT.ACC-1, E.Just.DT.ACC-1
 *   UPD-1  DN-1 → FAIL:           E.Info.UPD-1.Product
 */
class JustificationTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private const LONG = 'Access needs the paired owner app.';

    public function test_the_pack_serves_the_assessment_criteria(): void
    {
        $pack = app(ContentPack::class);
        $this->assertSame('1.1.4', $pack->assessment('demo-1', 'ACC-1')['clause']);
        $this->assertCount(3, $pack->assessment('demo-1', 'ACC-1')['cases']);
        $this->assertNull($pack->assessment('demo-1', 'NOPE-1'));
        $this->assertNull($pack->assessment('../demo-1', 'ACC-1'));
    }

    public function test_an_empty_or_short_justification_is_not_justified(): void
    {
        [$owner, $version, $asset] = $this->scoped();

        $this->walk($owner, $version, $asset, ['No', 'Yes'], '');
        $this->assertSame('unjustified', $this->statusOf($version, $asset));

        $this->walk($owner, $version, $asset, ['No', 'Yes'], 'Paired app.');
        $check = $this->check($version);
        $this->assertFalse($check->justified);
        $this->assertFalse($check->textLongEnough);
        $this->assertContains(__('assessment.reasons.short_text', ['min' => 20]), $check->reasons);

        $this->walk($owner, $version, $asset, ['No', 'Yes'], self::LONG);
        $check = $this->check($version);
        $this->assertTrue($check->justified);
        // DN-2 without outcome applies; the DN-1 NOT APPLICABLE and DN-2 FAIL rules do not.
        $this->assertSame([['DN-2', null]], array_map(fn ($r) => [$r['node'], $r['outcome']], $check->rules));
        $this->assertSame('done', $this->statusOf($version, $asset));
    }

    public function test_outcome_specific_rules_and_all_of_their_einfo(): void
    {
        [$owner, $version, $asset] = $this->scoped();

        // NOT APPLICABLE at DN-1: the asset's E.Info is required; UPD-1.Product counts only once UPD-1 places it.
        $this->walk($owner, $version, $asset, ['Yes'], self::LONG);
        $check = $this->check($version);
        $this->assertFalse($check->justified);
        $this->assertSame([['DN-1', 'NOT APPLICABLE', 'all']], array_map(fn ($r) => [$r['node'], $r['outcome'], $r['match']], $check->rules));
        $this->assertSame(['missing', 'not_required'], array_column($check->rules[0]['ids'], 'state'));
        $this->assertStringContainsString('/einfo/demo-1/'.$asset->id.'#e-'.md5($asset->id.'E.Info.ACC-1.Asset'), (string) $check->rules[0]['ids'][0]['href']);

        $this->einfo($version, 'E.Info.ACC-1.Asset', $asset);
        $this->assertTrue($this->check($version)->justified);

        // Once UPD-1 is walked, E.Info.UPD-1.Product sits on the product and "all" needs it too.
        $this->walk($owner, $version, null, ['Yes'], self::LONG, 'UPD-1');
        $check = $this->check($version);
        $this->assertFalse($check->justified);
        $this->assertSame(['documented', 'missing'], array_column($check->rules[0]['ids'], 'state'));
        $this->assertSame([['id' => 'E.Info.UPD-1.Product', 'mapped' => null, 'state' => 'missing', 'object' => 'product', 'href' => $check->missing()[0]['href']]], $check->missing());

        $this->einfo($version, 'E.Info.UPD-1.Product', null);
        $this->assertTrue($this->check($version)->justified);
    }

    public function test_any_of_their_einfo(): void
    {
        [$owner, $version, $asset] = $this->scoped();
        $this->walk($owner, $version, null, ['Yes'], self::LONG, 'UPD-1');

        // FAIL at DN-2: any of the asset's E.Info and the product's update E.Info.
        $this->walk($owner, $version, $asset, ['No', 'No'], self::LONG);
        $check = $this->check($version);
        $this->assertFalse($check->justified);
        $this->assertEqualsCanonicalizing([['DN-2', 'FAIL'], ['DN-2', null]], array_map(fn ($r) => [$r['node'], $r['outcome']], $check->rules));

        $this->einfo($version, 'E.Info.UPD-1.Product', null);
        $this->assertTrue($this->check($version)->justified);
    }

    public function test_the_evaluation_page_shows_the_rules_the_einfo_links_and_the_criteria(): void
    {
        [$owner, $version, $asset] = $this->scoped();
        $this->walk($owner, $version, $asset, ['Yes'], self::LONG);

        $this->signIn($owner)->get(route('versions.evaluate.target', [$version, 'demo-1', 'ACC-1', $asset->id]))
            ->assertOk()
            ->assertSee(__('assessment.based_on'))
            ->assertSee(__('assessment.rule_at_outcome', ['node' => 'DN-1', 'outcome' => 'NOT APPLICABLE']))
            ->assertSee(route('versions.einfo.object', [$version, 'demo-1', $asset->id]).'#e-'.md5($asset->id.'E.Info.ACC-1.Asset'), false)
            ->assertSee(__('assessment.criteria', ['standard' => 'DEMO-1 (sample standard)', 'clause' => '1.1.4']))
            ->assertSee('Sample purpose: check the documented decisions.')
            ->assertSee('<li>Sample unit one</li>', false)
            ->assertSee(__('evaluations.status.unjustified'));
    }

    public function test_french_pages_mark_the_standard_text_as_english(): void
    {
        [$owner, $version, $asset] = $this->scoped();
        $owner->forceFill(['locale' => 'fr'])->save();
        $this->walk($owner, $version, $asset, ['Yes'], self::LONG);
        app()->setLocale('fr');

        $this->signIn($owner)->get(route('versions.evaluate.target', [$version, 'demo-1', 'ACC-1', $asset->id]))
            ->assertOk()
            ->assertSee('<div class="a-criteria-text" lang="en">', false);
    }

    public function test_not_justified_shows_in_the_lists_and_blocks_the_submission(): void
    {
        Mail::fake();
        [$owner, $version, $asset] = $this->scoped();
        $this->walk($owner, $version, $asset, ['No', 'Yes'], 'Paired app.');

        $this->assertSame(1, Assessment::for($version)->evaluationProgress('demo-1')['unjustified']);
        $this->signIn($owner)->get(route('versions.evaluate', [$version, 'demo-1']))->assertOk()->assertSee(__('evaluations.status.unjustified'));
        $this->signIn($owner)->get(route('versions.show', $version))->assertOk()->assertSee(__('evaluations.status.unjustified'));

        // Saving a draft is never blocked, submitting is.
        $this->member($version->product, ProductRole::Reviewer);
        $this->provideUnits($version);
        $this->signIn($owner)->from(route('versions.show', $version))->post(route('versions.submit', $version))
            ->assertRedirect(route('versions.show', $version))
            ->assertSessionHas('error', trans_choice('assessment.submit_refused', 1, ['count' => 1]))
            ->assertSessionHas('error_link.href', route('versions.evaluate.target', [$version, 'demo-1', 'ACC-1', $asset->id]));
        $this->assertSame(VersionStatus::Draft, $version->fresh()->status);
        $this->provideUnits($version);
        $this->signIn($owner)->followingRedirects()->from(route('versions.show', $version))->post(route('versions.submit', $version))
            ->assertSee(__('assessment.submit_first', ['what' => 'ACC-1 · '.$asset->label()]));

        $this->walk($owner, $version, $asset, ['No', 'Yes'], self::LONG);
        $this->provideUnits($version);
        $this->signIn($owner)->post(route('versions.submit', $version))->assertRedirect();
        $this->assertSame(VersionStatus::InReview, $version->fresh()->status);
    }

    public function test_ids_printed_for_another_asset_type_are_read_for_the_object(): void
    {
        // The fixture pack's einfo-variants.json: Key <-> Data (…Asset), UserPort <-> NetworkPort.
        $this->assertSame(['E.Info.ACC-1.DataAsset.Public'], Justification::variants('E.Info.ACC-1.KeyAsset.Public'));
        $this->assertSame(['E.Info.ACC-1.StoredKeyAssets'], Justification::variants('E.Info.ACC-1.StoredDataAssets'));
        $this->assertSame(['E.Info.UPD-1.NetworkPort.Access'], Justification::variants('E.Info.UPD-1.UserPort.Access'));
        $this->assertSame([], Justification::variants('E.Info.ACC-1.Asset'));
        // The "E" and "Info" segments are never read; no definitions, no variants.
        $this->assertSame([], Justification::variants('KeyAsset.Info.Asset'));
        $this->assertSame([], Justification::variants('E.Info.ACC-1.KeyAsset', []));
    }

    public function test_a_rule_the_app_cannot_check_needs_a_human_check_never_a_silent_pass(): void
    {
        Mail::fake();
        [$owner, $version, $asset] = $this->scoped();
        $this->walk($owner, $version, $asset, ['No', 'Yes'], self::LONG);
        // UPD-1 PASS at DN-1 is based on an asset E.Info the product never has.
        $this->walk($owner, $version, null, ['Yes'], self::LONG, 'UPD-1');

        $check = $this->check($version, 'UPD-1');
        $this->assertTrue($check->justified);
        $this->assertSame(1, $check->toCheck());
        $this->assertTrue($check->rules[0]['check']);
        $this->assertSame(1, Assessment::for($version->fresh())->evaluationProgress('demo-1')['to_check']);

        $this->signIn($owner)->get(route('versions.evaluate', [$version, 'demo-1']))->assertOk()->assertSee(__('assessment.to_check'));
        $this->signIn($owner)->get(route('versions.evaluate.requirement', [$version, 'demo-1', 'UPD-1']))->assertOk()
            ->assertSee(trans_choice('assessment.to_check_count', 1, ['count' => 1]));
        // Not blocking: the version goes to review, where reviewers see it.
        $this->member($version->product, ProductRole::Reviewer);
        $this->provideUnits($version);
        $this->signIn($owner)->post(route('versions.submit', $version))->assertRedirect();
        $this->assertSame(VersionStatus::InReview, $version->fresh()->status);
        $this->signIn($owner)->get(route('versions.review', $version))->assertOk()
            ->assertSee('data-facet="tocheck"', false)->assertSee('data-facet-value="todo tocheck"', false);
    }

    public function test_the_version_cannot_be_approved_while_an_evaluation_is_not_justified(): void
    {
        Mail::fake();
        [$owner, $version, $asset] = $this->scoped();
        $reviewer = $this->member($version->product, ProductRole::Reviewer);
        $this->walk($owner, $version, $asset, ['No', 'Yes'], self::LONG);
        $this->provideUnits($version);
        $this->signIn($owner)->post(route('versions.submit', $version))->assertRedirect();
        // Justified when submitted; the rule changed since (here: the text was shortened).
        Evaluation::where('requirement', 'ACC-1')->update(['justification' => 'Paired app.']);

        $this->signIn($reviewer)->post(route('versions.review.decide', $version), ['verdict' => 'approved'])
            ->assertSessionHas('error', trans_choice('assessment.approve_refused_unjustified', 1, ['count' => 1, 'list' => 'ACC-1 · '.$asset->label()]))
            // The reviewer is led to the evidence panel on the Review page, never to an editor.
            ->assertSessionHas('error_link.href', route('versions.review.evidence', [$version, Evaluation::where('requirement', 'ACC-1')->value('id')]));
        $this->assertSame(VersionStatus::InReview, $version->fresh()->status);
    }

    /** @return array{0: User, 1: ProductVersion, 2: InventoryItem} */
    private function scoped(): array
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $asset = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Door key', 'attributes' => ['type' => 'security']]);

        return [$owner, $version, $asset];
    }

    /** @param list<string> $answers */
    private function walk(User $owner, ProductVersion $version, ?InventoryItem $item, array $answers, string $justification, string $requirement = 'ACC-1'): void
    {
        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', $requirement]), [
            'target' => $item->id ?? 'product', 'answers' => $answers, 'justification' => $justification,
        ])->assertSessionHasNoErrors();
    }

    private function einfo(ProductVersion $version, string $code, ?InventoryItem $item): void
    {
        EinfoEntry::create(['product_version_id' => $version->id, 'einfo' => $code, 'inventory_item_id' => $item?->id, 'text' => 'Documented for the test.']);
    }

    private function check(ProductVersion $version, string $requirement = 'ACC-1'): JustificationCheck
    {
        $evaluation = Evaluation::where('requirement', $requirement)->firstOrFail();

        return Assessment::for($version->fresh())->justification()->check($evaluation);
    }

    private function statusOf(ProductVersion $version, InventoryItem $asset): string
    {
        $assessment = Assessment::for($version->fresh());

        return $assessment->status($assessment->evaluation('demo-1', 'ACC-1', $asset), $assessment->pack->tree('demo-1', 'ACC-1'));
    }
}
