{{--
    Origin mark for one human field, when its text came from the AI.
    <x-ai.origin type="evaluation" :key="$evaluation->id" field="justification" :value="$evaluation->justification" />
    Renders nothing when a person wrote the text. "From inventory" marks an E.Info text filled in
    from the inventory and saved unchanged (EinfoController); changed, it is "Human Edited". Pass the field's CURRENT value: the mark
    holds only while the text equals what was accepted (CRAted's rule). An AI-written text a
    person changed shows "Human Edited"; a reworded text a person changed is theirs again.
    Types: product (key = product id, field description), product_version (intended_use, or
    description = the product's), inventory_item (name, description, attributes.<field>),
    evaluation (justification), einfo_entry (key = entry id or "<code>|<item id or ->", text),
    risk_entry (threat, description, risk, remediation, implementation).
--}}
@props(['type', 'key', 'field', 'value' => null])
@php
    $provenance = app(\App\Ai\Provenance::class);
    $origin = $provenance->origin((string) $type, (string) $key, (string) $field, $value === null ? null : (string) $value);
    // An E.Info text filled in from the inventory, then changed: "Human Edited", said the right way.
    $recorded = $origin === \App\Enums\Origin::HumanEdited ? $provenance->recorded((string) $type, (string) $key, (string) $field) : null;
    $tooltip = match ($recorded) {
        \App\Enums\Origin::FromInventory => __('ai.origin.from_inventory.tooltip_edited'),
        \App\Enums\Origin::Imported => __('ai.origin.imported.tooltip_edited'),
        \App\Enums\Origin::FromVex => __('ai.origin.from_vex.tooltip_edited'),
        default => null,
    };
@endphp
@if ($origin !== \App\Enums\Origin::Human)
    <x-ai.badge :origin="$origin" :tooltip="$tooltip" :attributes="$attributes" />
@endif
