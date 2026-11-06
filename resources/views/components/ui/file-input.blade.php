{{--
    A file field in the app's own style (owner 2026-11-06: no native "Choose files / No file chosen",
    which the browser shows in its own language): a hidden input, a button to choose, the chosen names
    beside it (modules/file-input.js). The same id, name and attributes as a plain input, so forms and
    tests do not change. Give it the same `form`, `accept`, `multiple`, `required` as before.
    <x-ui.file-input id="cert-file" name="certificate_file" accept=".pdf" />
--}}
@props(['id', 'name', 'multiple' => false])
<span class="a-filepick" data-file-input>
    <input type="file" id="{{ $id }}" name="{{ $name }}"{{ $multiple ? ' multiple' : '' }} class="a-sr-only" {{ $attributes }}>
    <label for="{{ $id }}" class="a-btn a-btn-outline a-btn-sm"><x-lucide-folder-open aria-hidden="true" />{{ $multiple ? __('common.choose_files') : __('common.choose_file') }}</label>
    <span class="a-filepick-name" data-file-name data-none="{{ __('common.no_file') }}" data-many="{{ __('common.files_chosen') }}" aria-live="polite">{{ __('common.no_file') }}</span>
</span>
