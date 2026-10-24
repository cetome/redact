{{--
    "Start a new version" fields (POST versions.store): label and the version it starts from.
    @include('versions._new-version', ['product' => $product, 'formId' => 'new-version-form'])
--}}
<form id="{{ $formId }}" method="POST" action="{{ route('versions.store', $product) }}" class="a-stack gap-3!">
    @csrf
    <div class="a-field">
        <label class="a-label" for="{{ $formId }}-label">{{ __('versions.label') }}</label>
        <input id="{{ $formId }}-label" name="label" class="a-input" required maxlength="40" value="{{ old('label') }}" @error('label') aria-invalid="true" @enderror>
        <p class="a-help">{{ __('versions.label_help') }}</p>
        @error('label')<p class="a-error">{{ $message }}</p>@enderror
    </div>
    @if ($product->versions->isNotEmpty())
        <div class="a-field">
            <label class="a-label" for="{{ $formId }}-from">{{ __('versions.previous') }}</label>
            <select id="{{ $formId }}-from" name="from" class="a-select">
                @foreach ($product->versions as $existing)
                    <option value="{{ $existing->id }}" @selected($loop->first)>{{ $existing->label }}</option>
                @endforeach
                <option value="">{{ __('versions.blank') }}</option>
            </select>
            <p class="a-help">{{ __('versions.new_help') }}</p>
        </div>
    @endif
</form>
