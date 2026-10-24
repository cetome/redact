{{--
    "New product" = the RED scoping: name the product, tick what applies, see the EN 18031
    parts in scope live, create. Used by products/create and by the empty dashboard.
--}}
@php
    $pack = app(\App\Content\ContentPack::class);
    $form = app(\App\Assessment\ScopingForm::class);
    $aiReason = \App\Ai\Availability::reasonForNew(auth()->user(), $organisations);
@endphp
<form method="POST" action="{{ route('products.store') }}" class="a-grid-main" x-data="scopingLive"
      data-scoping="{{ json_encode($form->liveMap()) }}" @change="refresh">
    @csrf
    <div class="a-stack min-w-0">
        <section class="a-panel">
            <div class="a-panel-body a-form-grid">
                <div class="a-stack gap-3!">
                    <div class="a-field">
                        <label class="a-label" for="name">{{ __('scoping.name') }}</label>
                        <input id="name" name="name" class="a-input" value="{{ old('name') }}" maxlength="160"
                               placeholder="{{ __('scoping.name_placeholder') }}" autofocus @error('name') aria-invalid="true" @enderror>
                        @error('name')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                    @if ($organisations->count() > 1)
                        <div class="a-field">
                            <label class="a-label" for="organisation_id">{{ __('products.organisation') }}</label>
                            <select id="organisation_id" name="organisation_id" class="a-select" required>
                                @foreach ($organisations as $organisation)
                                    <option value="{{ $organisation->id }}" @selected(old('organisation_id') === $organisation->id)>{{ $organisation->name }}</option>
                                @endforeach
                            </select>
                            @error('organisation_id')<p class="a-error">{{ $message }}</p>@enderror
                        </div>
                    @else
                        <input type="hidden" name="organisation_id" value="{{ $organisations->first()->id }}">
                    @endif
                </div>
                <div class="a-field">
                    <label class="a-label" for="description">{{ __('scoping.what') }} <span class="a-label-hint">{{ __('scoping.what_hint') }}</span></label>
                    <textarea id="description" name="description" class="a-textarea a-textarea-sm" rows="3" maxlength="5000"
                              placeholder="{{ __('scoping.what_placeholder') }}" aria-describedby="description-help"
                              @error('description') aria-invalid="true" @enderror>{{ old('description') }}</textarea>
                    @error('description')<p class="a-error">{{ $message }}</p>@enderror
                    <p class="a-help" id="description-help">{{ __('scoping.what_help') }}</p>
                    @include('scoping._ai', ['mode' => 'new', 'url' => route('ai.scoping.new'), 'reason' => $aiReason])
                </div>
            </div>
        </section>

        @include('scoping._toggles', ['pack' => $pack, 'answers' => old('answers') ? array_map(fn ($v) => $v === '1', (array) old('answers')) : []])
    </div>

    <aside class="a-aside-sticky">
        <section class="a-panel">
            @include('scoping._result', ['pack' => $pack])
            <div class="a-panel-foot">
                <button type="submit" class="a-btn a-btn-primary a-btn-block"><x-lucide-plus aria-hidden="true" />{{ __('scoping.start') }}</button>
            </div>
        </section>
    </aside>
</form>
