<x-layouts.guest :title="__('auth.link.title')">
    <h1 class="c-h1">{{ __('auth.link.heading') }}</h1>
    <p class="c-lead mt-4">{{ __('auth.link.lead') }}</p>

    <form method="POST" action="{{ route('login.link.consume', ['token' => $token]) }}" class="mt-8">
        @csrf
        <button type="submit" class="c-btn c-btn-primary c-btn-lg">{{ __('auth.link.continue') }}</button>
    </form>

    <p class="c-meta mt-8">{{ __('auth.link.why') }}</p>
</x-layouts.guest>
