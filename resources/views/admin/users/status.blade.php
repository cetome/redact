{{-- Status badges of one account. Expects $user with roles loaded. --}}
<span class="inline-flex flex-wrap gap-1">
    @if ($user->active)
        <span class="a-badge a-badge-success">{{ __('admin.users.statuses.active') }}</span>
    @else
        <span class="a-badge a-badge-danger">{{ __('admin.users.statuses.disabled') }}</span>
    @endif
    @if ($user->is_guest)
        <span class="a-badge a-badge-warning">{{ __('admin.users.statuses.guests') }}</span>
    @endif
    @unless ($user->email_verified_at)
        <span class="a-badge a-badge-warning">{{ __('admin.users.statuses.unverified') }}</span>
    @endunless
    @foreach ($user->roles as $role)
        <span class="a-badge">{{ \App\Admin\Label::of('roles', $role->name) }}</span>
    @endforeach
</span>
