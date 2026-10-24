{{-- Privacy policy. Text in lang/*/privacy_policy.php; retention periods and the AI provider come from the config. --}}
@php
    $months = fn (string $key, int $default) => e((int) round((int) config('redact.retention.'.$key, $default) / 30.42));
    $provider = app(\App\Ai\AiSettings::class)->provider();
    $fill = [
        ':notice' => '<a class="c-link" href="'.e(route('legal.terms')).'#notice">'.e(__('privacy_policy.links.notice')).'</a>',
        ':contact' => '<a class="c-link" href="'.e(route('contact.topic', 'privacy')).'">'.e(__('privacy_policy.links.contact')).'</a>',
        ':hours' => e(\App\Guest\Sandbox::ttlHours()),
        ':unverified_days' => e((int) config('redact.auth.purge_unverified_days', 7)),
        ':remember_days' => e(\App\Auth\Remember::days()),
        ':requests_months' => $months('request_logs_days', 395),
        ':activity_months' => $months('activity_log_days', 730),
        ':security_months' => $months('security_log_days', 365),
        ':contact_months' => $months('contact_messages_days', 365),
        ':ai_provider' => e(__('privacy_policy.providers.'.$provider)),
        ':ai_training' => e(trans()->has('privacy_policy.training.'.$provider) ? __('privacy_policy.training.'.$provider) : ''),
    ];
    $text = fn (string $value) => strtr(e($value), $fill);
    $colon = \App\Support\Locales::detail('colon');
@endphp
<x-layouts.guest :title="__('nav.privacy')">
    <article class="c-text r-legal">
        <h1 class="c-h1">{{ __('nav.privacy') }}</h1>
        <p class="c-meta">{{ __('privacy_policy.version') }}</p>

        @foreach (__('privacy_policy.sections') as $key => $section)
            <section id="{{ $key }}">
                <h2 class="c-h3">{{ $section[0] }}</h2>
                @foreach ($section[1] as $paragraph)
                    <p>{!! $text($paragraph) !!}</p>
                @endforeach
                @if ($key === 'processors')
                    <dl class="r-legal-dl mt-2">
                        @foreach ($section[2] as [$name, $role])
                            <dt>{!! ucfirst(strtr(e($name), $fill)) !!}</dt>
                            <dd>{{ $role }}</dd>
                        @endforeach
                    </dl>
                @elseif (isset($section[2]))
                    <ul class="list-disc pl-6 space-y-2 mt-2">
                        @foreach ($section[2] as $item => [$label, $value])
                            <li id="data-{{ $item }}"><strong>{{ $label }}</strong>{{ $colon }}{!! $text($value) !!}</li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </article>
</x-layouts.guest>
