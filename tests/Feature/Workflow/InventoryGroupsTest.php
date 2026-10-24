<?php

namespace Tests\Feature\Workflow;

use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * Groups of inventory objects (one object for the trees and E.Info): added from the catalogue,
 * accepted from a proposal, made from existing objects, split back; refused while work exists.
 */
class InventoryGroupsTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    public function test_several_catalogue_items_can_be_added_as_one_group(): void
    {
        [$owner, $version] = $this->scoped();

        $this->signIn($owner)->post(route('versions.inventory.store', [$version, 'assets']), ['catalogue' => [0, 3], 'as_group' => '1', 'group_name' => ''])
            ->assertRedirect(route('versions.inventory', [$version, 'assets']))
            ->assertSessionHas('inventory.new');

        $group = $version->inventoryItems()->where('list', 'assets')->sole();
        $this->assertSame('Sample secrets', $group->name, 'Named after the pack group they share.');
        $this->assertSame(['Sample key', 'Sample secret'], $group->memberNames());
        $this->assertSame('security', $group->attr('type'));
        $this->assertTrue($group->attr('stored'));
        $this->assertSame(['type' => 'security', 'stored' => true], $group->memberList()[0]['attributes']);

        // In the picker both are now flagged as added.
        $this->signIn($owner)->get(route('versions.inventory.pick', [$version, 'assets']))->assertOk()->assertDontSee(__('inventory.proposals_title'));
    }

    public function test_a_group_keeps_only_the_values_its_members_share_and_takes_the_given_name(): void
    {
        [$owner, $version] = $this->scoped();

        $this->signIn($owner)->post(route('versions.inventory.store', [$version, 'assets']), ['catalogue' => [0, 1, 2], 'as_group' => '1', 'group_name' => 'My things'])
            ->assertRedirect();

        $group = $version->inventoryItems()->where('list', 'assets')->sole();
        $this->assertSame('My things', $group->name);
        $this->assertNull($group->attr('type'), 'The members differ: left for the person to set.');
        $this->assertFalse($group->attr('stored'));
        $this->assertCount(3, $group->memberList());
    }

    public function test_a_proposed_group_is_accepted_in_one_click_and_returns_to_the_picker(): void
    {
        [$owner, $version] = $this->scoped();

        $this->signIn($owner)->post(route('versions.inventory.store', [$version, 'assets']), [
            'catalogue' => [0, 3], 'as_group' => '1', 'group_name' => 'Sample secrets', 'return' => 'pick',
        ])->assertRedirect(route('versions.inventory.pick', [$version, 'assets']))
            ->assertSessionHas('status', __('inventory.group_added', ['name' => 'Sample secrets', 'count' => 2]));
    }

    public function test_a_group_needs_two_items(): void
    {
        [$owner, $version] = $this->scoped();

        $this->signIn($owner)->post(route('versions.inventory.store', [$version, 'assets']), ['catalogue' => [0], 'as_group' => '1'])
            ->assertSessionHas('error', __('inventory.group_needs_two'));
        $this->assertSame(0, $version->inventoryItems()->count());
    }

    public function test_existing_objects_are_grouped_with_the_union_of_their_links_then_ungrouped(): void
    {
        [$owner, $version] = $this->scoped();
        $a = $this->item($version, 'AS-01', 'Key A', ['type' => 'security', 'stored' => true]);
        $b = $this->item($version, 'AS-02', 'Key B', ['type' => 'security', 'stored' => false]);
        $other = $this->item($version, 'AS-03', 'Other', ['type' => 'data']);
        $wifi = $version->inventoryItems()->create(['list' => 'interfaces', 'code' => 'IF-01', 'name' => 'Wi-Fi', 'attributes' => ['kind' => 'network']]);
        $usb = $version->inventoryItems()->create(['list' => 'interfaces', 'code' => 'IF-02', 'name' => 'USB', 'attributes' => ['kind' => 'user']]);
        $this->link($version, $a, $wifi);
        $this->link($version, $b, $wifi);
        $this->link($version, $b, $usb);

        $this->signIn($owner)->post(route('versions.inventory.group', [$version, 'assets']), ['items' => [$a->id, $b->id], 'name' => ''])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertNull(InventoryItem::find($a->id));
        $group = $version->inventoryItems()->where('list', 'assets')->where('code', 'AS-01')->sole();
        $this->assertSame('Sample secrets', $group->name);
        $this->assertSame(['Key A', 'Key B'], $group->memberNames());
        $this->assertSame('security', $group->attr('type'));
        $this->assertFalse($group->attr('stored'), 'Only shared values stay on the group.');
        $this->assertEqualsCanonicalizing([$wifi->id, $usb->id], $version->inventoryLinks()->where('from_item_id', $group->id)->pluck('to_item_id')->all());
        $this->assertSame(1, $version->inventoryLinks()->where('from_item_id', $group->id)->where('to_item_id', $wifi->id)->count(), 'No duplicate link.');
        $this->assertSame(2, $version->inventoryItems()->where('list', 'assets')->count(), 'The group and "Other".');
        $this->assertTrue(Activity::query()->where('description', 'grouped')->where('subject_id', $group->id)->exists());

        // The list shows the group with its members.
        $this->signIn($owner)->get(route('versions.inventory', [$version, 'assets']))->assertOk()
            ->assertSee(__('inventory.group_of', ['count' => 2]))->assertSee('Key B');

        // Ungroup: one object per member, former codes and own values back, the group's links on each.
        $this->signIn($owner)->post(route('versions.inventory.ungroup', [$version, 'assets', $group]))
            ->assertRedirect()->assertSessionHas('status');

        $items = $version->inventoryItems()->where('list', 'assets')->orderBy('position')->get();
        $this->assertSame(['Key A', 'Key B', 'Other'], $items->pluck('name')->all());
        $this->assertSame(['AS-01', 'AS-02', 'AS-03'], $items->pluck('code')->all());
        $this->assertTrue($items[0]->attr('stored'), 'The member\'s own value comes back where the group left it empty.');
        foreach ([$items[0], $items[1]] as $item) {
            $this->assertEqualsCanonicalizing([$wifi->id, $usb->id], $version->inventoryLinks()->where('from_item_id', $item->id)->pluck('to_item_id')->all());
        }
        $this->assertTrue(Activity::query()->where('description', 'ungrouped')->exists());
        $this->assertSame($other->id, $items[2]->id);
    }

    public function test_grouping_is_refused_when_an_object_has_evaluations_or_einfo(): void
    {
        [$owner, $version] = $this->scoped();
        $a = $this->item($version, 'AS-01', 'Key A', ['type' => 'security']);
        $b = $this->item($version, 'AS-02', 'Key B', ['type' => 'security']);
        $version->evaluations()->create(['standard' => 'demo-1', 'requirement' => 'ACC-1', 'inventory_item_id' => $b->id, 'decision_node' => 'DN-1']);

        $this->signIn($owner)->post(route('versions.inventory.group', [$version, 'assets']), ['items' => [$a->id, $b->id]])
            ->assertSessionHas('error', __('inventory.group_refused', ['items' => 'AS-02']));
        $this->assertNotNull(InventoryItem::find($a->id));
        $this->assertNotNull(InventoryItem::find($b->id));
        $this->assertSame(1, $version->evaluations()->count(), 'Nothing lost.');

        // Same for a group with E.Info texts: not split.
        $group = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-09', 'name' => 'G', 'members' => [['name' => 'x'], ['name' => 'y']]]);
        $version->einfoEntries()->create(['einfo' => 'E.Info.Demo', 'inventory_item_id' => $group->id, 'text' => 'Written for the group.']);
        $this->signIn($owner)->post(route('versions.inventory.ungroup', [$version, 'assets', $group]))
            ->assertSessionHas('error', __('inventory.ungroup_refused', ['item' => 'AS-09']));
        $this->assertNotNull(InventoryItem::find($group->id));
    }

    public function test_group_members_can_be_edited_one_per_line(): void
    {
        [$owner, $version] = $this->scoped();
        $group = $version->inventoryItems()->create(['list' => 'assets', 'code' => 'AS-01', 'name' => 'G', 'attributes' => ['type' => 'security'],
            'members' => [['name' => 'Key A', 'code' => 'AS-07', 'attributes' => ['stored' => true]], ['name' => 'Key B']]]);

        $this->signIn($owner)->put(route('versions.inventory.update', [$version, 'assets', $group]), [
            'code' => 'AS-01', 'name' => 'G', 'attributes' => ['type' => 'security'], 'members_text' => "Key A\n\nKey C\nkey a",
        ])->assertRedirect();

        $members = $group->fresh()->memberList();
        $this->assertSame(['Key A', 'Key C'], array_column($members, 'name'));
        $this->assertSame('AS-07', $members[0]['code'], 'A kept member keeps what it carried.');
    }

    public function test_reviewers_and_strangers_cannot_group(): void
    {
        [$owner, $version] = $this->scoped();
        $a = $this->item($version, 'AS-01', 'Key A', ['type' => 'security']);
        $b = $this->item($version, 'AS-02', 'Key B', ['type' => 'security']);

        $this->signIn(User::factory()->create())->post(route('versions.inventory.group', [$version, 'assets']), ['items' => [$a->id, $b->id]])->assertNotFound();
        $this->assertSame(2, $version->inventoryItems()->count());
    }

    /** @return array{0: User, 1: ProductVersion} */
    private function scoped(): array
    {
        $owner = $this->owner();
        $version = $this->product($owner);
        $version->scopingAnswers()->create(['question' => 'connected', 'value' => true]);

        return [$owner, $version];
    }

    /** @param  array<string, mixed>  $attributes */
    private function item(ProductVersion $version, string $code, string $name, array $attributes): InventoryItem
    {
        return $version->inventoryItems()->create([
            'list' => 'assets', 'code' => $code, 'name' => $name, 'attributes' => $attributes + ['type' => null, 'stored' => false],
            'position' => (int) substr($code, -2),
        ]);
    }

    private function link(ProductVersion $version, InventoryItem $from, InventoryItem $to): void
    {
        $version->inventoryLinks()->create(['relation' => 'asset_interface', 'from_item_id' => $from->id, 'to_item_id' => $to->id]);
    }
}
