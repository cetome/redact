{{--
    Side panel (or modal) to edit one record: a native <dialog>, so focus stays inside and Esc closes.
    Opened by <a href="{fallback url}" data-dialog-open="{id}"> or by script (openDialog() in ui.js).
    Without script, the page that opens it renders it with :open="true": it then shows as a block
    of the page, and its close button is a link back to :close-url.

    <x-ui.drawer id="risk-drawer" :title="…" :open="$editing !== null" :close-url="route(…)" wide>
        … body …
        <x-slot:footer> buttons </x-slot:footer>
    </x-ui.drawer>
    <x-ui.drawer id="matrix" modal …>   same thing, centred (a-modal)
--}}
@props(['id', 'title' => null, 'open' => false, 'closeUrl' => null, 'wide' => false, 'modal' => false])
<dialog id="{{ $id }}" aria-labelledby="{{ $id }}-title"
    {{ $attributes->class([$modal ? 'a-modal' : 'a-drawer', 'a-drawer-wide' => $wide && ! $modal, 'a-modal-wide' => $wide && $modal, 'is-static' => $open]) }}
    @if ($closeUrl) data-close-url="{{ $closeUrl }}" @endif @if ($open) open @endif>
    <div class="a-drawer-head">
        <div class="min-w-0">
            @isset($eyebrow)<p class="a-eyebrow">{{ $eyebrow }}</p>@endisset
            <h2 class="a-drawer-title" id="{{ $id }}-title">@isset($heading){{ $heading }}@else{{ $title }}@endisset</h2>
        </div>
        @if ($closeUrl)
            <a href="{{ $closeUrl }}" class="a-close" data-dialog-close aria-label="{{ __('common.close') }}"><x-lucide-x aria-hidden="true" /></a>
        @else
            <button type="button" class="a-close" data-dialog-close aria-label="{{ __('common.close') }}"><x-lucide-x aria-hidden="true" /></button>
        @endif
    </div>
    <div class="a-drawer-body">{{ $slot }}</div>
    @isset($footer)
        <div class="a-drawer-foot">{{ $footer }}</div>
    @endisset
</dialog>
