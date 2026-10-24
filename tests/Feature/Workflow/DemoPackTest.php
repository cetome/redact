<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\Conformity;
use App\Content\ContentPack;
use App\Content\Tree;
use App\Models\InventoryItem;

/**
 * The open demo pack (packs/demo) runs the whole workflow (ContentPackSmokeTest, always run on
 * it), is internally consistent, and its two demo rules behave as documented.
 */
class DemoPackTest extends ContentPackSmokeTest
{
    protected function setUp(): void
    {
        $previous = getenv('REDACT_TEST_PACK');
        putenv('REDACT_TEST_PACK='.dirname(__DIR__, 3).'/packs/demo');
        try {
            parent::setUp();
        } finally {
            putenv($previous === false ? 'REDACT_TEST_PACK' : 'REDACT_TEST_PACK='.$previous);
        }
    }

    public function test_the_demo_pack_is_consistent(): void
    {
        $pack = app(ContentPack::class);
        $this->assertSame('demo', $pack->id());
        $standards = array_keys($pack->standards());
        $lists = array_keys($pack->lists());

        foreach ($pack->requirements() as $code => $requirement) {
            $this->assertArrayHasKey($requirement['family'], $pack->families(), $code);
            $this->assertTrue($requirement['list'] === null || in_array($requirement['list'], $lists, true), $code);
            foreach ($requirement['standards'] as $standard) {
                $this->assertContains($standard, $standards, $code);
                $tree = $pack->tree($standard, $code);
                $this->assertInstanceOf(Tree::class, $tree, "{$standard}/{$code} has a tree");
                $this->assertSame([$standard, $code], [$tree->standard(), $tree->requirement()]);
                $this->assertTrue($tree->has($tree->start()));
                foreach ($tree->decisionNodes() as $node) {
                    foreach ($tree->node($node)['branches'] as $branch) {
                        $this->assertTrue($tree->has($branch['to']), "{$standard}/{$code} {$node} -> {$branch['to']}");
                    }
                }
            }
        }

        foreach ($pack->einfo() as $code => $einfo) {
            $this->assertNotNull($pack->requirement($einfo['requirement']), $code);
            $this->assertTrue($einfo['list'] === null || in_array($einfo['list'], $lists, true), $code);
        }

        foreach ($lists as $list) {
            $fields = collect($pack->lists()[$list]['fields'] ?? [])->keyBy('key');
            foreach ($pack->catalogue($list) as $item) {
                foreach (array_keys($item['attributes'] ?? []) as $key) {
                    $this->assertTrue($fields->has($key), "catalogue {$list}: unknown field {$key}");
                }
                $this->assertNotEmpty($item['source'] ?? null);
            }
        }
    }

    public function test_dat_1_is_walked_for_personal_data_only_and_restrictions_decide_self_assessment(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        foreach (['connected' => true, 'updates' => true, 'personal_data' => true, 'payments' => false] as $question => $value) {
            $version->scopingAnswers()->create(['question' => $question, 'value' => $value]);
        }
        $personal = InventoryItem::create(['product_version_id' => $version->id, 'list' => 'assets', 'code' => 'AS-1', 'name' => 'Contacts', 'attributes' => ['type' => 'personal']]);
        InventoryItem::create(['product_version_id' => $version->id, 'list' => 'assets', 'code' => 'AS-2', 'name' => 'Password', 'attributes' => ['type' => 'secret']]);

        $assessment = Assessment::for($version->fresh());
        $this->assertSame(['demo-net', 'demo-priv'], $assessment->standards());
        $this->assertSame([$personal->id], array_map(fn ($item) => $item?->id, $assessment->targets('demo-priv', 'DAT-1')));
        $this->assertCount(2, $assessment->targets('demo-priv', 'ACC-1'));

        // Trees still to walk: not known yet. Restrictions are listed only when they apply.
        $result = (new Conformity($assessment))->result();
        $this->assertNull($result->selfAssessment);
        $this->assertSame(['en18031', 'defaults', 'update_checks'], array_keys($result->restrictions));

        $pack = app(ContentPack::class);
        $answers = ['connected' => true, 'own_password' => true, 'has_updates' => true, 'checked_updates' => false, '_en18031_full' => true];
        $this->assertFalse($pack->rules()->scoping($answers, $pack)->selfAssessment, 'An unchecked update fails a restriction.');
        $answers['checked_updates'] = true;
        $this->assertTrue($pack->rules()->scoping($answers, $pack)->selfAssessment);
        unset($answers['own_password']);
        $this->assertNull($pack->rules()->scoping($answers, $pack)->selfAssessment, 'A visible question is still to answer.');
        $answers['own_password'] = true;
        $answers['_en18031_full'] = false;
        $this->assertFalse($pack->rules()->scoping($answers, $pack)->selfAssessment, 'A FAIL rules out self-assessment.');
    }
}
