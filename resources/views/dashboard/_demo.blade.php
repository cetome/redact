{{--
    "Load the demo" / "Open the demo" for an account (DashboardController: $demo, the demo of its
    organisation). Loading posts to /demo (DemoController::account); one demo per organisation.
--}}
@if ($demo['product'])
    <a href="{{ $demo['product']->homeUrl() }}" class="a-btn a-btn-ghost"><x-lucide-lightbulb aria-hidden="true" />{{ __('products.demo.open') }}</a>
@else
    <form method="POST" action="{{ route('demo.load') }}">
        @csrf
        <button type="submit" class="a-btn a-btn-outline"><x-lucide-lightbulb aria-hidden="true" />{{ __('products.demo.load') }}</button>
    </form>
@endif
