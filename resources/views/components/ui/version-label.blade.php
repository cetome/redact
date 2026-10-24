{{--
    "Product · Version 2.0" (· standard): the eyebrow of every version page.
    <x-slot:eyebrow><x-ui.version-label :version="$version" :standard="$standard" /></x-slot:eyebrow>
--}}
@props(['version', 'standard' => null])
{{ $version->product->name }} · {{ __('versions.version') }} {{ $version->label }}@if ($standard) · {{ app(\App\Content\ContentPack::class)->standardName($standard) }}@endif
