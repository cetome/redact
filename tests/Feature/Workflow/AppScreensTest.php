<?php

namespace Tests\Feature\Workflow;

use App\Content\ContentPack;
use App\Enums\ProductRole;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * The behaviour behind the redesigned screens (app UI kit): catalogue picker, side panels
 * without script, mapping autosave, heat map rating, mechanisms list, "save and next".
 * Runs on the test fixture pack.
 */
class AppScreensTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_the_catalogue_picker_adds_several_objects_and_marks_the_incomplete_ones(): void
    {
        [$owner, $version] = $this->scoped();

        $this->signIn($owner)->get(route('versions.inventory.pick', [$version, 'assets']))
            ->assertOk()->assertSee('Sample key')->assertSee('name="catalogue[]"', false);

        $this->signIn($owner)->post(route('versions.inventory.store', [$version, 'assets']), ['catalogue' => [0, 2]])
            ->assertRedirect(route('versions.inventory', [$version, 'assets']))
            ->assertSessionHas('inventory.new');

        $items = $version->inventoryItems()->where('list', 'assets')->orderBy('position')->get();
        $this->assertSame(['Sample key', 'Sample incomplete'], $items->pluck('name')->all());
        $this->assertSame('security', $items[0]->getAttribute('attributes')['type']);
        $this->assertNull($items[1]->getAttribute('attributes')['type']);

        // Already in the inventory: shown as added, not offered again.
        $this->signIn($owner)->get(route('versions.inventory.pick', [$version, 'assets']))->assertOk()->assertSee(__('inventory.already_added'));

        $this->signIn($owner)->post(route('versions.inventory.store', [$version, 'assets']), ['catalogue' => [99]])
            ->assertSessionHasErrors('catalogue.0');
    }

    public function test_the_picker_is_refused_to_people_who_cannot_edit(): void
    {
        [$owner, $version] = $this->scoped();
        $reviewer = $this->member($version->product, ProductRole::Reviewer);

        $this->signIn($reviewer)->get(route('versions.inventory.pick', [$version, 'assets']))->assertNotFound();
        $this->signIn(User::factory()->create())->get(route('versions.inventory.pick', [$version, 'assets']))->assertNotFound();
        $this->signIn($owner)->get(route('versions.inventory.pick', [$version, 'nope']))->assertNotFound();
    }

    public function test_side_panels_open_without_script_and_mappings_save_as_json(): void
    {
        [$owner, $version] = $this->scoped();
        $asset = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'Door key', 'attributes' => ['type' => 'security']]);
        $interface = $version->inventoryItems()->create(['list' => 'interfaces', 'code' => 'IF-01', 'name' => 'BLE', 'attributes' => []]);

        $this->signIn($owner)->get(route('versions.inventory.edit', [$version, 'assets', $asset->id]))
            ->assertOk()->assertSee('id="item-drawer"', false)->assertSee('Door key');
        $this->signIn($owner)->get(route('versions.inventory.mappings', [$version, 'assets']))
            ->assertOk()->assertSee('data-tick-grid', false);

        $relation = array_key_first(app(ContentPack::class)->relations());
        $this->signIn($owner)->putJson(route('versions.inventory.links', [$version, $relation]), [
            'side' => 'from', 'rows' => [$asset->id], 'links' => [$asset->id => [$interface->id]],
        ])->assertOk()->assertJson(['saved' => true]);
        $this->assertSame(1, $version->inventoryLinks()->count());
    }

    public function test_a_risk_is_rated_on_the_heat_map_with_mechanisms_from_the_picker(): void
    {
        [$owner, $version] = $this->scoped();

        $this->signIn($owner)->post(route('versions.risks.store', $version), [
            'threat' => 'Fake firmware', 'cell' => '4-2', 'requirements' => ['UPD-1', 'NOT-A-REQ', 'UPD-1'],
        ])->assertRedirect();

        $risk = $version->riskEntries()->firstOrFail();
        $this->assertSame([4, 2], [$risk->impact, $risk->likelihood]);
        $this->assertSame(['UPD-1'], $risk->requirements);

        $this->signIn($owner)->put(route('versions.risks.update', [$version, $risk]), ['threat' => 'Fake firmware', 'cell' => ''])->assertRedirect();
        $this->assertNull($risk->fresh()->impact);

        $this->signIn($owner)->post(route('versions.risks.store', $version), ['threat' => 'x', 'cell' => '6-1'])->assertSessionHasErrors('cell');

        foreach ([route('versions.risks.edit', [$version, $risk]), route('versions.risks.new', $version),
            route('versions.risks.library.show', $version), route('versions.risks.matrix.show', $version)] as $url) {
            $this->signIn($owner)->get($url)->assertOk();
        }
    }

    public function test_the_library_adds_one_threat_with_its_suggested_values(): void
    {
        [$owner, $version] = $this->scoped();

        $this->signIn($owner)->post(route('versions.risks.library', $version), ['threats' => [0]])->assertRedirect();

        $risk = $version->riskEntries()->firstOrFail();
        $this->assertSame('Sample threat', $risk->threat);
        $this->assertSame(['T'], $risk->stride);
        $this->assertSame(['UPD-1'], $risk->requirements);
    }

    public function test_save_and_next_moves_on_to_the_next_object_to_do(): void
    {
        [$owner, $version] = $this->scoped();
        $first = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'One', 'attributes' => ['type' => 'security']]);
        $second = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-02', 'name' => 'Two', 'attributes' => ['type' => 'security']]);

        $this->signIn($owner)->put(route('versions.evaluate.update', [$version, 'demo-1', 'ACC-1']), [
            'target' => $first->id, 'answers' => ['No', 'Yes'], 'justification' => 'Paired app.', 'next' => '1',
        ])->assertRedirect(route('versions.evaluate.target', [$version, 'demo-1', 'ACC-1', $second->id]));

        $this->signIn($owner)->get(route('versions.evaluate.target', [$version, 'demo-1', 'ACC-1', $second->id]))
            ->assertOk()->assertSee('Two');
    }

    /** @return array{0: User, 1: ProductVersion} */
    private function scoped(): array
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);

        return [$owner, $version];
    }
}
