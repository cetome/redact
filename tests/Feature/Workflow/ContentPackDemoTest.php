<?php

namespace Tests\Feature\Workflow;

use App\Assessment\ArticlesCheck;
use App\Assessment\Assessment;
use App\Assessment\AssessmentCases;
use App\Billing\Plan;
use App\Content\ContentPack;
use App\Declaration\DeclarationData;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Guest\ClaimSandbox;
use App\Guest\DemoProduct;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Guest\GuestHelpers;
use Tests\TestCase;

/**
 * "See a demo" with the cetome pack: the demo product (demo/smart-bulb.json, a Wi-Fi smart LED bulb in
 * scope of article 3.3(d), EN 18031-1) loads into a fresh sandbox and is complete the way a test lab
 * accepts it: every step done except the review (and the declaration, which waits for approval), every
 * walk justified, no FAIL or INCONCLUSIVE, E.Info complete, Module A possible, the risk step done; its
 * screens, Word and Excel reports, workbooks and declaration render.
 * Skipped unless REDACT_TEST_PACK points to the pack:
 *   $env:REDACT_TEST_PACK="..\redact-content"; php artisan test --filter=ContentPackDemo
 */
class ContentPackDemoTest extends TestCase
{
    use GuestHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $path = getenv('REDACT_TEST_PACK') ?: null;
        if (! $path) {
            $this->markTestSkipped('Set REDACT_TEST_PACK to a content pack to run this test.');
        }
        config(['redact.content_path' => realpath($path) ?: $path]);
        $this->app->forgetInstance(ContentPack::class);
        if (app(ContentPack::class)->id() !== 'cetome-redact' || ! app(DemoProduct::class)->available()) {
            $this->markTestSkipped('The demo product is the cetome REDact pack\'s.');
        }
        $this->setUpGuest();
        Storage::fake('local');
    }

    public function test_the_demo_is_complete_the_way_a_lab_accepts_it(): void
    {
        $this->post(route('guest.demo'), ['altcha' => 'ok'])->assertRedirect();
        $guest = User::query()->where('is_guest', true)->sole();
        $product = Product::query()->where('owner_id', $guest->id)->sole();
        $version = $product->versions()->sole();
        $assessment = Assessment::for($version);

        $this->assertSame('Smart LED bulb (demo)', $product->name);
        $this->assertSame('EM-B10', $product->reference);
        $this->assertSame(['EM-B10 E27', 'EM-B10 B22'], $product->models()->pluck('value')->all());
        $this->assertNotNull($product->image_path);
        $this->assertSame(['en18031-1'], $assessment->standards());
        $this->assertSame(VersionStatus::Draft, $version->status);

        // Every step done but the review (no reviewer in a sandbox) and the declaration (after approval).
        foreach ($assessment->steps() as $step) {
            $this->assertSame(! in_array($step['key'], ['review', 'declaration'], true), $step['done'], $step['key'].': '.$step['detail']);
        }
        $this->assertSame([], $assessment->unjustified());
        $this->assertSame([], $assessment->inconclusive());
        $progress = $assessment->evaluationProgress('en18031-1');
        $this->assertGreaterThan(0, $progress['total']);
        $this->assertSame($progress['total'], $progress['done']);
        $this->assertSame(0, $progress['fail'] + $progress['inconclusive'] + $progress['follows'] + $progress['unlinked'] + $progress['invalid']);
        $this->assertSame($progress['total'], $progress['pass'] + $progress['na']);
        $einfo = $assessment->einfoProgress('en18031-1');
        $this->assertGreaterThan(0, $einfo['total']);
        $this->assertSame($einfo['total'], $einfo['done']);

        // Assessment units: every unit of the standard provided, PASS or NOT APPLICABLE, with its evidence.
        $units = AssessmentCases::for($assessment)->units();
        $this->assertGreaterThan(0, count($units));
        foreach ($units as $unit) {
            $this->assertNotNull($unit['result'], $unit['requirement'].' '.$unit['case']);
            $this->assertContains($unit['result']->verdict, [Verdict::Pass, Verdict::NotApplicable], $unit['requirement'].' '.$unit['case']);
            $this->assertGreaterThanOrEqual(20, mb_strlen($unit['result']->evidence));
        }

        // Risks: each one complete and on the article in scope; no mismatch with the articles.
        $this->assertSame([], (new ArticlesCheck($assessment))->mismatches());
        foreach ($version->riskEntries as $risk) {
            $this->assertSame(['3.3d'], $risk->articles);
            $this->assertNotNull($risk->residual_rank);
            $this->assertNotEmpty($risk->remediation);
        }

        // Conformity: Module A, chosen, with the restriction answers the data suggests.
        $conformity = $assessment->conformity();
        $this->assertTrue($conformity->selfAssessmentPossible());
        $this->assertFalse($conformity->conflict());
        $this->assertSame('module_a', $version->conformity_route->value);
        $saved = $version->scopingAnswers()->pluck('value', 'question')->all();
        foreach ($conformity->suggestions() as $question => $suggestion) {
            if (! $suggestion['settled'] && $suggestion['value'] !== null) {
                $this->assertSame($suggestion['value'], $saved[$question] ?? null, $question);
            }
        }

        // Declaration: every field filled, only the approval open.
        $declaration = new DeclarationData($assessment);
        $this->assertSame([], $declaration->missing());
        $this->assertSame(['not_approved'], array_column($declaration->openItems(), 'key'));
        $this->assertSame('Emotec', $declaration->manufacturerName());

        // The screens of the sandbox.
        foreach ([
            route('versions.show', $version), route('versions.edit', $version), route('versions.scoping', $version),
            route('versions.risks', $version), route('versions.evaluate', [$version, 'en18031-1']),
            route('versions.einfo', [$version, 'en18031-1']), route('versions.units', $version), route('versions.conformity', $version),
            route('versions.review', $version), route('versions.review.units', $version), route('versions.declaration', $version),
        ] as $url) {
            $this->assertStringNotContainsString('?', $url);
            $this->get($url)->assertOk();
        }
        foreach ($assessment->inventoryGuide()->listKeys() as $list) {
            $this->get(route('versions.inventory', [$version, $list]))->assertOk();
        }
        foreach (array_keys($assessment->applicableRequirements('en18031-1')) as $code) {
            $this->get(route('versions.evaluate.requirement', [$version, 'en18031-1', $code]))->assertOk();
        }
        $this->get(route('versions.declaration', $version))->assertOk()->assertSee('Emotec')->assertSee('Camille Martin');

        // Downloads need an account: kept, the same product renders its reports, workbooks and declaration.
        $account = app(ClaimSandbox::class)->convert($guest, ['name' => 'Grace', 'email' => 'grace@example.test', 'organisation' => '', 'product_news' => false]);
        $this->signedIn($account);
        foreach ([['assessment', 'docx'], ['assessment', 'xlsx'], ['trees', 'docx'], ['trees', 'xlsx'], ['review', 'docx'], ['review', 'xlsx']] as [$kind, $format]) {
            $this->get(route('reports.download', ['version' => $version, 'kind' => $kind, 'format' => $format]))->assertOk();
        }
        $this->get(route('versions.workbooks.zip', $version))->assertOk();
        $this->get(route('versions.declaration.download', [$version, 'docx']))->assertOk();
        $this->get(route('versions.declaration.download', [$version, 'pdf']))->assertOk();
    }

    public function test_the_demo_reads_in_french(): void
    {
        $this->withHeader('Accept-Language', 'fr')->post(route('guest.demo'), ['altcha' => 'ok'])->assertRedirect();
        $guest = User::query()->where('is_guest', true)->sole();
        $version = Product::query()->where('owner_id', $guest->id)->sole()->versions()->sole();

        $this->assertSame('Ampoule LED connectée (démo)', $version->product->name);
        $this->assertSame('Identifiants Wi-Fi', $version->inventoryItems()->where('code', 'AS-01')->value('name'));
        $this->assertSame([], Assessment::for($version)->unjustified());
        $this->get(route('versions.show', $version))->assertOk()->assertSee('Ampoule LED connectée (démo)');
        $this->get(route('versions.units', $version))->assertOk()->assertSee('Les quatre chemins ACM-1 (AS-01 à AS-04) aboutissent à PASS');
    }

    public function test_a_free_account_loads_the_read_only_demo_outside_its_plan(): void
    {
        $user = User::factory()->create();
        $organisation = Organisation::create(['name' => 'Free Org', 'created_by' => $user->id]);
        $organisation->members()->attach($user->id, ['role' => 'admin']);
        $organisation->forceFill(['legal_name' => 'Free Org SAS', 'address' => '3 rue Réelle'])->save();

        $this->signedIn($user)->post(route('demo.load'))->assertRedirect();
        $product = Product::query()->where('organisation_id', $organisation->id)->sole();
        $version = $product->versions()->sole();
        $assessment = Assessment::for($version);

        $this->assertTrue($product->is_demo);
        $this->assertSame(Plan::SLOT_DEMO, $product->plan_slot);
        $this->assertSame(0, Plan::fresh($organisation)->productCount());
        $this->assertSame(Plan::SLOT_FREE, Plan::fresh($organisation)->nextSlot());
        $this->assertSame([], $assessment->unjustified());
        $this->assertSame($assessment->evaluationProgress('en18031-1')['total'], $assessment->evaluationProgress('en18031-1')['done']);
        $this->assertTrue($assessment->conformity()->selfAssessmentPossible());

        $declaration = new DeclarationData($assessment);
        $this->assertSame('Emotec', $declaration->manufacturerName());
        $this->assertSame([], $declaration->missing());
        $this->assertSame(['demo', 'not_approved'], array_column($declaration->openItems(), 'key'));
        $this->assertSame('Free Org SAS', $organisation->refresh()->legal_name);

        foreach ([route('versions.show', $version), route('versions.evaluate', [$version, 'en18031-1']), route('versions.einfo', [$version, 'en18031-1'])] as $url) {
            $this->get($url)->assertOk()->assertSee(__('products.demo.read_only'));
        }
        $this->get(route('versions.declaration', $version))->assertOk()->assertSee('Emotec')->assertDontSee('Free Org SAS');
        foreach ([['assessment', 'docx'], ['assessment', 'xlsx'], ['trees', 'docx']] as [$kind, $format]) {
            $this->get(route('reports.download', ['version' => $version, 'kind' => $kind, 'format' => $format]))->assertOk();
        }
        $this->get(route('versions.workbooks.zip', $version))->assertOk();
        $this->get(route('versions.declaration.download', [$version, 'docx']))->assertOk();
        $this->get(route('versions.declaration.download', [$version, 'pdf']))->assertOk();
        $this->get(route('versions.technical-file.download', $version))->assertOk();
    }
}
