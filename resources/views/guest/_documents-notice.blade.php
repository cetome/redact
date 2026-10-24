{{-- Documents panel of a sandbox guest: uploading needs an account (GuestSandbox ACCOUNT_REQUIRED). --}}
<div class="a-stack gap-2!">
    <p class="a-help">{{ __('guest.documents') }}</p>
    <div>@include('reports._locked', ['label' => __('ai.documents.locked'), 'tooltip' => __('ai.documents.locked_tooltip')])</div>
</div>
