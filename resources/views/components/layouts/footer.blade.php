<x-brand.footer>
    <nav class="c-footer-column" aria-label="REDact">
        <h2 class="c-footer-label">REDact</h2>
        <ul class="c-footer-links">
            <li><x-try-link>{{ __('nav.dashboard') }}</x-try-link></li>
            <li><a href="{{ route('about') }}">{{ __('nav.about') }}</a></li>
            <li><a href="{{ route('pricing') }}">{{ __('nav.pricing') }}</a></li>
            <li><a href="{{ route('templates') }}">{{ __('templates.nav') }}</a></li>
            <li><a href="{{ route('trees.index') }}">{{ __('nav.trees') }}</a></li>
            <li><a href="{{ route('contact') }}">{{ __('nav.contact') }}</a></li>
        </ul>
    </nav>
    <x-slot:legal>
        <a href="{{ route('legal.terms') }}">{{ __('nav.terms') }}</a>
        <a href="{{ route('legal.privacy') }}">{{ __('nav.privacy') }}</a>
        {{-- The security page (how to report an issue), as on the contact page; security.txt stays for scanners. --}}
        @if (filled(config('redact.security_url')))
            <a href="{{ config('redact.security_url') }}" target="_blank" rel="noopener">{{ __('nav.security') }}</a>
        @endif
    </x-slot:legal>
</x-brand.footer>
