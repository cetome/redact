{{--
    Labelled form field with help and error (app UI kit: .a-field, .a-label, .a-help, .a-error).
    <x-field name="name" :label="__('products.name')" :help="__('…')">
        <input id="name" name="name" class="a-input" …>
    </x-field>
--}}
@props(['name', 'label', 'help' => null, 'for' => null])
<div {{ $attributes->class(['a-field']) }}>
    <label class="a-label" for="{{ $for ?? $name }}">{{ $label }}</label>
    {{ $slot }}
    @if ($help)
        <p class="a-help">{{ $help }}</p>
    @endif
    @error($name)
        <p class="a-error">{{ $message }}</p>
    @enderror
</div>
