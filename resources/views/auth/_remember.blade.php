{{-- "Keep me signed in", above the button of each way in (Passkey, E-mail code). Both boxes stay in step
     (login-method.js); the e-mail form sends its own, the passkey button reads the first (passkeyLogin). --}}
<label class="mb-4 flex items-start gap-3" for="{{ $id }}">
    <input type="checkbox" id="{{ $id }}" name="remember" value="1" class="c-check mt-1" data-remember @checked(old('remember'))>
    <span>{{ __('auth.login.remember', ['days' => $rememberDays]) }}</span>
</label>
