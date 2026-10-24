{{--
    Light/dark switch, icon only: a moon in the light theme, a sun in the dark one. The label and
    the tooltip say what a click does ("Dark theme" / "Light theme"). Alpine `themeToggle` (core.js).
    <x-ui.theme-toggle />
--}}
<button type="button" {{ $attributes->class(['a-btn a-btn-ghost a-btn-icon']) }} x-data="themeToggle" @click="toggle"
    data-dark-label="{{ __('common.theme_dark') }}" data-light-label="{{ __('common.theme_light') }}"
    x-bind:aria-label="label" x-bind:title="label" aria-label="{{ __('common.theme_dark') }}" title="{{ __('common.theme_dark') }}">
    <x-lucide-moon class="c-only-light" aria-hidden="true" />
    <x-lucide-sun class="c-only-dark" aria-hidden="true" />
</button>
