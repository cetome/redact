<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\CatalogueSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\Concerns\UsesPackCopy;
use Tests\TestCase;

/**
 * The picker's order is the pack's (catalogue.json "ranking"); without one the engine applies
 * its neutral default (tier, then presence, then catalogue order).
 */
class CatalogueRankingTest extends TestCase
{
    use BuildsProducts, RefreshDatabase, UsesPackCopy;

    /** @return list<string> names in picker order, with connected + updates answered yes */
    private function order(): array
    {
        $version = $this->product($this->owner());
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $version->scopingAnswers()->create(['question' => 'updates', 'value' => true]);
        $version->scopingAnswers()->create(['question' => 'later_q', 'value' => false]);

        return array_column((new CatalogueSuggestions(Assessment::for($version)))->forList('assets'), 'name');
    }

    public function test_without_a_ranking_the_engine_default_applies(): void
    {
        $this->usePackCopy(fn (string $path) => $this->changeJson($path.'/catalogue.json', function (array $data) {
            unset($data['ranking']);

            return $data;
        }));

        // Tier (answer, standard, other), then presence (typical, common, none), then catalogue order.
        $this->assertSame(['Sample log', 'Sample update data', 'Sample key', 'Sample secret', 'Sample incomplete', 'Sample later'], $this->order());
    }

    public function test_the_pack_ranking_decides_the_order(): void
    {
        $this->usePackCopy(fn (string $path) => $this->changeJson($path.'/catalogue.json', function (array $data) {
            // Presence only, common before typical; unknown keys and values are ignored.
            $data['ranking'] = ['order' => ['presence', 'nonsense'], 'presence' => ['common' => 5, 'typical' => 1, 'rare' => 9]];

            return $data;
        }));

        $this->assertSame(['Sample secret', 'Sample key', 'Sample log', 'Sample update data', 'Sample incomplete', 'Sample later'], $this->order());
    }

    public function test_an_empty_order_keeps_the_catalogue_order(): void
    {
        $this->usePackCopy(fn (string $path) => $this->changeJson($path.'/catalogue.json', function (array $data) {
            $data['ranking'] = ['order' => []];

            return $data;
        }));

        $this->assertSame(['Sample key', 'Sample update data', 'Sample incomplete', 'Sample secret', 'Sample log', 'Sample later'], $this->order());
    }
}
