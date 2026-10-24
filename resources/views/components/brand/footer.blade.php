{{--
    cetome.com footer: white logo and company registrations, link columns, then the legal line.
    Dark (brand-dark) in both themes.

    <x-brand.footer>
        <nav class="c-footer-column" aria-label="{{ __('REDact') }}">
            <h2 class="c-footer-label">{{ __('REDact') }}</h2>
            <ul class="c-footer-links">
                <li><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
            </ul>
        </nav>
        <x-slot:legal>
            <a href="{{ route('privacy') }}">{{ __('Privacy policy') }}</a>
        </x-slot:legal>
    </x-brand.footer>

    Default slot: extra columns, placed between the brand column and the "cetome" column.
    `legal` slot: replaces the legal links (About, Security, Legal, Privacy policy, LinkedIn on
    cetome.com) when given.
    :cetome="false" drops the "cetome" column of links to cetome.com. "Talk to us" is not in it: REDact
    has its own contact form, linked from the REDact column (layouts/footer).
--}}
@props([
    'cetome' => true,
])

@php
    $site = 'https://cetome.com';
@endphp

<footer {{ $attributes->class(['c-footer']) }}>
    <div class="c-footer-inner">
        <div class="c-footer-grid">
            <div class="c-footer-brand">
                <a href="{{ $site }}/" aria-label="{{ __('cetome.com') }}">
                    <x-brand.logo variant="white" alt="cetome" />
                </a>
                <ul class="c-footer-registration">
                    <li>{{ __('England and Wales (No. 11074299)') }}</li>
                    <li>{{ __('France (SIRET: 849 836 101 00014)') }}</li>
                </ul>
            </div>

            {{ $slot }}

            @if ($cetome)
                <nav class="c-footer-column" aria-label="{{ __('cetome') }}">
                    <h2 class="c-footer-label">{{ __('cetome') }}</h2>
                    <ul class="c-footer-links">
                        <li><a href="{{ $site }}/services/">{{ __('Advisory') }}</a></li>
                        <li><a href="{{ $site }}/training/">{{ __('Training') }}</a></li>
                        <li><a href="{{ $site }}/insights/">{{ __('Insights') }}</a></li>
                    </ul>
                </nav>
            @endif
        </div>

        <div class="c-footer-bottom">
            <div class="c-footer-copyright">{{ __('© cetome 2017-:year. All rights reserved.', ['year' => date('Y')]) }}</div>
            <nav class="c-footer-legal" aria-label="{{ __('Legal') }}">
                @if (isset($legal) && trim((string) $legal) !== '')
                    {{ $legal }}
                @else
                    <a href="{{ $site }}/about/">{{ __('About') }}</a>
                    <a href="{{ $site }}/security/">{{ __('Security') }}</a>
                    <a href="{{ $site }}/legal/">{{ __('Legal') }}</a>
                    <a href="{{ $site }}/privacy/">{{ __('Privacy policy') }}</a>
                    <a href="https://linkedin.com/company/cetome" target="_blank" rel="noopener">{{ __('LinkedIn') }}</a>
                @endif
            </nav>
        </div>
    </div>
</footer>
