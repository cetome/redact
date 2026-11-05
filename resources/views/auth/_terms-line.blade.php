{{-- Under the button that creates an account: the terms are accepted by creating it (recorded with their
     version), the privacy policy is information, not something to accept. No box to tick (owner 2026-11-05). --}}
<p class="r-auth-help">
    {{ __('auth.register.terms_line_before') }}
    <a href="{{ route('legal.terms') }}" class="c-link" target="_blank" rel="noopener">{{ __('auth.register.terms_link') }}</a>{{ __('auth.register.terms_line_middle') }}
    <a href="{{ route('legal.privacy') }}" class="c-link" target="_blank" rel="noopener">{{ __('auth.register.privacy_link') }}</a>{{ __('auth.register.terms_line_after') }}
</p>
