{{--
    Error pages. Standalone on purpose: an error can happen before the session, the
    authentication or the CSP middleware ran, so nothing here may depend on them.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $code }} · {{ $title }} ◼ REDact</title>
    <link rel="icon" href="{{ asset('brand/redact-favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-canvas text-ink font-brand antialiased">
    <main class="mx-auto flex min-h-screen max-w-xl flex-col items-center justify-center px-6 text-center">
        <a href="{{ url('/') }}" aria-label="REDact">
            <img src="{{ asset('brand/redact.svg') }}" alt="cetome REDact" width="190" height="50" class="h-12 w-auto">
        </a>
        <p class="mt-10 text-[13px] font-black uppercase tracking-[0.3em] text-brand-blue">{{ $code }}</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight">{{ $title }}</h1>
        <p class="mt-3 text-ink-muted">{{ $message }}</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ url('/dashboard') }}" class="c-btn c-btn-primary"><x-lucide-arrow-left class="size-4" aria-hidden="true" /> {{ __('errors.home') }}</a>
            <a href="{{ url('/contact') }}" class="c-btn c-btn-ghost"><x-lucide-mail class="size-4" aria-hidden="true" /> {{ __('errors.contact') }}</a>
        </div>
    </main>
</body>
</html>
