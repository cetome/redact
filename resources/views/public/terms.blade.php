{{-- Terms and legal notice: the publisher and host first (legal notice), then the terms of use. Text in lang/*/terms.php (not legal.php: __('Legal') in the brand footer would load it on Windows). --}}
@php
    $email = config('redact.security_contact');
    $members = (int) config('redact.plans.free.team_members', 1);
    $fill = [
        ':hours' => e(\App\Guest\Sandbox::ttlHours()),
        ':email' => '<a class="c-link" href="mailto:'.e($email).'">'.e($email).'</a>',
        ':pgp' => '<a class="c-link" href="'.e(config('redact.security_pgp.url')).'">'.e(__('terms.links.pgp')).'</a>',
        ':fingerprint' => '<code>'.e(config('redact.security_pgp.fingerprint')).'</code>',
        ':price' => e(\App\Billing\Plan::priceLabel()),
        ':ai_units' => e((int) config('redact.plans.paid.ai_units', 500)),
        ':free_team' => e(trans_choice('public.pricing.plans.free.team', $members, ['members' => $members])),
        ':talk' => '<a class="c-link" href="'.e(\App\Billing\Plan::contactUrl('self-hosted')).'">'.e(__('terms.links.talk')).'</a>',
    ];
@endphp
<x-layouts.guest :title="__('nav.terms')">
    <article class="c-text r-legal">
        <h1 class="c-h1">{{ __('nav.terms') }}</h1>
        <p class="c-meta">{{ __('terms.version') }}</p>

        <h2 class="c-h2" id="notice">{{ __('terms.notice_title') }}</h2>
        <dl class="r-legal-dl">
            @foreach (__('terms.notice') as $key => [$label, $value])
                <dt>{{ $label }}</dt>
                <dd>
                    @if ($key === 'contact')
                        <a class="c-link" href="{{ route('contact') }}">{{ $value }}</a>
                    @else
                        {{ $value }}
                    @endif
                </dd>
            @endforeach
        </dl>

        <h2 class="c-h2" id="terms">{{ __('terms.terms_title') }}</h2>
        @foreach (__('terms.terms') as $key => [$heading, $paragraphs])
            <section id="{{ $key }}">
                <h3 class="c-h3">{{ $loop->iteration }}. {{ $heading }}</h3>
                @foreach ($paragraphs as $paragraph)
                    <p>{!! strtr(e($paragraph), $fill) !!}</p>
                @endforeach
            </section>
        @endforeach
    </article>
</x-layouts.guest>
