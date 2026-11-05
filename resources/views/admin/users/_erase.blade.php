{{--
    Console, a person's page: delete the account for good (super-admin, UserController::erase,
    App\Admin\AccountEraser). What goes with it, what stops it, then the typed address and the
    buttons: with a refund of the last payment, or without.
--}}
@php
    $first = $erase['refundable'][0] ?? null;
@endphp
<section class="a-panel mt-4" aria-labelledby="erase-heading">
    <div class="a-panel-head"><h2 id="erase-heading" class="a-h3">{{ __('admin.users.erase.title') }}</h2></div>
    <div class="a-panel-body a-stack gap-3!">
        <p>{{ __('admin.users.erase.lead', ['email' => $user->email]) }}</p>
        <ul class="a-plan-features">
            @forelse ($erase['organisations'] as $organisation)
                @php $count = \App\Models\Product::withTrashed()->where('organisation_id', $organisation->id)->count(); @endphp
                <li><x-lucide-building-2 aria-hidden="true" />{{ trans_choice('admin.users.erase.organisation', $count, ['name' => $organisation->name, 'count' => $count]) }}</li>
            @empty
                <li><x-lucide-user aria-hidden="true" />{{ __('admin.users.erase.nothing') }}</li>
            @endforelse
            @if ($erase['paid']->isNotEmpty())
                <li><x-lucide-credit-card aria-hidden="true" />{{ __('admin.users.erase.stripe') }}</li>
            @endif
            @if ($user->organisations->count() > $erase['organisations']->count())
                <li><x-lucide-users aria-hidden="true" />{{ __('admin.users.erase.shared') }}</li>
            @endif
        </ul>

        @if ($erase['blockers'] !== [])
            <div class="a-alert a-alert-warning" role="status">
                <x-lucide-triangle-alert aria-hidden="true" />
                <span>{{ implode(' ', $erase['blockers']) }}</span>
            </div>
        @else
            <form method="POST" action="{{ route('admin.users.erase', $user) }}" class="a-stack gap-3!" x-data="confirmSubmit"
                  data-confirm="{{ __('admin.users.erase.confirm', ['email' => $user->email]) }}" @submit="check">
                @csrf
                @method('DELETE')
                <div class="a-field max-w-96">
                    <label for="erase-email" class="a-label">{{ __('admin.users.erase.email', ['email' => $user->email]) }}</label>
                    <input id="erase-email" name="email" type="text" class="a-input" required autocomplete="off" spellcheck="false"
                           @error('email', 'erase') aria-invalid="true" @enderror>
                    @error('email', 'erase')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                @if ($erase['stripeError'])
                    <p class="a-help">{{ __('admin.users.erase.stripe_error') }}</p>
                @elseif ($erase['paid']->isNotEmpty() && $first === null)
                    <p class="a-help">{{ __('admin.users.erase.no_payment') }}</p>
                @endif
                <div class="flex flex-wrap gap-2">
                    @if ($first !== null)
                        <button type="submit" name="refund" value="1" class="a-btn a-btn-danger a-btn-sm">
                            <x-lucide-undo-2 aria-hidden="true" />{{ __('admin.users.erase.refund', ['amount' => $first['amount'], 'days' => $first['days'], 'total' => $first['total'], 'paid' => $first['paid'], 'date' => $first['date']]) }}</button>
                    @endif
                    <button type="submit" name="refund" value="0" class="a-btn {{ $first !== null ? 'a-btn-outline' : 'a-btn-danger' }} a-btn-sm">
                        <x-lucide-trash-2 aria-hidden="true" />{{ $erase['paid']->isNotEmpty() ? __('admin.users.erase.no_refund') : __('admin.users.erase.delete') }}</button>
                </div>
            </form>
        @endif
    </div>
</section>
