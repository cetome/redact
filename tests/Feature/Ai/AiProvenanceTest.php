<?php

namespace Tests\Feature\Ai;

use App\Ai\AiException;
use App\Ai\Provenance;
use App\Ai\Targets;
use App\Enums\Origin;
use App\Enums\ProductRole;
use App\Models\AiSuggestion;
use App\Models\EinfoEntry;
use App\Models\FieldOrigin;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use Illuminate\Support\Facades\Blade;

class AiProvenanceTest extends AiTestCase
{
    public function test_improve_text_never_touches_the_field_before_acceptance(): void
    {
        $this->fakeAi([['improved' => 'A door lock that pairs over a radio link.', 'changes' => ['Clearer wording']]]);

        $response = $this->actingAs($this->owner)->postJson(route('ai.improve', $this->version), [
            'type' => 'product', 'key' => $this->product->id, 'field' => 'description', 'text' => 'A door lock with a radio.',
        ]);

        $response->assertOk()->assertJson(['improved' => 'A door lock that pairs over a radio link.', 'unchanged' => false]);
        $this->assertSame('A door lock with a radio.', $this->product->fresh()->description);
        $this->assertSame(0, FieldOrigin::count());

        $suggestion = AiSuggestion::sole();
        $this->assertSame('pending', $suggestion->status);
        $this->assertSame(Origin::AiEnhanced, $suggestion->origin);
    }

    public function test_accepting_writes_the_value_and_its_origin_and_an_edit_makes_it_the_persons_again(): void
    {
        $this->fakeAi([['improved' => 'A door lock that pairs over a radio link.', 'changes' => []]]);
        $id = $this->actingAs($this->owner)->postJson(route('ai.improve', $this->version), [
            'type' => 'product', 'key' => $this->product->id, 'field' => 'description', 'text' => 'A door lock with a radio.',
        ])->json('id');

        $this->postJson(route('ai.suggestions.accept', [$this->version, $id]))
            ->assertOk()->assertJson(['value' => 'A door lock that pairs over a radio link.', 'origin' => 'ai_enhanced']);

        $this->assertSame('A door lock that pairs over a radio link.', $this->product->fresh()->description);
        $mark = FieldOrigin::sole();
        $this->assertSame(['product', $this->product->id, 'description'], [$mark->target_type, $mark->target_key, $mark->field]);
        $this->assertSame($this->owner->id, $mark->accepted_by);
        $suggestion = AiSuggestion::find($id);
        $this->assertSame('accepted', $suggestion->status);
        $this->assertSame($this->owner->id, $suggestion->decided_by);
        $this->assertNotNull($suggestion->decided_at);

        $provenance = new Provenance;
        $this->assertSame(Origin::AiEnhanced, $provenance->origin('product', $this->product->id, 'description', 'A door lock that pairs over a radio link.'));
        $this->assertSame(Origin::AiEnhanced, $provenance->origin('product_version', $this->version->id, 'description', 'A door lock that pairs over a radio link.'));
        // CRAted: a reworded text a person changes again is theirs.
        $this->assertSame(Origin::Human, $provenance->origin('product', $this->product->id, 'description', 'My own words now.'));

        // Accepting twice is refused.
        $this->postJson(route('ai.suggestions.accept', [$this->version, $id]))->assertStatus(422);
    }

    public function test_an_ai_generated_text_edited_by_a_person_is_human_edited(): void
    {
        $item = $this->item();
        $suggestion = $this->suggestion('inventory_item', $item->id, 'description', 'Key stored in the secure element.');

        app(Provenance::class)->accept($suggestion, $this->owner);

        $this->assertSame('Key stored in the secure element.', $item->fresh()->description);
        $provenance = new Provenance;
        $this->assertSame(Origin::AiGenerated, $provenance->origin('inventory_item', $item->id, 'description', 'Key stored in the secure element.'));
        $this->assertSame(Origin::HumanEdited, $provenance->origin('inventory_item', $item->id, 'description', 'Key stored in the secure element, rotated yearly.'));
        $this->assertSame(Origin::Human, $provenance->origin('inventory_item', $item->id, 'name', 'Door key'));

        // The origin component renders the badge, and nothing for human text.
        $html = Blade::render('<x-ai.origin type="inventory_item" :key="$id" field="description" :value="$value" />', ['id' => $item->id, 'value' => 'Key stored in the secure element.']);
        $this->assertStringContainsString(__('ai.origin.ai_generated.label'), $html);
        $this->assertStringContainsString(__('ai.origin.ai_generated.tooltip'), $html);
        $this->assertSame('', trim(Blade::render('<x-ai.origin type="inventory_item" :key="$id" field="name" value="Door key" />', ['id' => $item->id])));
    }

