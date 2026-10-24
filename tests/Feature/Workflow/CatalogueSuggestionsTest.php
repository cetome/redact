<?php

namespace Tests\Feature\Workflow;

use App\Assessment\Assessment;
use App\Assessment\CatalogueSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Catalogue items are pre-selected from the scoping: standard in scope, and
 * the item's own question (suggest_when) answered yes. They are ordered by
 * tier (answer, standard, other), then the pack's presence, then catalogue order.
 */
class CatalogueSuggestionsTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_nothing_is_suggested_before_scoping(): void
    {
        $version = $this->product($this->owner());

        $rows = (new CatalogueSuggestions(Assessment::for($version)))->forList('assets');

        $this->assertCount(6, $rows);
        $this->assertSame([false], array_values(array_unique(array_column($rows, 'suggested'))));
        $this->assertSame(['other'], array_values(array_unique(array_column($rows, 'tier'))));
        // Without scoping, the presence ranking alone orders the list: typical, common, the rest.
        $this->assertSame(['Sample key', 'Sample log', 'Sample secret', 'Sample update data', 'Sample incomplete', 'Sample later'], array_column($rows, 'name'));
    }

    public function test_suggestions_follow_the_scoping_answers(): void
    {
        $version = $this->product($this->owner());
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);

        $rows = collect((new CatalogueSuggestions(Assessment::for($version)))->forList('assets'))->keyBy('name');

        $this->assertTrue($rows['Sample key']['suggested']);
        $this->assertFalse($rows['Sample update data']['suggested'], 'Needs the "updates" answer.');
        $this->assertTrue($rows['Sample key']['complete']);
        $this->assertFalse($rows['Sample incomplete']['complete'], 'Required "type" is missing.');

        $version->scopingAnswers()->create(['question' => 'updates', 'value' => true]);
        $rows = collect((new CatalogueSuggestions(Assessment::for($version->fresh())))->forList('assets'))->keyBy('name');
        $this->assertTrue($rows['Sample update data']['suggested']);
        $this->assertSame('answer', $rows['Sample update data']['strength'], 'Linked to an answer: pre-ticked.');
        $this->assertSame('standard', $rows['Sample key']['strength'], 'Only usual for the standard: listed, not ticked.');
        $this->assertCount(2, $rows['Sample update data']['reasons']);
    }

    public function test_rows_are_ordered_by_tier_then_presence_then_catalogue_order(): void
    {
        $version = $this->product($this->owner());
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $version->scopingAnswers()->create(['question' => 'updates', 'value' => true]);
        $version->scopingAnswers()->create(['question' => 'later_q', 'value' => false]);

        $rows = (new CatalogueSuggestions(Assessment::for($version)))->forList('assets');

        // Linked to an answer (typical first), then usual for the standard (typical, common, rest), then the rest.
        $this->assertSame(['Sample log', 'Sample update data', 'Sample key', 'Sample secret', 'Sample incomplete', 'Sample later'], array_column($rows, 'name'));
        $this->assertSame(['answer', 'answer', 'standard', 'standard', 'standard', 'other'], array_column($rows, 'tier'));
        $this->assertSame(['typical', null, 'typical', 'common', null, null], array_column($rows, 'presence'));
        // "index" stays the catalogue position (the form value).
        $this->assertSame([4, 1, 0, 3, 2, 5], array_column($rows, 'index'));
    }

    public function test_a_question_of_a_later_step_does_not_hold_an_item_back_until_answered(): void
    {
        $version = $this->product($this->owner());
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $later = fn () => collect((new CatalogueSuggestions(Assessment::for($version->fresh())))->forList('assets'))->keyBy('name')['Sample later'];

        $this->assertSame('standard', $later()['strength'], 'Not answered yet: suggested for its part only.');

        $answer = $version->scopingAnswers()->create(['question' => 'later_q', 'value' => false]);
        $this->assertFalse($later()['suggested'], 'Answered no: held back.');

        $answer->update(['value' => true]);
        $this->assertSame('answer', $later()['strength'], 'Answered yes: linked to the answer.');
    }

    public function test_items_already_in_the_inventory_are_flagged_not_suggested(): void
    {
        $version = $this->product($this->owner());
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);
        $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'sample KEY']);
        $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-02', 'name' => 'Secrets', 'members' => [['name' => 'Sample secret']]]);

        $rows = collect((new CatalogueSuggestions(Assessment::for($version)))->forList('assets'))->keyBy('name');

        $this->assertTrue($rows['Sample key']['added']);
        $this->assertFalse($rows['Sample key']['suggested']);
        $this->assertTrue($rows['Sample secret']['added'], 'A member of a group counts as added.');
        $this->assertSame('standard', $rows['Sample key']['tier'], 'An added item keeps its place in the order.');
    }

    public function test_groups_are_proposed_for_items_put_forward_with_the_same_attributes(): void
    {
        $version = $this->product($this->owner());
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);

        $proposals = CatalogueSuggestions::proposals((new CatalogueSuggestions(Assessment::for($version)))->forList('assets'));

        // Sample key (typical) and Sample secret (common) share {type: security, stored: true}.
        $this->assertCount(1, $proposals);
        $this->assertSame('secrets', $proposals[0]['key']);
        $this->assertSame('Sample secrets', $proposals[0]['name']);
        $this->assertSame([0, 3], $proposals[0]['indexes']);

        // With "updates": the two pre-ticked data items form a second group; "Sample later"
        // (other attributes, no presence, not linked to a yes) is left out.
        $version->scopingAnswers()->create(['question' => 'updates', 'value' => true]);
        $proposals = collect(CatalogueSuggestions::proposals((new CatalogueSuggestions(Assessment::for($version->fresh())))->forList('assets')))->keyBy('key');
        $this->assertSame([4, 1], $proposals['data']['indexes']);
        $this->assertSame(['Sample log', 'Sample update data'], $proposals['data']['names']);
    }

    public function test_the_picker_shows_presence_order_help_and_proposals(): void
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);

        $this->signIn($owner)->get(route('versions.inventory.pick', [$version, 'assets']))->assertOk()
            ->assertSee(__('inventory.presence_typical'))
            ->assertSee(__('inventory.presence_common'))
            ->assertSee(__('inventory.order_help'))
            ->assertSee(__('inventory.proposals_title'))
            ->assertSee('form="proposal-0"', false)
            ->assertSee(__('inventory.add_as_group'))
            ->assertSeeInOrder(['Sample key', 'Sample secret', 'Sample incomplete']);
    }
}
