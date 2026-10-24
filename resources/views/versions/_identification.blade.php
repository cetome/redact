{{--
    Product identification (Product step), the one place for the models and the identifiers (owner,
    2026-10-23): the product's models first (product level, ProductModels), then the version's trade
    name, brand and type. The reference and the hardware and software versions are the card's
    (ProductIdentifier::VERSION_KINDS); older rows of those kinds stay listed with their kind.
--}}
@php
    $rows = $version->product->models->map(fn ($m) => ['kind' => \App\Models\ProductIdentifier::MODEL, 'value' => $m->value, 'notes' => $m->notes ?? ''])
        ->concat($version->identifiers->map(fn ($i) => ['kind' => $i->kind, 'value' => $i->value, 'notes' => $i->notes ?? '']))->values();
    $kinds = array_values(array_unique([\App\Models\ProductIdentifier::MODEL, ...\App\Models\ProductIdentifier::VERSION_KINDS,
        ...$rows->pluck('kind')->all()]));
@endphp
<form id="identification" method="POST" action="{{ route('versions.identification', $version) }}" class="a-panel">
    @csrf
    @method('PUT')
    <div class="a-panel-head">
        <div class="min-w-0">
            <h2 class="a-h3">{{ __('identification.title') }}</h2>
            <p class="a-help mt-0.5">{{ __('identification.intro') }}</p>
        </div>
    </div>
    <fieldset @disabled(! $editable) class="a-panel-body">
        <div x-data="identifierRows" data-rows="{{ $rows->toJson() }}" data-default-kind="model">
            <div class="hidden gap-2 pb-1.5 sm:grid sm:grid-cols-[11rem_1fr_1fr_2rem]">
                <span class="a-micro">{{ __('identification.kind') }}</span>
                <span class="a-micro">{{ __('identification.value') }}</span>
                <span class="a-micro">{{ __('identification.notes') }}</span>
                <span></span>
            </div>
            <template x-for="(row, index) in rows" :key="row.id">
                <div class="grid gap-2 border-t border-line-soft py-2 sm:grid-cols-[11rem_1fr_1fr_2rem] sm:items-center dark:border-line">
                    <select class="a-select a-input-sm" :name="'identifiers[' + index + '][kind]'" x-model="row.kind" aria-label="{{ __('identification.kind') }}">
                        @foreach ($kinds as $kind)
                            <option value="{{ $kind }}">{{ __('identification.kinds.'.$kind) }}</option>
                        @endforeach
                    </select>
                    <input class="a-input a-input-sm" :name="'identifiers[' + index + '][value]'" x-model="row.value" maxlength="160" aria-label="{{ __('identification.value') }}">
                    <input class="a-input a-input-sm" :name="'identifiers[' + index + '][notes]'" x-model="row.notes" maxlength="255" placeholder="{{ __('identification.notes_placeholder') }}" aria-label="{{ __('identification.notes') }}">
                    @if ($editable)
                        <button type="button" class="a-btn a-btn-ghost a-btn-icon a-btn-sm" :data-id="row.id" @click="remove" title="{{ __('identification.remove') }}">
                            <x-lucide-x aria-hidden="true" /><span class="a-sr-only">{{ __('identification.remove') }}</span>
                        </button>
                    @endif
                </div>
            </template>
            @if ($editable)
                <button type="button" class="a-btn a-btn-outline a-btn-sm mt-2" @click="add">
                    <x-lucide-plus aria-hidden="true" /> {{ __('identification.add') }}
                </button>
            @endif
        </div>
    </fieldset>
    @if ($editable)
        <div class="a-panel-foot flex-wrap">
            <button type="submit" data-shortcut="mod+s" data-shortcut-label="{{ __('common.save') }}" class="a-btn a-btn-primary"><x-lucide-save aria-hidden="true" />{{ __('common.save') }}</button>
            <span class="a-spacer"></span>
            {{-- Import from Excel: its own form below (forms cannot nest), reached with form="…". --}}
            @if (request()->user()?->is_guest)
                @include('reports._locked', ['label' => __('import.locked'), 'tooltip' => __('identification.import.tooltip')])
            @else
                <span class="flex min-w-0 flex-wrap items-center gap-2">
                    <label class="a-micro" for="identification-file">{{ __('identification.import.label') }}</label>
                    <input id="identification-file" type="file" name="file" form="identification-import" required accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
                           class="block max-w-60 min-w-0 text-xs text-ink-muted file:mr-2 file:h-7 file:cursor-pointer file:rounded-md file:border file:border-line-strong file:bg-surface file:px-2 file:text-[10px] file:font-extrabold file:uppercase file:tracking-[0.12em] file:text-ink">
                    <button type="submit" form="identification-import" class="a-btn a-btn-outline a-btn-sm" title="{{ __('identification.import.tooltip') }}"><x-lucide-file-spreadsheet aria-hidden="true" />{{ __('identification.import.button') }}</button>
                    <a href="{{ route('versions.identification.template', $version) }}" class="a-link-btn text-xs" download>{{ __('identification.import.template') }}</a>
                </span>
            @endif
        </div>
        @error('identification_file')
            <p class="a-error px-4 pb-3">{{ $message }}</p>
        @enderror
        @error('file')
            <p class="a-error px-4 pb-3">{{ $message }}</p>
        @enderror
    @endif
</form>
@if ($editable && ! request()->user()?->is_guest)
    <form id="identification-import" method="POST" action="{{ route('versions.identification.import', $version) }}" enctype="multipart/form-data" class="hidden">
        @csrf
    </form>
@endif
