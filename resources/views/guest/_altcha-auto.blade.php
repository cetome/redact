{{--
    Invisible ALTCHA for the forms that open a sandbox (home tool, /try). Must sit inside a
    form[data-sandbox-start]: sandbox-start.js reads the widget's state, shows it in the status
    line (role="status", so screen readers hear it) and sends the form once the proof is ready.
    The proof is still checked on the server (App\Guest\OpenSandbox).
--}}
<altcha-widget challenge="{{ route('guest.altcha') }}" name="altcha" auto="onload" display="invisible"
    language="{{ \App\Support\Locales::detail('altcha') }}"></altcha-widget>
<p class="r-start-status" data-start-status
   data-verifying="{{ __('guest.start.check.verifying') }}" data-ready="{{ __('guest.start.check.ready') }}"
   data-preparing="{{ __('guest.start.check.preparing') }}" data-failed="{{ __('guest.start.check.failed') }}">
    <x-lucide-shield-check aria-hidden="true" />
    <span role="status" data-start-status-text></span>
</p>
@error('altcha')
    <p class="a-error r-start-error" role="alert">{{ $message }}</p>
@enderror
