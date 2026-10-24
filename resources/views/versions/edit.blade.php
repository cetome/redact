<x-layouts.app :title="__('description.title')">
    <x-slot:subnav><x-version-nav :version="$version" active="description" /></x-slot:subnav>
    <x-read-only-notice :access="$access" :version="$version" />
    @php($editable = $access->canEdit($version))

    <x-ui.page-header :title="__('description.title')" :subtitle="__('description.intro')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
    </x-ui.page-header>

    <div class="a-stack">

        {{-- The product card (same block as the Overview and Product settings), with the step's own fields. --}}
        <x-product.card :product="$version->product" :version="$version" :models="false" :action="route('versions.update', $version)" form-id="description-form"
                        :editable="$editable" :version-editable="$editable" :ai="true" :description-max="10000">
            <x-slot:extra>
                <fieldset @disabled(! $editable) class="a-pcard-block a-form-grid">
                    <div class="a-field a-span-all">
                        <label class="a-label" for="intended_use">{{ __('description.intended_use') }}</label>
                        <textarea id="intended_use" name="intended_use" class="a-textarea" rows="3" maxlength="10000" @error('intended_use') aria-invalid="true" @enderror>{{ old('intended_use', $version->intended_use) }}</textarea>
                        <p class="a-help">{{ __('description.intended_use_help') }}</p>
                        @error('intended_use')<p class="a-error">{{ $message }}</p>@enderror
                        <div class="flex flex-wrap items-center gap-2">
                            <x-ai.origin type="product_version" :key="$version->id" field="intended_use" :value="$version->intended_use" />
                            @if ($editable)
                                <x-ai.assist :version="$version" type="product_version" :key="$version->id" field="intended_use" />
                            @endif
                        </div>
                    </div>
                    <div class="a-field">
                        <label class="a-label" for="author_name">{{ __('description.author') }}</label>
                        <input id="author_name" name="author_name" class="a-input" maxlength="120" value="{{ old('author_name', $version->author_name) }}" @error('author_name') aria-invalid="true" @enderror>
                        @error('author_name')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="a-field">
                        <label class="a-label" for="completion_date">{{ __('description.completion_date') }}</label>
                        <input id="completion_date" type="date" name="completion_date" class="a-input" value="{{ old('completion_date', $version->completion_date?->toDateString()) }}" @error('completion_date') aria-invalid="true" @enderror>
                        @error('completion_date')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                </fieldset>
            </x-slot:extra>
            @if ($editable)
                <x-slot:actions>
                    <button type="submit" data-shortcut="mod+s" data-shortcut-label="{{ __('common.save') }}" class="a-btn a-btn-outline"><x-lucide-save aria-hidden="true" />{{ __('common.save') }}</button>
                    <button type="submit" name="continue" value="1" class="a-btn a-btn-primary" title="{{ __('workflow.next_step_go', ['step' => \App\Assessment\Assessment::for($version)->nextStepAfter('description')['label'] ?? '']) }}">{{ __('scoping.continue') }}<x-lucide-arrow-right aria-hidden="true" /></button>
                </x-slot:actions>
            @endif
        </x-product.card>

        @include('versions._identification', ['editable' => $editable])
    </div>
</x-layouts.app>
