{{-- Annex V heading of a document (App\Declaration\TechnicalFile::CATEGORIES). @include('declaration._category-select', ['name', 'id', 'value']) --}}
<select name="{{ $name }}" id="{{ $id }}" class="a-select a-input-sm a-doc-category" aria-label="{{ __('declaration.file.category') }}" title="{{ __('declaration.file.category_tip') }}">
    <option value="">{{ __('declaration.file.category_none') }}</option>
    @foreach (\App\Declaration\TechnicalFile::CATEGORIES as $key => $heading)
        <option value="{{ $key }}" @selected(($value ?? null) === $key)>{{ __('declaration.file.categories.'.$key) }}</option>
    @endforeach
</select>
