{{--
    The language switch in the headers (owner 2026-11-05: nobody is held in a language they did not
    choose): the current language with an icon, the others in a small menu. Works without script
    (details + one form per language, LocaleController). Signed in, it changes the account's language.
    <x-ui.locale-switch />
--}}
@props(['full' => false])
@php
    $locales = (array) config('redact.locales');
    $current = app()->getLocale();
@endphp
@if (count($locales) > 1)
    <details {{ $attributes->class(['a-menu']) }}>
        <summary @class(['a-btn a-btn-sm a-locale-btn', 'a-btn-ghost' => ! $full, 'a-btn-outline' => $full]) aria-label="{{ __('nav.language_current', ['name' => $locales[$current] ?? $current]) }}" title="{{ __('nav.language') }}">
            <x-lucide-globe aria-hidden="true" /><span aria-hidden="true">{{ $full ? ($locales[$current] ?? $current) : strtoupper($current) }}</span>
        </summary>
        <div class="a-menu-panel a-menu-panel-narrow">
            @foreach ($locales as $code => $label)
                <form method="POST" action="{{ route('locale.update') }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $code }}">
                    <button type="submit" class="a-menu-item" lang="{{ $code }}" @if ($code === $current) aria-current="true" @endif>
                        {{ $label }}
                        @if ($code === $current)<x-lucide-check class="ml-auto" aria-hidden="true" />@endif
                    </button>
                </form>
            @endforeach
        </div>
    </details>
@endif
