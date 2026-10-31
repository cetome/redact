<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\SignificantChange;
use App\Assessment\VersionFactory;
use App\Content\ContentPack;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Reports\ExcelReport;
use App\Reports\ReportData;
use App\Reports\ReportKind;
use App\Reports\WordReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;
use ZipArchive;

/** Significant change between a version and the one it was made from (App\Assessment\SignificantChange), on the demo pack. */
class SignificantChangeTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private User $owner;

    private ProductVersion $v1;

    private InventoryItem $contacts;

    private InventoryItem $password;

    protected function setUp(): void
    {
        parent::setUp();
        // The demo pack: three parts of the standard, so a part can come into scope.
        config(['redact.content_path' => base_path('packs/demo')]);
        $this->app->forgetInstance(ContentPack::class);

        $this->owner = $this->owner();
        $this->v1 = $this->product($this->owner);
        foreach (['connected' => true, 'updates' => true, 'personal_data' => false, 'payments' => false] as $question => $value) {
            $this->v1->scopingAnswers()->create(['question' => $question, 'value' => $value]);
        }
        $this->contacts = InventoryItem::create(['product_version_id' => $this->v1->id, 'list' => 'assets', 'code' => 'AS-1', 'name' => 'Contacts', 'attributes' => ['type' => 'operational', 'stored' => true]]);
        $this->password = InventoryItem::create(['product_version_id' => $this->v1->id, 'list' => 'assets', 'code' => 'AS-2', 'name' => 'Password', 'attributes' => ['type' => 'secret']]);
        $this->v1->riskEntries()->create(['position' => 1, 'threat' => 'Stolen password', 'impact' => 2, 'likelihood' => 2, 'requirements' => ['ACC-1'], 'residual_rank' => 4]);
        foreach ([$this->contacts, $this->password] as $item) {
            $this->v1->evaluations()->create(['standard' => 'demo-net', 'requirement' => 'ACC-1', 'inventory_item_id' => $item->id,
                'decision_node' => 'DN-2', 'verdict' => Verdict::Pass, 'justification' => 'The product checks the owner before any access to the asset (E.Info.ACC-1).']);
            $this->v1->einfoEntries()->create(['einfo' => 'E.Info.ACC-1', 'inventory_item_id' => $item->id, 'text' => 'Only the owner reaches it.']);
        }
    }

    private function copy(): ProductVersion
    {
        return app(VersionFactory::class)->create($this->v1->product, '2.0', $this->owner, $this->v1->fresh());
    }

    private function item(ProductVersion $version, string $code): InventoryItem
    {
        return $version->inventoryItems()->where('code', $code)->firstOrFail();
    }

    public function test_a_first_version_has_no_changes_step(): void
    {
        $this->assertNull(SignificantChange::for($this->v1));
        $this->assertNotContains('changes', array_column(Assessment::for($this->v1)->steps(), 'key'));
        $this->signIn($this->owner)->get(route('versions.changes', $this->v1))->assertNotFound();
        $this->assertSame('evaluate', Assessment::for($this->v1)->nextStepAfter('inventory')['key']);
    }

    public function test_an_unchanged_copy_shows_no_sign_and_nothing_to_decide(): void
    {
        $v2 = $this->copy();
        $changes = SignificantChange::for($v2);

        $this->assertSame([], $changes->signs());
        $this->assertSame('no_signs', $changes->state());
        $this->assertTrue($changes->settled());
        $this->assertSame($this->contacts->id, $this->item($v2, 'AS-1')->source_id, 'A copy keeps where it comes from.');
        $this->assertSame('changes', Assessment::for($v2)->nextStepAfter('inventory')['key']);

        $this->signIn($this->owner)->get(route('versions.changes', $v2))->assertOk()
            ->assertSee(__('changes.no_signs_title'))
            ->assertSee(__('changes.title', ['label' => '1.0']));
    }

    public function test_new_and_higher_risks_changed_assets_and_new_parts_are_signs(): void
    {
        $v2 = $this->copy();
        // Higher: impact 2, likelihood 2 → impact 4, likelihood 4; residual Low → High.
        $v2->riskEntries()->first()->update(['impact' => 4, 'likelihood' => 4, 'residual_rank' => 2]);
        $v2->riskEntries()->create(['position' => 2, 'threat' => 'Firmware replaced', 'impact' => 3, 'likelihood' => 2]);
        // Renamed only: not a change. New type: a change. New asset, removed asset.
        $this->item($v2, 'AS-1')->update(['name' => 'Address book', 'attributes' => ['type' => 'personal', 'stored' => true]]);
        $this->item($v2, 'AS-2')->update(['name' => 'Admin password']);
        $v2->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-3', 'name' => 'Card token', 'attributes' => ['type' => 'payment']]);
        $v2->scopingAnswers()->where('question', 'personal_data')->first()->update(['value' => true]);

        $changes = SignificantChange::for($v2->fresh());
        $kinds = collect($changes->signs())->pluck('kind')->all();

        $this->assertSame(['risk_up', 'risk_new', 'item_changed', 'item_added', 'scope_added'], $kinds);
        $this->assertStringContainsString('→', $changes->signs()[0]['detail']);
        $this->assertStringContainsString(__('changes.detail.residual', ['from' => 'Low', 'to' => 'High']), $changes->signs()[0]['detail']);
        $this->assertStringContainsString('Personal', $changes->signs()[2]['detail']);
        $this->assertSame('to_decide', $changes->state());

        $v2->inventoryItems()->where('code', 'AS-3')->delete();
        $this->item($v2, 'AS-2')->delete();
        $this->assertContains('item_removed', collect(SignificantChange::for($v2->fresh())->signs())->pluck('kind')->all());
    }

    public function test_a_significant_change_puts_what_it_touches_back_to_re_check(): void
    {
        $v2 = $this->copy();
        $this->item($v2, 'AS-1')->update(['attributes' => ['type' => 'personal', 'stored' => true]]);

        $this->signIn($this->owner)->post(route('versions.changes.decide', $v2), ['decision' => 'significant', 'justification' => 'short'])
            ->assertSessionHasErrors('justification');
        $this->post(route('versions.changes.decide', $v2), ['decision' => 'significant', 'justification' => 'The contacts are personal data now.'])
            ->assertRedirect(route('versions.changes', $v2));

        $v2->refresh();
        $this->assertSame('significant', $v2->change_decision);
        $contacts = $this->item($v2, 'AS-1');
        $marked = $v2->evaluations()->where('recheck', true)->pluck('inventory_item_id')->all();
        // The changed asset only: the risk did not change, the password is untouched.
        $this->assertSame([$contacts->id], $marked);
        $this->assertSame([$contacts->id], $v2->einfoEntries()->where('recheck', true)->pluck('inventory_item_id')->all());

        $assessment = Assessment::for($v2->fresh());
        $evaluation = $v2->evaluations()->where('inventory_item_id', $contacts->id)->first();
        $this->assertSame('recheck', $assessment->status($evaluation, $assessment->pack->tree('demo-net', 'ACC-1')));
        $step = collect($assessment->steps())->firstWhere('key', 'changes');
        $this->assertFalse($step['done']);
        $this->assertSame(trans_choice('changes.recheck_count', 2, ['count' => 2]), $step['detail']);

        $this->get(route('versions.changes', $v2))->assertOk()
            ->assertSee(__('changes.recheck_title'))
            ->assertSee('ACC-1 · AS-1 · Contacts')
            ->assertSee('The contacts are personal data now.');
        $this->get(route('versions.evaluate.target', [$v2, 'demo-net', 'ACC-1', $contacts->id]))->assertOk()
            ->assertSee(__('changes.still_valid'));

        // "Still valid" on the evaluation, a new text on the E.Info entry.
        $this->post(route('versions.changes.confirm.evaluation', [$v2, $evaluation]))->assertSessionHas('status', __('changes.confirmed'));
        $this->assertFalse($evaluation->fresh()->recheck);
        $entry = $v2->einfoEntries()->where('inventory_item_id', $contacts->id)->first();
        $entry->update(['text' => 'Only the owner reaches the contacts, encrypted at rest.']);
        $this->assertFalse($entry->fresh()->recheck);

        $changes = SignificantChange::for($v2->fresh());
        $this->assertSame([], $changes->rechecks());
        $this->assertTrue(collect(Assessment::for($v2->fresh())->steps())->firstWhere('key', 'changes')['done']);
    }

    public function test_the_decision_is_out_of_date_when_the_signs_change(): void
    {
        $v2 = $this->copy();
        $v2->riskEntries()->create(['position' => 2, 'threat' => 'Firmware replaced', 'impact' => 3, 'likelihood' => 2]);
        $changes = SignificantChange::for($v2);
        $changes->decide($this->owner, SignificantChange::NOT_SIGNIFICANT, 'Low impact, mitigated by signed updates.');
        $this->assertSame('decided', SignificantChange::for($v2->fresh())->state());
        app()->setLocale('fr');
        $this->assertSame('decided', SignificantChange::for($v2->fresh())->state(), 'Another language is not another sign.');
        app()->setLocale('en');

        $v2->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-9', 'name' => 'Camera feed', 'attributes' => ['type' => 'personal']]);
        $this->assertSame('outdated', SignificantChange::for($v2->fresh())->state());
    }

    public function test_submission_waits_for_the_decision_and_approval_for_the_re_checks(): void
    {
        $v2 = $this->copy();
        $this->item($v2, 'AS-1')->update(['attributes' => ['type' => 'personal', 'stored' => true]]);

        $this->signIn($this->owner)->from(route('versions.show', $v2))->post(route('versions.submit', $v2))
            ->assertSessionHas('error', __('changes.submit_refused', ['label' => '1.0']));
        $this->assertSame(VersionStatus::Draft, $v2->fresh()->status);

        SignificantChange::for($v2)->decide($this->owner, SignificantChange::SIGNIFICANT, 'The contacts are personal data now.');
        $v2->forceFill(['status' => VersionStatus::InReview, 'submitted_at' => now()])->save();
        $this->provideUnits($v2);

        $this->from(route('versions.review', $v2))->post(route('versions.review.decide', $v2), ['verdict' => 'approved'])
            ->assertSessionHas('error');
        $this->assertStringContainsString('AS-1 · Contacts', (string) session('error'));
        $this->assertNotSame(VersionStatus::Approved, $v2->fresh()->status);
    }

    public function test_a_not_significant_decision_clears_the_marks_and_the_history_tells_it(): void
    {
        $v2 = $this->copy();
        $this->item($v2, 'AS-1')->update(['attributes' => ['type' => 'personal', 'stored' => true]]);
        SignificantChange::for($v2)->decide($this->owner, SignificantChange::SIGNIFICANT, 'The contacts are personal data now.');
        $this->assertSame(2, count(SignificantChange::for($v2->fresh())->rechecks()));

        $this->signIn($this->owner)->post(route('versions.changes.decide', $v2), ['decision' => 'not_significant', 'justification' => 'Same protection as before, reviewed with the team.']);
        $this->assertSame([], SignificantChange::for($v2->fresh())->rechecks());

        $this->get(route('products.history', $v2->product))->assertOk()
            ->assertSeeText(__('history.events.change_decided.not_significant', ['label' => '1.0']))
            ->assertSeeText(__('history.events.change_decided.significant', ['label' => '1.0']))
            ->assertSeeText('Same protection as before, reviewed with the team.');
    }

    public function test_a_higher_risk_puts_back_every_started_evaluation_of_the_requirements_it_names(): void
    {
        $v2 = $this->copy();
        // Never started: to do anyway, not marked.
        $v2->evaluations()->create(['standard' => 'demo-net', 'requirement' => 'ACC-1', 'inventory_item_id' => null]);
        $v2->riskEntries()->first()->update(['impact' => 4, 'likelihood' => 4, 'requirements' => ['ACC']]);

        SignificantChange::for($v2)->decide($this->owner, SignificantChange::SIGNIFICANT, 'The stolen password is now critical.');

        // Named by its family: both started evaluations of ACC-1; no object changed, so no E.Info entry.
        $this->assertEqualsCanonicalizing([$this->item($v2, 'AS-1')->id, $this->item($v2, 'AS-2')->id],
            $v2->evaluations()->where('recheck', true)->pluck('inventory_item_id')->all());
        $this->assertSame(0, $v2->evaluations()->whereNull('inventory_item_id')->where('recheck', true)->count());
        $this->assertSame(0, $v2->einfoEntries()->where('recheck', true)->count());
    }

    public function test_the_requirements_a_risk_names_are_part_of_its_sign(): void
    {
        $v2 = $this->copy();
        $risk = $v2->riskEntries()->create(['position' => 2, 'threat' => 'Firmware replaced', 'impact' => 3, 'likelihood' => 2]);
        SignificantChange::for($v2)->decide($this->owner, SignificantChange::SIGNIFICANT, 'A new way in: reassess.');
        $this->assertSame(0, $v2->evaluations()->where('recheck', true)->count());

        // Named after the decision: what goes back to re-check changes, so the decision is out of date.
        $risk->update(['requirements' => ['ACC-1']]);
        $this->assertSame('outdated', SignificantChange::for($v2->fresh())->state());
        SignificantChange::for($v2->fresh())->decide($this->owner, SignificantChange::SIGNIFICANT, 'A new way in: reassess.');
        $this->assertSame(2, $v2->evaluations()->where('recheck', true)->count());
    }

    public function test_deciding_again_leaves_out_what_the_signs_no_longer_touch(): void
    {
        $v2 = $this->copy();
        $this->item($v2, 'AS-1')->update(['attributes' => ['type' => 'personal', 'stored' => true]]);
        SignificantChange::for($v2)->decide($this->owner, SignificantChange::SIGNIFICANT, 'The contacts are personal data now.');

        // The contacts are back as they were; the password changes instead.
        $this->item($v2, 'AS-1')->update(['attributes' => ['type' => 'operational', 'stored' => true]]);
        $this->item($v2, 'AS-2')->update(['attributes' => ['type' => 'personal']]);
        $this->assertSame('outdated', SignificantChange::for($v2->fresh())->state());
        SignificantChange::for($v2->fresh())->decide($this->owner, SignificantChange::SIGNIFICANT, 'The password is personal data now.');

        $password = $this->item($v2, 'AS-2')->id;
        $this->assertSame([$password], $v2->evaluations()->where('recheck', true)->pluck('inventory_item_id')->all());
        $this->assertSame([$password], $v2->einfoEntries()->where('recheck', true)->pluck('inventory_item_id')->all());
    }

    public function test_still_valid_confirms_a_shared_walk_in_every_part_tied_to_it(): void
    {
        $v2 = $this->copy();
        $v2->evaluations()->update(['shared_group' => 'group-1', 'recheck' => true]);
        $untied = $v2->evaluations()->create(['standard' => 'demo-pay', 'requirement' => 'ACC-1', 'inventory_item_id' => $this->item($v2, 'AS-1')->id,
            'decision_node' => 'DN-2', 'verdict' => Verdict::Pass, 'justification' => 'Decided separately for payments.']);
        $untied->forceFill(['shared_untied' => true, 'recheck' => true])->saveQuietly();

        $this->signIn($this->owner)->post(route('versions.changes.confirm.evaluation', [$v2, $v2->evaluations()->where('shared_untied', false)->first()]))
            ->assertSessionHas('status', __('changes.confirmed'));

        $this->assertSame([$untied->id], $v2->evaluations()->where('recheck', true)->pluck('id')->all());
    }

    public function test_versions_copied_before_source_ids_match_by_code_and_threat(): void
    {
        $v2 = $this->copy();
        $v2->inventoryItems()->update(['source_id' => null]);
        $v2->riskEntries()->update(['source_id' => null]);
        $this->item($v2, 'AS-2')->update(['name' => 'Admin password']);

        $this->assertSame([], SignificantChange::for($v2->fresh())->signs());

        $this->item($v2, 'AS-2')->update(['attributes' => ['type' => 'personal']]);
        $this->assertSame(['item_changed'], collect(SignificantChange::for($v2->fresh())->signs())->pluck('kind')->all());
    }

    public function test_a_deleted_earlier_version_leaves_no_step_and_the_marks_can_still_be_confirmed(): void
    {
        $v2 = $this->copy();
        $this->item($v2, 'AS-1')->update(['attributes' => ['type' => 'personal', 'stored' => true]]);
        SignificantChange::for($v2)->decide($this->owner, SignificantChange::SIGNIFICANT, 'The contacts are personal data now.');
        ProductVersion::query()->whereKey($this->v1->id)->delete();
        $v2 = $v2->fresh();

        $this->assertNull(SignificantChange::for($v2));
        $this->assertNotContains('changes', array_column(Assessment::for($v2)->steps(), 'key'));
        $this->signIn($this->owner)->get(route('versions.changes', $v2))->assertNotFound();

        $contacts = $this->item($v2, 'AS-1');
        $this->get(route('versions.evaluate.target', [$v2, 'demo-net', 'ACC-1', $contacts->id]))->assertOk()
            ->assertSee(__('changes.recheck_note_alone'))
            ->assertDontSee('href="'.route('versions.changes', $v2).'"', false);
        $evaluation = $v2->evaluations()->where('inventory_item_id', $contacts->id)->first();
        $this->post(route('versions.changes.confirm.evaluation', [$v2, $evaluation]))->assertSessionHas('status', __('changes.confirmed'));
        $this->assertFalse($evaluation->fresh()->recheck);
    }

    public function test_the_reports_carry_the_decision_and_the_signs(): void
    {
        $v2 = $this->copy();
        $this->item($v2, 'AS-1')->update(['attributes' => ['type' => 'personal', 'stored' => true]]);
        SignificantChange::for($v2)->decide($this->owner, SignificantChange::SIGNIFICANT, 'The contacts are personal data now.');

        $data = ReportData::for($v2->fresh(), $this->owner);
        $this->assertSame('1.0', $data->change['previous']);
        $this->assertSame(__('changes.decisions.significant'), $data->change['decision']);
        $this->assertSame(2, $data->change['rechecks']);
        $this->assertSame(__('changes.kind.item_changed'), $data->change['signs'][0]['kind']);
        $this->assertNull(ReportData::for($this->v1->fresh(), $this->owner)->change);

        $path = tempnam(sys_get_temp_dir(), 'rep').'.docx';
        (new WordReport($data, ReportKind::Assessment))->save($path);
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);
        $this->assertStringContainsString('Changes since 1.0', $xml);
        $this->assertStringContainsString('The contacts are personal data now.', $xml);

        $path = tempnam(sys_get_temp_dir(), 'rep').'.xlsx';
        (new ExcelReport($data, ReportKind::Assessment))->save($path);
        $this->assertContains(__('changes.report.sheet'), IOFactory::load($path)->getSheetNames());
        @unlink($path);
    }
}
