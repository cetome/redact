{{--
    Origin mark, same four labels, icons and tooltips as CRAted.
    <x-ai.badge :origin="App\Enums\Origin::AiGenerated" />
    A star always means the AI, a person icon always means a person, the boxes icon the inventory
    (E.Info text the content pack filled in from the inventory: not AI). `tooltip` replaces the
    origin's own tooltip when the context says more (e.g. a pre-filled text not saved yet);
    `label` its label (e.g. a conformity answer pre-filled "From your scoping and decision trees").
--}}
@props(['origin', 'tooltip' => null, 'label' => null])
@php
    $origin = $origin instanceof App\Enums\Origin ? $origin : App\Enums\Origin::from($origin);
    $classes = match ($origin) {
        App\Enums\Origin::AiGenerated => 'bg-brand-blue/10 text-brand-blue border-brand-blue/30 dark:text-brand-blue-night dark:border-brand-blue-night/40',
        App\Enums\Origin::AiEnhanced => 'bg-emerald-500/10 text-emerald-700 border-emerald-500/30 dark:text-emerald-400 dark:border-emerald-500/40',
        App\Enums\Origin::FromInventory, App\Enums\Origin::Imported, App\Enums\Origin::FromVex => 'a-origin-inventory',
        default => 'bg-slate-100 text-slate-600 border-slate-300 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700',
    };
    $icon = match ($origin) {
        App\Enums\Origin::AiGenerated, App\Enums\Origin::AiEnhanced => 'lucide-sparkles',
        App\Enums\Origin::HumanEdited => 'lucide-user-check',
        App\Enums\Origin::Human => 'lucide-user',
        App\Enums\Origin::FromInventory => 'lucide-boxes',
        App\Enums\Origin::Imported => 'lucide-file-spreadsheet',
        App\Enums\Origin::FromVex => 'lucide-shield-check',
    };
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold shrink-0 border', $classes]) }}
      title="{{ $tooltip ?? $origin->tooltip() }}">
    <x-dynamic-component :component="$icon" class="size-[11px]" aria-hidden="true" />
    {{ $label ?? $origin->label() }}
</span>
