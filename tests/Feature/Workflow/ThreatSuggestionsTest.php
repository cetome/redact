<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\ThreatSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Threats of the library are proposed from the scoping: pre-ticked when linked to an
 * answer given yes, listed when one of their articles is in scope.
 */
class ThreatSuggestionsTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_nothing_is_suggested_before_scoping(): void
    {
        $rows = (new ThreatSuggestions(Assessment::for($this->product($this->owner()))))->rows();

        $this->assertCount(3, $rows);
        $this->assertSame([false, false, false], array_column($rows, 'suggested'));
    }

    public function test_threats_follow_the_scoping_answers_and_come_first(): void
    {
        $version = $this->product($this->owner());
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $version->scopingAnswers()->create(['question' => 'updates', 'value' => true]);

        $rows = (new ThreatSuggestions(Assessment::for($version)))->rows();

        $this->assertSame(['Sample update threat', 'Sample threat', 'Sample threat of another article'], array_column($rows, 'name'));
        $this->assertSame(['answer', 'standard', null], array_column($rows, 'strength'));
        $this->assertSame(1, $rows[0]['index'], 'The library index is kept for the form.');
        $this->assertCount(1, $rows[0]['reasons']);
    }

    public function test_a_threat_already_in_the_register_is_not_suggested(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'updates', 'value' => true]);
        $version->riskEntries()->create(['position' => 1, 'threat' => 'sample UPDATE threat']);

        $rows = collect((new ThreatSuggestions(Assessment::for($version)))->rows())->keyBy('name');

        $this->assertTrue($rows['Sample update threat']['added']);
        $this->assertFalse($rows['Sample update threat']['suggested']);
    }

    public function test_the_empty_register_offers_the_suggested_threats_in_one_click(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'updates', 'value' => true]);

        $this->signIn($owner)->get(route('versions.risks', $version))
            ->assertOk()
            ->assertSee('Add the suggested threat')
            ->assertSee('Linked to your answers (ticked)');

        $this->post(route('versions.risks.library', $version), ['threats' => [1]])->assertRedirect();
        $this->assertSame(['Sample update threat'], $version->riskEntries()->pluck('threat')->all());
    }
}
