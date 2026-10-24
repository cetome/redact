<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\Conformity;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use App\Reports\ReportData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Walks settled by another walk (PackRules::followsFrom), e.g. SCM-2…4 where SCM-1 ends FAIL:
 * not walked, shown as "FAIL · follows from SCM-1" on the decision-tree index, on the walk page and
 * in the reports, counted once apart, never as separate FAILs. The conformity fails through the
 * source walk already.
 * Fixture pack: UPD-1 (product level) has "follows_from": ACC-1 FAIL; ACC-1 is DN-1 (Yes → NOT
 * APPLICABLE, No → DN-2), DN-2 (Yes → PASS, No → FAIL).
 */
class FollowsFromTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private User $owner;

    private ProductVersion $version;

    private InventoryItem $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->owner();
        $this->version = $this->product($this->owner);
        $this->version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $this->asset = $this->version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Door key', 'attributes' => ['type' => 'security']]);
    }

    private function walkAcc(array $answers): void
    {
        $this->signIn($this->owner)->put(route('versions.evaluate.update', [$this->version, 'demo-1', 'ACC-1']), [
            'target' => $this->asset->id, 'answers' => $answers, 'justification' => 'Access to the key is checked by the lock firmware.',
        ])->assertRedirect();
    }

    public function test_nothing_follows_until_the_source_walk_fails(): void
    {
        $this->assertSame([], Assessment::for($this->version)->followsFrom('demo-1', 'UPD-1'));
        $this->walkAcc(['No', 'Yes']);
        $this->assertSame([], Assessment::for($this->version->fresh())->followsFrom('demo-1', 'UPD-1'));
        $this->assertSame(0, Assessment::for($this->version->fresh())->evaluationProgress('demo-1')['follows']);
    }

    public function test_a_failing_source_walk_is_counted_once(): void
    {
        $this->walkAcc(['No', 'No']);
        $assessment = Assessment::for($this->version->fresh());

        $follows = $assessment->followsFrom('demo-1', 'UPD-1');
        $this->assertCount(1, $follows);
        $this->assertSame(['requirement' => 'ACC-1', 'state' => 'fail'], ['requirement' => $follows[0]['requirement'], 'state' => $follows[0]['state']]);
        $this->assertSame($this->asset->id, $follows[0]['item']->id);

        // ACC-1's FAIL counts; the FAIL that follows from it is apart, never a second FAIL nor a walk.
        $progress = $assessment->evaluationProgress('demo-1');
        $this->assertSame(1, $progress['fail']);
        $this->assertSame(1, $progress['follows']);
        $this->assertSame(2, $progress['total']);
        $conformity = new Conformity($assessment);
        $this->assertSame(1, $conformity->failCount());
        $this->assertFalse($conformity->en18031Fully());
    }

    public function test_the_index_and_the_walk_page_show_it(): void
    {
        $this->walkAcc(['No', 'No']);

        $this->signIn($this->owner)->get(route('versions.evaluate', [$this->version, 'demo-1']))->assertOk()
            ->assertSee('FAIL · follows from ACC-1')
            ->assertSee('Follow from another requirement')
            ->assertSee('a-verdict-follows', false);

        $source = route('versions.evaluate.target', [$this->version, 'demo-1', 'ACC-1', $this->asset->id]);
        $this->get(route('versions.evaluate.requirement', [$this->version, 'demo-1', 'UPD-1']))->assertOk()
            ->assertSee('Not evaluated: follow from ACC-1')
            ->assertSee('href="'.$source.'"', false)
            ->assertSee('follows from ACC-1')
            ->assertSee('AS-01');

        // French.
        app()->setLocale('fr');
        $this->assertSame('FAIL · découle de ACC-1', __('evaluations.follows.badge', ['code' => 'ACC-1']));
    }

    public function test_the_reports_list_it_apart(): void
    {
        $this->walkAcc(['No', 'No']);

        $report = ReportData::for($this->version->fresh(), $this->owner);
        $standard = $report->standards[0];
        $this->assertSame(1, $standard['follows']);
        $this->assertSame(1, $report->totals()['fail']);
        $upd = collect($standard['requirements'])->firstWhere('code', 'UPD-1');
        $this->assertSame([['object' => $this->asset->label(), 'source' => 'ACC-1', 'state' => 'fail']], $upd['follows']);

        $this->signIn($this->owner)->get(route('reports.download', ['version' => $this->version, 'kind' => 'trees', 'format' => 'xlsx']))->assertOk();
        $this->get(route('reports.download', ['version' => $this->version, 'kind' => 'trees', 'format' => 'docx']))->assertOk();
    }
}
