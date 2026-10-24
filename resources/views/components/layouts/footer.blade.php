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
        <a href="{{ url('/.well-known/security.txt') }}">{{ __('nav.security') }}</a>
    </x-slot:legal>
</x-brand.footer>
