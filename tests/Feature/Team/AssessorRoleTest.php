<?php

namespace Tests\Feature\Team;

use App\Assessment\ReviewRights;
use App\Billing\Plan;
use App\Enums\ProductRole;
use App\Enums\VersionStatus;
use App\Mail\ProductInvitation;
use App\Models\AssessmentCaseResult;
use App\Models\AssessmentCaseReview;
use App\Models\Document;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Support\ProductAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * The Assessor role (owner, 2026-10-23: "We could also ask an EXTERNAL to complete them"): an
 * external person (e.g. a test laboratory engineer) invited from Team like a reviewer, who sees the
 * product read-only (never its history) and records the assessment units in REDact on a draft or
 * in review, nothing else. Their results are "Independent" with their name; an assessor is never a
 * reviewer (self-review and approval unchanged) and takes a team seat like anyone.
 */
class AssessorRoleTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private const EVIDENCE = 'Bench test on two samples: access refused without the paired app.';

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
            'target' => $this->asset->id, 'answers' => ['No', 'Yes'], 'justification' => 'Access needs the paired owner app, checked on the bench.',
        ])->assertSessionHasNoErrors();
    }

    public function test_an_assessor_is_invited_from_team_and_accepts(): void
    {
        $product = $this->version->product;
        $this->assertSame('/products/'.$product->id.'/team/assessor', parse_url(route('team.assessor', $product), PHP_URL_PATH));

        // The invite panel starts with the Assessor role, its one-line description beside it.
        $page = (string) $this->signIn($this->owner)->get(route('team.assessor', $product))->assertOk()
            ->assertSee(__('roles.assessor.label'))->assertSee(__('roles.assessor.help'))->getContent();
        $this->assertStringContainsString('value="assessor" checked', $page);
        $this->assertStringNotContainsString('value="reviewer" checked', $page);

        $lab = User::factory()->create(['email' => 'lab@emotec.example', 'name' => 'Lea Lab']);
        $this->signIn($this->owner)->post(route('team.invite', $product), ['email' => 'lab@emotec.example', 'role' => 'assessor'])
            ->assertSessionHas('status', __('team.invite_sent'));
        $token = null;
        Mail::assertQueued(ProductInvitation::class, function ($mail) use (&$token) {
            $token = $mail->token;

            return true;
        });
        $this->signIn($lab)->get(route('invitations.show', $token))->assertOk()->assertSee(__('roles.assessor.help'));
        $this->signIn($lab)->post(route('invitations.accept', $token))->assertRedirect();
        $this->assertSame(ProductRole::Assessor, $product->roleOf($lab));
        $member = $product->members()->where('user_id', $lab->id)->firstOrFail();
        $this->assertTrue($member->external);

        // The role is one of the team's: shown, changed and logged like the others.
        $this->signIn($this->owner)->get(route('team.index', $product))->assertOk()
            ->assertSee('<option value="assessor" selected>', false);
        $this->signIn($this->owner)->patch(route('team.update', [$product, $member]), ['role' => 'contributor'])->assertSessionHas('status');
        $this->signIn($this->owner)->patch(route('team.update', [$product, $member]), ['role' => 'assessor'])->assertSessionHas('status');
        $this->signIn($this->owner)->get(route('products.history', $product))->assertOk()
            ->assertSeeText('Invited lab@emotec.example as Assessor')
            ->assertSeeText('Changed the role of Lea Lab: Contributor → Assessor');

        // The previous owner of a transfer never becomes an assessor.
        $this->signIn($this->owner)->post(route('team.transfer', $product), ['owner_id' => $lab->id, 'previous' => 'assessor'])
            ->assertSessionHasErrors('previous');
    }

    public function test_an_assessor_records_units_in_draft_and_in_review_independent_with_their_name(): void
    {
        $lab = $this->member($this->version->product, ProductRole::Assessor);
        $lab->forceFill(['name' => 'Lea Lab'])->save();
        $report = Document::create(['product_version_id' => $this->version->id, 'uploaded_by' => $this->owner->id, 'original_name' => 'emotec-lab-report.pdf',
            'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('a', 64), 'path' => 'documents/x.pdf']);

        // The step: the forms, an existing document to pick; no "where", no invite, no upload.
        $main = $this->main($this->signIn($lab)->get(route('versions.units', $this->version))->assertOk());
        $this->assertStringContainsString('data-case-form', $main);
        $this->assertStringContainsString('emotec-lab-report.pdf', $main);
        $this->assertStringNotContainsString(__('assessment.where.legend'), $main);
        $this->assertStringNotContainsString(__('assessment.invite_assessor'), $main);
        $this->assertStringNotContainsString(__('assessment.add_document'), $main);
        $this->assertStringNotContainsString(__('assessment.read_only'), $main);

        $this->record($lab, 'conceptual', $report->id)->assertRedirect()->assertSessionHasNoErrors();
        $result = AssessmentCaseResult::where('case_key', 'conceptual')->firstOrFail();
        $this->assertSame([true, $lab->id, $report->id], [$result->independent, $result->recorded_by, $result->document_id]);
        // The team's results stay "Not independent".
        $this->record($this->owner, 'functional_completeness')->assertRedirect();
        $this->assertFalse(AssessmentCaseResult::where('case_key', 'functional_completeness')->firstOrFail()->independent);

        // A document of another version is refused; the assessor never chooses where the units are performed.
        $this->record($lab, 'functional_sufficiency', 'not-a-document')->assertSessionHasErrorsIn('case-demo-1-ACC-1-functional_sufficiency', ['document']);
        $this->signIn($lab)->post(route('versions.units.where', $this->version), ['where' => 'external'])->assertNotFound();

        // Everyone reads it "Independent" with the assessor's name.
        foreach ([$lab, $this->owner] as $reader) {
            $page = $this->main($this->signIn($reader)->get(route('versions.units', $this->version))->assertOk());
            $this->assertStringContainsString(__('assessment.independent'), $page);
            $this->assertStringContainsString('Lea Lab', $page);
            $this->assertStringContainsString(__('assessment.self_short'), $page);
        }

        // In review: still recorded, and a recording makes the earlier unit reviews stale.
        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $this->provideUnits($this->version);
        $this->signIn($this->owner)->post(route('versions.submit', $this->version))->assertRedirect();
        $this->assertSame(VersionStatus::InReview, $this->version->fresh()->status);
        $this->signIn($reviewer)->post(route('versions.review.unit', [$this->version, $result]), ['verdict' => 'agree'])->assertRedirect();
        $review = AssessmentCaseReview::firstOrFail();
        $this->assertFalse($review->isStale());
        $this->travel(1)->minutes();
        $this->signIn($lab)->get(route('versions.units', $this->version))->assertOk()->assertSee('data-case-form', false)
            ->assertDontSee(__('versions.locked', ['status' => mb_strtolower(VersionStatus::InReview->label())]));
        $this->signIn($lab)->post(route('versions.units.record', $this->version), [
            'standard' => 'demo-1', 'requirement' => 'ACC-1', 'case' => 'conceptual', 'verdict' => 'PASS',
            'evidence' => 'Repeated on a third sample after the firmware update: same result.',
        ])->assertRedirect();
        $this->assertTrue($review->fresh()->isStale());
        $this->assertTrue($result->fresh()->independent);

        // History: the owner reads the assessor's record; the assessor never reads the history.
        $this->signIn($this->owner)->get(route('products.history', $this->version->product))->assertOk()->assertSee('Lea Lab');
        $this->signIn($lab)->get(route('products.history', $this->version->product))->assertNotFound();

        // Approved: nothing more.
        $this->version->forceFill(['status' => VersionStatus::Approved])->save();
        $this->record($lab, 'conceptual')->assertNotFound();
        $this->signIn($lab)->get(route('versions.units', $this->version))->assertOk()->assertDontSee('data-case-form', false);
    }

    public function test_an_assessor_changes_nothing_else(): void
    {
        $lab = $this->member($this->version->product, ProductRole::Assessor);
        $product = $this->version->product;
        $v = $this->version;
        $access = ProductAccess::for($lab, $product);
        $this->assertTrue($access->canView());
        $this->assertFalse($access->canEdit($v) || $access->canManage() || $access->canReview() || $access->canUseAi($v) || $access->canSeeHistory() || $access->canDelete());

        $writes = [
            ['put', route('versions.evaluate.update', [$v, 'demo-1', 'ACC-1']), ['target' => $this->asset->id, 'answers' => ['Yes'], 'justification' => 'Changed by the assessor, never allowed.']],
            ['put', route('versions.einfo.update', [$v, 'demo-1']), ['entries' => [$this->asset->id => ['E.Info.ACC-1.Asset' => 'Changed']]]],
            ['post', route('versions.risks.store', $v), ['title' => 'Assessor risk']],
            ['post', route('versions.inventory.store', [$v, 'assets']), ['code' => 'AS-02', 'name' => 'Lens']],
            ['put', route('versions.inventory.update', [$v, 'assets', $this->asset]), ['name' => 'Renamed']],
            ['put', route('versions.scoping.update', $v), ['answers' => []]],
            ['put', route('products.update', $product), ['name' => 'Renamed']],
            ['post', route('versions.documents.store', $v), []],
            ['post', route('versions.import.store', $v), []],
            ['post', route('ai.draft', $v), []],
            ['post', route('versions.submit', $v), []],
            ['post', route('versions.units.where', $v), ['where' => 'external']],
            ['post', route('team.invite', $product), ['email' => 'x@emotec.example', 'role' => 'reviewer']],
        ];
        foreach ($writes as [$method, $url, $data]) {
            $status = $this->signIn($lab)->{$method}($url, $data)->getStatusCode();
            $this->assertContains($status, [403, 404], strtoupper($method).' '.$url.' answered '.$status);
        }
        $this->assertSame('Smart lock', $product->fresh()->name);
        $this->assertSame(1, $v->inventoryItems()->count());

        // Pages read-only like a reviewer's (forms in a disabled fieldset at most): nothing to submit.
        $pages = [
            route('versions.evaluate.requirement', [$v, 'demo-1', 'ACC-1']), route('versions.einfo', [$v, 'demo-1']), route('versions.risks', $v),
            route('versions.inventory', [$v, 'assets']), route('versions.documents', $v),
        ];
        foreach ($pages as $url) {
            // The E.Info item picker only navigates (POST to a path, no query string).
            $main = str_replace('type="submit" class="a-btn a-btn-outline a-einfo-pick-go"', '', $this->main($this->signIn($lab)->get($url)->assertOk()));
            $this->assertStringNotContainsString('type="submit"', $main, $url);
        }
        $this->assertStringNotContainsString('action="'.route('versions.documents.store', $v).'"', $this->main($this->signIn($lab)->get(route('versions.documents', $v))));
        $overview = $this->main($this->signIn($lab)->get(route('versions.show', $v))->assertOk());
        $this->assertStringNotContainsString(route('versions.submit', $v), $overview);
        $this->signIn($lab)->get(route('products.edit', $product))->assertNotFound();
        $this->signIn($lab)->get(route('products.history', $product))->assertNotFound();
        $team = $this->signIn($lab)->get(route('team.index', $product))->assertOk();
        $team->assertDontSee('action="'.route('team.invite', $product).'"', false);
        $this->assertStringNotContainsString(route('products.history', $product), (string) $this->signIn($lab)->get(route('versions.show', $v))->getContent());

        // In review: no review, no decision, no withdrawal.
        $this->provideUnits($v);
        $this->signIn($this->owner)->post(route('versions.submit', $v))->assertRedirect();
        $result = AssessmentCaseResult::firstOrFail();
        $this->signIn($lab)->post(route('versions.review.unit', [$v, $result]), ['verdict' => 'agree'])->assertNotFound();
        $this->signIn($lab)->post(route('versions.review.decide', $v), ['verdict' => 'approved'])->assertNotFound();
        $this->assertContains($this->signIn($lab)->post(route('versions.withdraw', $v))->getStatusCode(), [403, 404]);
        $review = $this->main($this->signIn($lab)->get(route('versions.review', $v))->assertOk());
        $this->assertStringNotContainsString('data-review-form', $review);
        $this->assertSame(VersionStatus::InReview, $v->fresh()->status);
    }

    public function test_an_assessor_is_never_a_reviewer(): void
    {
        $product = $this->version->product;
        $lab = $this->member($product, ProductRole::Assessor);

        // Only an assessor on the team: the owner still self-reviews, the assessor never reviews.
        $this->assertFalse(ReviewRights::hasReviewer($product));
        $this->assertTrue(ReviewRights::for($this->owner, $this->version)->canReview());
        $this->assertFalse(ReviewRights::for($lab, $this->version)->canReview());
        $this->provideUnits($this->version);
        $this->signIn($this->owner)->post(route('versions.submit', $this->version))
            ->assertRedirect(route('versions.review', $this->version))->assertSessionHas('status', __('review.submitted_self'));
        Mail::assertNothingQueued();
        $this->signIn($this->owner)->post(route('versions.review.decide', $this->version), ['verdict' => 'approved'])->assertRedirect();
        $this->assertSame(VersionStatus::Approved, $this->version->fresh()->status);
        $this->assertTrue(ReviewRights::selfApproved($this->version->fresh()));
    }

    public function test_an_assessor_takes_a_team_seat_like_a_reviewer(): void
    {
        config(['redact.plans.free.team_members' => 1]);
        $product = $this->version->product;
        $product->forceFill(['plan_slot' => Plan::SLOT_FREE])->save();

        $this->signIn($this->owner)->post(route('team.invite', $product), ['email' => 'lab@emotec.example', 'role' => 'assessor'])
            ->assertSessionHas('status', __('team.invite_sent'));
        $this->assertSame(1, Plan::teamCount($product));
        $this->signIn($this->owner)->post(route('team.invite', $product), ['email' => 'rev@emotec.example', 'role' => 'reviewer'])
            ->assertSessionHas('error');
        // The free team is full: no invitation form.
        $this->signIn($this->owner)->get(route('team.index', $product))->assertOk()->assertDontSee(__('team.send'));
    }

    public function test_the_units_step_offers_to_invite_an_assessor_to_the_people_who_manage_the_team(): void
    {
        $product = $this->version->product;
        $contributor = $this->member($product, ProductRole::Contributor, internal: true);
        $link = 'href="'.route('team.assessor', $product).'"';

        $this->signIn($this->owner)->get(route('versions.units', $this->version))->assertOk()
            ->assertSee($link, false)->assertSee(__('assessment.invite_assessor'));
        $this->signIn($contributor)->get(route('versions.units', $this->version))->assertOk()->assertDontSee($link, false);
        // Outside REDact there is nothing to record here.
        $this->version->forceFill(['units_external' => true])->save();
        $this->signIn($this->owner)->get(route('versions.units', $this->version))->assertOk()->assertDontSee($link, false);
        $this->version->forceFill(['units_external' => false])->save();

        // The demo stays read-only: no invitation from it.
        $product->forceFill(['is_demo' => true])->save();
        $this->signIn($this->owner)->get(route('versions.units', $this->version))->assertOk()->assertDontSee($link, false);
        $this->signIn($this->owner)->post(route('team.invite', $product), ['email' => 'lab@emotec.example', 'role' => 'assessor'])->assertNotFound();
        Mail::assertNothingQueued();
    }

    private function record(User $user, string $case, ?string $document = null): TestResponse
    {
        return $this->signIn($user)->from(route('versions.units', $this->version))->post(route('versions.units.record', $this->version), [
            'standard' => 'demo-1', 'requirement' => 'ACC-1', 'case' => $case, 'verdict' => 'PASS', 'evidence' => self::EVIDENCE,
        ] + ($document === null ? [] : ['document' => $document]));
    }

    /** The page's own content: the sidebar of steps (navigation) left out. */
    private function main(TestResponse $response): string
    {
        $html = (string) $response->getContent();
        $this->assertSame(1, preg_match('#<main id="main".*?</main>#s', $html, $m));

        return $m[0];
    }
}