    public function test_the_verdict_decision_node_and_path_can_never_be_targeted(): void
    {
        $evaluation = $this->evaluation();

        foreach (['verdict', 'decision_node', 'path', 'requirement', 'standard'] as $field) {
            $this->assertFalse(Targets::allowed('evaluation', $field));
            try {
                Targets::resolve($this->version, 'evaluation', $evaluation->id, $field);
                $this->fail("{$field} must not be a target");
            } catch (AiException $e) {
                $this->assertSame(422, $e->status);
            }

            $forged = $this->suggestion('evaluation', $evaluation->id, $field, 'FAIL');
            $this->actingAs($this->owner)->postJson(route('ai.suggestions.accept', [$this->version, $forged]))->assertStatus(422);
        }

        $this->fakeAi([]);
        $this->actingAs($this->owner)->postJson(route('ai.improve', $this->version), [
            'type' => 'evaluation', 'key' => $evaluation->id, 'field' => 'verdict', 'text' => 'PASS',
        ])->assertStatus(422);

        $fresh = $evaluation->fresh();
        $this->assertSame('PASS', $fresh->verdict->value);
        $this->assertSame('DN-2', $fresh->decision_node);
        $this->assertSame(0, FieldOrigin::count());

        // The justification is the one evaluation field the AI may propose.
        $justification = $this->suggestion('evaluation', $evaluation->id, 'justification', 'Access needs the paired owner app.');
        app(Provenance::class)->accept($justification, $this->owner);
        $this->assertSame('Access needs the paired owner app.', $evaluation->fresh()->justification);
        $this->assertSame('PASS', $evaluation->fresh()->verdict->value);
    }

    public function test_fields_of_another_version_or_unknown_types_are_refused(): void
    {
        $other = ProductVersion::create(['product_id' => $this->product->id, 'label' => '2.0', 'status' => 'draft']);
        $item = InventoryItem::create(['product_version_id' => $other->id, 'list' => 'assets', 'code' => 'AS-9', 'name' => 'Other']);

        $this->expectException(AiException::class);
        Targets::resolve($this->version, 'inventory_item', $item->id, 'description');
    }

    public function test_select_attributes_accept_only_pack_options_and_einfo_entries_are_created(): void
    {
        $item = $this->item();
        $bad = $this->suggestion('inventory_item', $item->id, 'attributes.type', 'not-an-option');
        $this->actingAs($this->owner)->postJson(route('ai.suggestions.accept', [$this->version, $bad]))->assertStatus(422);
        $this->assertSame('security', $item->fresh()->attr('type'));

        $good = $this->suggestion('inventory_item', $item->id, 'attributes.type', 'data');
        $this->postJson(route('ai.suggestions.accept', [$this->version, $good]))->assertOk();
        $this->assertSame('data', $item->fresh()->attr('type'));

        $einfo = $this->suggestion('einfo_entry', Targets::einfoKey('E.Info.ACC-1.Asset', $item->id), 'text', 'Only the paired app can open the lock.');
        $this->postJson(route('ai.suggestions.accept', [$this->version, $einfo]))->assertOk();
        $entry = EinfoEntry::sole();
        $this->assertSame('Only the paired app can open the lock.', $entry->text);
        // The lead's pages key E.Info marks by entry id: same mark.
        $this->assertSame(Origin::AiGenerated, app(Provenance::class)->origin('einfo_entry', $entry->id, 'text', $entry->text));
    }

    public function test_reject_changes_nothing_and_a_reviewer_cannot_accept(): void
    {
        $item = $this->item();
        $suggestion = $this->suggestion('inventory_item', $item->id, 'name', 'Master key');

        $reviewer = $this->member(ProductRole::Reviewer);
        $this->actingAs($reviewer)->postJson(route('ai.suggestions.accept', [$this->version, $suggestion]))->assertNotFound();
        $this->actingAs($reviewer)->postJson(route('ai.suggestions.reject', [$this->version, $suggestion]))->assertNotFound();

        $this->actingAs($this->owner)->postJson(route('ai.suggestions.reject', [$this->version, $suggestion]))->assertOk();
        $this->assertSame('rejected', $suggestion->fresh()->status);
        $this->assertSame('Door key', $item->fresh()->name);
        $this->assertSame(0, FieldOrigin::count());
    }

    private function suggestion(string $type, string $key, string $field, string $value, string $feature = 'draft_from_documents'): AiSuggestion
    {
        return AiSuggestion::create([
            'product_version_id' => $this->version->id,
            'feature' => $feature,
            'target_type' => $type,
            'target_key' => $key,
            'field' => $field,
            'origin' => 'ai_generated',
            'payload' => ['value' => $value, 'sources' => []],
            'status' => 'pending',
            'provider' => 'gemini',
            'model' => 'gemini-test-model',
            'requested_by' => $this->owner->id,
        ]);
    }
}
