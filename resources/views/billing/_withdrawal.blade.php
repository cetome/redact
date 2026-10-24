{{-- Required before any payment (BillingController::consent): immediate start and the EU consumer withdrawal right. --}}
<label class="a-check">
    <input type="checkbox" name="withdrawal" value="1" required @checked(old('withdrawal')) @error('withdrawal') aria-invalid="true" aria-describedby="withdrawal-error" @enderror>
    <span>{{ __('plans.consent.withdrawal') }} <a href="{{ route('legal.terms') }}#consumers" class="a-link-btn" target="_blank" rel="noopener">{{ __('plans.consent.terms') }}</a></span>
</label>
@error('withdrawal')<p class="a-error" id="withdrawal-error">{{ $message }}</p>@enderror
