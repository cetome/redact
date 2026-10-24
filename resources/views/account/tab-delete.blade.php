{{-- Account, Delete tab: the danger zone (recent sign-in required). --}}
<section class="a-panel a-panel-danger" aria-labelledby="delete-heading">
    <div class="a-panel-head">
        <div>
            <h2 id="delete-heading" class="a-h3">{{ __('auth.delete.heading') }}</h2>
            <p class="a-help mt-0.5">{{ __('auth.delete.lead') }}</p>
        </div>
    </div>
    <div class="a-panel-body">
        @if ($recent)
            <form method="POST" action="{{ route('account.destroy') }}" class="flex flex-wrap items-center gap-3"
                x-data="confirmSubmit" data-confirm="{{ __('auth.delete.confirm_dialog') }}" x-on:submit="check">
                @csrf
                @method('DELETE')
                <label class="a-check">
                    <input type="checkbox" name="confirm" value="1" required @error('confirm') aria-invalid="true" @enderror>
                    <span>{{ __('auth.delete.confirm_label') }}</span>
                </label>
                <button type="submit" class="a-btn a-btn-danger"><x-lucide-trash-2 aria-hidden="true" />{{ __('auth.delete.submit') }}</button>
                @error('confirm')
                    <p class="a-error w-full">{{ $message }}</p>
                @enderror
            </form>
        @else
            <div class="flex flex-wrap items-center gap-3">
                <p class="a-help">{{ __('auth.delete.confirm_first') }}</p>
                <a href="{{ route('account.reconfirm', ['tab' => 'delete']) }}" class="a-btn a-btn-outline"><x-lucide-shield-check aria-hidden="true" />{{ __('auth.account.confirm_button') }}</a>
            </div>
        @endif
    </div>
</section>
