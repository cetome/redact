<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\VersionFactory;
use App\Declaration\TechnicalFile;
use App\Enums\ProductRole;
use App\Enums\VersionStatus;
use App\Models\AssessmentCaseResult;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Reports\ReportData;
use App\Support\WorkflowSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Where a version's assessment units are performed (owner, 2026-10-23): in REDact (the team records
 * them) or outside REDact (an independent test laboratory, its report in Documents), whatever the
 * conformity route. Outside REDact the units block neither submission nor approval, the step is done
 * "Performed externally", the forms are hidden (results recorded before stay, read-only) and Review,
 * the technical file and the reports say so. Plus the status action in the Review page header.
 */
class UnitsExternalTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private const JUSTIFICATION = 'Access needs the paired owner app, checked on the bench.';

    private User $owner;

    private ProductVersion $version;

    private InventoryItem $asset;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = $this->owner();
        $this->version = $this->product($this->owner);
        $this->version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $this->asset = $this->version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Door key', 'attributes' => ['type' => 'security']]);
        $this->signIn($this->owner)->put(route('versions.evaluate.update', [$this->version, 'demo-1', 'ACC-1']), [
            'target' => $this->asset->id, 'answers' => ['No', 'Yes'], 'justification' => self::JUSTIFICATION,
        ])->assertSessionHasNoErrors();
        $this->signIn($this->owner)->put(route('versions.evaluate.update', [$this->version, 'demo-1', 'UPD-1']), [
            'target' => 'product', 'answers' => ['Yes'], 'justification' => self::JUSTIFICATION,
        ])->assertSessionHasNoErrors();
    }

    public function test_the_choice_is_stored_by_the_people_who_provide_the_units(): void
    {
        $this->assertSame('/versions/'.$this->version->id.'/assessment-units/where', parse_url(route('versions.units.where', $this->version), PHP_URL_PATH));
        $contributor = $this->member($this->version->product, ProductRole::Contributor);
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);

        $page = $this->signIn($this->owner)->get(route('versions.units', $this->version))->assertOk()
            ->assertSee(__('assessment.where.redact'))->assertSee(__('assessment.where.external_help'))
            ->assertSee('action="'.route('versions.units.where', $this->version).'"', false)
            ->assertSee('data-case-form', false);
        $this->assertStringContainsString('value="redact" checked', (string) $page->getContent());

        $this->where($this->owner, 'nowhere')->assertSessionHasErrors('where');
        $this->where($reviewer, 'external')->assertNotFound();
        $this->assertFalse($this->version->fresh()->units_external);
        $this->signIn($reviewer)->get(route('versions.units', $this->version))->assertOk()->assertDontSee(__('assessment.where.legend'));

        $this->where($contributor, 'external')->assertRedirect(route('versions.units', $this->version))
            ->assertSessionHas('status', __('assessment.where.saved_external'));
        $this->assertTrue($this->version->fresh()->units_external);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'version', 'subject_id' => $this->version->id, 'event' => 'updated']);
        $this->where($this->owner, 'redact')->assertSessionHas('status', __('assessment.where.saved_redact'));
        $this->assertFalse($this->version->fresh()->units_external);

        // In review still (the units stay open), never once approved, never on the demo.
        $this->version->forceFill(['status' => VersionStatus::InReview])->save();
        $this->where($this->owner, 'external')->assertRedirect();
        $this->assertTrue($this->version->fresh()->units_external);
        $this->version->forceFill(['status' => VersionStatus::Approved])->save();
        $this->where($this->owner, 'redact')->assertNotFound();
        $this->version->forceFill(['status' => VersionStatus::Draft])->save();
        $this->version->product->forceFill(['is_demo' => true])->save();
        $this->where($this->owner, 'redact')->assertNotFound();
        $this->signIn($this->owner)->get(route('versions.units', $this->version))->assertOk()
            ->assertDontSee(__('assessment.where.legend'))->assertSee(__('assessment.external_line'));
        $this->assertTrue($this->version->fresh()->units_external);

        // A new version keeps the choice.
        $this->version->product->forceFill(['is_demo' => false])->save();
        $next = app(VersionFactory::class)->create($this->version->product, '2.0', $this->owner, $this->version->fresh());
        $this->assertTrue($next->units_external);
    }

    public function test_outside_redact_nothing_waits_for_the_units(): void
    {
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        // One unit recorded before the choice: kept and shown read-only, never deleted.
        $this->signIn($this->owner)->post(route('versions.units.record', $this->version), [
            'standard' => 'demo-1', 'requirement' => 'ACC-1', 'case' => 'conceptual', 'verdict' => 'PASS', 'evidence' => self::JUSTIFICATION,
        ])->assertRedirect();
        $this->where($this->owner, 'external')->assertRedirect();

        // The step: done, "Performed externally"; no form, no figures; the result recorded before, read-only.
        $step = collect(Assessment::for($this->version->fresh())->steps())->firstWhere('key', 'units');
        $this->assertTrue($step['done']);
        $this->assertSame(__('assessment.external_detail'), $step['detail']);
        $page = $this->signIn($this->owner)->get(route('versions.units', $this->version))->assertOk()
            ->assertSee(__('assessment.external_detail'))
            ->assertSee(__('assessment.where.recorded_before'))->assertSee(self::JUSTIFICATION)
            ->assertSee(route('versions.documents', $this->version), false)
            ->assertDontSee('data-case-form', false)->assertDontSee(__('assessment.stat_recorded'))
            ->assertDontSee(__('assessment.cases.functional_completeness'));
        $this->assertStringContainsString('value="external" checked', (string) $page->getContent());
        $this->signIn($this->owner)->post(route('versions.units.record', $this->version), [
            'standard' => 'demo-1', 'requirement' => 'UPD-1', 'case' => 'conceptual', 'verdict' => 'PASS', 'evidence' => self::JUSTIFICATION,
        ])->assertNotFound();
        $this->assertSame(1, AssessmentCaseResult::count());

        // Submitted without the units and without a warning about them.
        $this->signIn($this->owner)->post(route('versions.submit', $this->version))->assertRedirect()
            ->assertSessionMissing('error')->assertSessionMissing('warning');
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);

        // Review: one line instead of the units; approval does not look at them.
        $units = $this->signIn($reviewer)->get(route('versions.review.units', $this->version))->assertOk()
            ->assertSee(__('assessment.external_line'))->assertDontSee('data-cases-review', false);
        $this->assertSame(1, substr_count((string) $units->getContent(), e(__('assessment.external_line'))));
        $evaluations = $this->version->evaluations()->get();
        foreach ($evaluations as $evaluation) {
            $this->signIn($reviewer)->post(route('versions.review.evaluation', [$this->version, $evaluation]), ['verdict' => 'agree'])->assertRedirect();
        }
        $this->signIn($reviewer)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])
            ->assertSessionHas('status', __('review.decision_saved'));
        $this->assertSame(VersionStatus::Approved, $this->version->fresh()->status);

        // Technical file and reports: "performed externally (independent test laboratory)", no unit listed.
        $notes = collect(TechnicalFile::for($this->version->fresh())->items())->where('heading', 'h')->pluck('note');
        $this->assertContains(__('declaration.file.notes.review_external'), $notes);
        $data = ReportData::for($this->version->fresh(), $this->owner);
        $this->assertTrue($data->unitsExternal);
        $this->assertSame([], $data->cases);
        $xlsx = $this->download('review', 'xlsx');
        $rows = IOFactory::load($xlsx)->getSheetByName(__('assessment.report.sheet'))->toArray();
        @unlink($xlsx);
        $this->assertSame(['—', __('assessment.external_report')], array_slice($rows[1], 0, 2));
        $docx = $this->download('review', 'docx');
        $zip = new \ZipArchive;
        $zip->open($docx);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($docx);
        $this->assertStringContainsString(e(__('assessment.external_report')), $xml);
        $this->assertStringNotContainsString(self::JUSTIFICATION.'</w:t>', substr($xml, (int) strpos($xml, e(__('assessment.report.cases')))));
    }

    public function test_in_redact_approval_waits_for_the_units_outside_it_does_not(): void
    {
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $this->signIn($this->owner)->post(route('versions.submit', $this->version))->assertRedirect()->assertSessionHas('warning');
        foreach ($this->version->evaluations()->get() as $evaluation) {
            $this->signIn($reviewer)->post(route('versions.review.evaluation', [$this->version, $evaluation]), ['verdict' => 'agree'])->assertRedirect();
        }
        $this->signIn($reviewer)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])->assertSessionHas('error');
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);

        // "An independent test laboratory performs them", from the warning after submitting: then approved.
        $this->where($this->owner, 'external')->assertRedirect();
        $this->signIn($reviewer)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])->assertSessionMissing('error');
        $this->assertSame(VersionStatus::Approved, $this->version->fresh()->status);
    }

    public function test_the_warning_offers_outside_redact_as_a_post(): void
    {
        $this->member($this->version->product, ProductRole::Reviewer);
        $html = (string) $this->signIn($this->owner)->from(route('versions.review', $this->version))
            ->followingRedirects()->post(route('versions.submit', $this->version))->assertOk()->getContent();
        $this->assertStringContainsString(e(trans_choice('assessment.submitted_units_missing', 5, ['count' => 5])), $html);
        $this->assertMatchesRegularExpression('#<form method="POST" action="'.preg_quote(route('versions.units.where', $this->version), '#').'">\s*<input type="hidden" name="_token"[^>]*>\s*<input type="hidden" name="where" value="external">#', $html);
        $this->assertStringContainsString(e(__('assessment.where.lab_action')), $html);
    }

    public function test_the_review_page_header_carries_the_status_action(): void
    {
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $submit = 'action="'.route('versions.submit', $this->version).'"';
        $withdraw = 'action="'.route('versions.withdraw', $this->version).'"';
        $reopen = 'action="'.route('versions.reopen', $this->version).'"';

        // Draft: "Submit for review" once, in the header (owner); nothing for a reviewer.
        $html = $this->header($this->signIn($this->owner)->get(route('versions.review', $this->version))->assertOk());
        $this->assertStringContainsString($submit, $html);
        $this->assertSame(1, substr_count((string) $this->signIn($this->owner)->get(route('versions.review', $this->version))->getContent(), $submit));
        $this->assertStringNotContainsString($submit, (string) $this->signIn($reviewer)->get(route('versions.review', $this->version))->getContent());

        // Submitted from the Review page: back on the Review page.
        $this->signIn($this->owner)->from(route('versions.review', $this->version))->post(route('versions.submit', $this->version))
            ->assertRedirect(route('versions.review', $this->version));
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);

        // In review: "Withdraw from review" for the owner, nothing for the reviewer.
        $html = $this->header($this->signIn($this->owner)->get(route('versions.review', $this->version))->assertOk());
        $this->assertStringContainsString($withdraw, $html);
        $this->assertStringNotContainsString($submit, $html);
        $page = (string) $this->signIn($reviewer)->get(route('versions.review.units', $this->version))->getContent();
        $this->assertStringNotContainsString($withdraw, $page);
        $this->assertStringNotContainsString($submit, $page);
        $this->signIn($this->owner)->from(route('versions.review', $this->version))->post(route('versions.withdraw', $this->version))
            ->assertRedirect(route('versions.review', $this->version));
        $this->assertSame(VersionStatus::Draft, $this->version->fresh()->status);

        // Approved: "Reopen as a draft", as on the Overview.
        $this->version->forceFill(['status' => VersionStatus::Approved])->save();
        $this->assertStringContainsString($reopen, $this->header($this->signIn($this->owner)->get(route('versions.review', $this->version))));
        $this->assertStringContainsString($reopen, (string) $this->signIn($this->owner)->get(route('versions.show', $this->version))->getContent());

        // The demo (read-only): none.
        $this->version->forceFill(['status' => VersionStatus::Draft])->save();
        $this->version->product->forceFill(['is_demo' => true])->save();
        $this->assertStringNotContainsString($submit, (string) $this->signIn($this->owner)->get(route('versions.review', $this->version))->assertOk()->getContent());
    }

    public function test_the_sidebar_shows_performed_externally(): void
    {
        $this->version->forceFill(['units_external' => true])->save();
        $group = collect(WorkflowSummary::for($this->version->fresh())->groups)->firstWhere('key', 'units');
        $this->assertTrue($group['done']);
        $this->assertNull($group['total']);
        $this->signIn($this->owner)->get(route('versions.show', $this->version))->assertOk()->assertSee(__('assessment.external_detail'));
    }

    private function where(User $user, string $where): TestResponse
    {
        return $this->signIn($user)->from(route('versions.units', $this->version))->post(route('versions.units.where', $this->version), ['where' => $where]);
    }

    /** The page header of the response (title block and its actions). */
    private function header(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('#<header class="a-page-head">.*?</header>#s', (string) $response->getContent(), $m));

        return $m[0];
    }

    private function download(string $kind, string $format): string
    {
        $response = $this->signIn($this->owner->refresh())->get(route('reports.download', ['version' => $this->version, 'kind' => $kind, 'format' => $format]))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'redact-test-').'.'.$format;
        copy($response->baseResponse->getFile()->getPathname(), $path);

        return $path;
    }
}
