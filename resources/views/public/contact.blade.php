{{--
    Contact form (App\Http\Controllers\ContactController), after cetome.com's: name, e-mail, company,
    a topic (/contact/{topic} preselects one), the message. The ALTCHA proof is solved invisibly on page load
    (contact-form.js); when the check fails, or the server refused it, the widget is shown as a
    checkbox. The honeypot ("website") sits off-screen. Sent: the same page says so.
--}}
@php
    $altchaVisible = $errors->has('altcha');
    $months = max(1, (int) round(((int) config('redact.retention.contact_messages_days', 365)) / 30.4));
    $security = (string) config('redact.security_contact');
@endphp
<x-layouts.guest :bare="true" :index="true" :inline-errors="true" :page-title="__('contact.meta.title')" :description="__('contact.meta.description')" :json-ld="$jsonLd" :canonical="route('contact')">
    <section class="c-hero r-hero-short">
        <div class="c-hero-grid">
            <div class="c-hero-main">
                <p class="c-hero-kicker">{{ __('public.kicker') }}</p>
                <h1 class="c-hero-title">{{ __('contact.title') }}</h1>
                <p class="c-hero-lead">{{ __('contact.lead') }}</p>
            </div>
        </div>
    </section>

    <section class="c-section">
        <div class="c-container grid items-start gap-12 lg:grid-cols-12 lg:gap-16">
            <div class="lg:col-span-8">
                @if ($sent)
                    <div class="flex flex-col items-center gap-3 rounded-xl border border-line bg-surface p-8 text-center sm:p-12" role="status">
                        <span class="flex size-10 items-center justify-center bg-brand-blue text-white"><x-lucide-check class="size-6" aria-hidden="true" /></span>
                        <h2 class="c-h3 text-brand-blue dark:text-brand-blue-night">{{ __('contact.sent_title') }}</h2>
                        <p class="c-text text-ink-muted">{{ __('contact.sent_text') }}</p>
                        <a href="{{ route('contact') }}" class="c-link mt-4 font-bold">← {{ __('contact.another') }}</a>
                    </div>
                @else
                    <form method="POST" action="{{ route('contact.send') }}" class="relative grid gap-x-12 gap-y-10 sm:grid-cols-2" data-contact-form>
                        @csrf

                        {{-- Honeypot: off-screen, never filled by a person. --}}
                        <div class="absolute -left-[10000px] top-0 h-px w-px overflow-hidden" aria-hidden="true">
                            <label for="contact-website">Website</label>
                            <input type="text" id="contact-website" name="website" value="" tabindex="-1" autocomplete="off">
                        </div>

                        <div class="c-field">
                            <label for="contact-name" class="c-label">{{ __('contact.fields.name') }}</label>
                            <input type="text" id="contact-name" name="name" class="c-input" value="{{ old('name', $name) }}" maxlength="100" autocomplete="name" required
                                placeholder="{{ __('contact.placeholders.name') }}" @error('name') aria-invalid="true" aria-describedby="contact-name-error" @enderror>
                            @error('name')<p id="contact-name-error" class="c-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="c-field">
                            <label for="contact-email" class="c-label">{{ __('contact.fields.email') }}</label>
                            <input type="email" id="contact-email" name="email" class="c-input" value="{{ old('email', $email) }}" maxlength="150" autocomplete="email" required
                                placeholder="{{ __('contact.placeholders.email') }}" @error('email') aria-invalid="true" aria-describedby="contact-email-error" @enderror>
                            @error('email')<p id="contact-email-error" class="c-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="c-field">
                            <label for="contact-company" class="c-label">{{ __('contact.fields.company') }} <span class="font-medium normal-case tracking-normal text-ink-subtle">({{ __('contact.optional') }})</span></label>
                            <input type="text" id="contact-company" name="company" class="c-input" value="{{ old('company') }}" maxlength="150" autocomplete="organization"
                                placeholder="{{ __('contact.placeholders.company') }}" @error('company') aria-invalid="true" aria-describedby="contact-company-error" @enderror>
                            @error('company')<p id="contact-company-error" class="c-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="c-field">
                            <label for="contact-topic" class="c-label">{{ __('contact.fields.topic') }}</label>
                            <select id="contact-topic" name="topic" class="c-select" required @error('topic') aria-invalid="true" aria-describedby="contact-topic-error" @enderror>
                                <option value="" disabled @selected(old('topic', $topic) === '')>{{ __('contact.choose') }}</option>
                                @foreach ($topics as $value)
                                    <option value="{{ $value }}" @selected(old('topic', $topic) === $value)>{{ __('contact.topics.'.$value) }}</option>
                                @endforeach
                            </select>
                            @error('topic')<p id="contact-topic-error" class="c-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="c-field sm:col-span-2">
                            <label for="contact-message" class="c-label">{{ __('contact.fields.message') }}</label>
                            <textarea id="contact-message" name="message" class="c-textarea" rows="6" maxlength="5000" required
                                placeholder="{{ __('contact.placeholders.message') }}" @error('message') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ old('message') }}</textarea>
                            @error('message')<p id="contact-message-error" class="c-error">{{ $message }}</p>@enderror
                        </div>

                        <div class="sm:col-span-2 flex flex-col gap-4">
                            @error('altcha')<p class="c-error" role="alert">{{ $message }}</p>@enderror
                            <altcha-widget challenge="{{ route('contact.altcha') }}" name="altcha" auto="onload" @unless ($altchaVisible) display="invisible" @endunless
                                language="{{ \App\Support\Locales::detail('altcha') }}"></altcha-widget>
                            @error('form')<p class="c-error" role="alert">{{ $message }}</p>@enderror

                            <p class="c-help">{{ __('contact.privacy', ['months' => $months]) }} <a href="{{ route('legal.privacy') }}" class="c-link">{{ __('contact.privacy_link') }}</a></p>

                            <div>
                                <button type="submit" class="c-btn c-btn-primary c-btn-lg r-start-btn" data-contact-submit>
                                    <x-lucide-loader-circle class="r-start-spinner" aria-hidden="true" />
                                    <x-lucide-send class="r-start-icon" aria-hidden="true" />
                                    <span data-contact-label data-busy="{{ __('contact.sending') }}">{{ __('contact.submit') }}</span>
                                </button>
                            </div>
                        </div>
                    </form>
                @endif
            </div>

            <aside class="border-line lg:col-span-4 lg:border-l lg:pl-8">
                <h2 class="c-kicker">{{ __('contact.next_title') }}</h2>
                <p class="c-text">{{ __('contact.next') }}</p>
                <p class="c-text mt-4">{!! __('contact.security', ['email' => '<a class="c-link" href="mailto:'.e($security).'">'.e($security).'</a>']) !!}</p>
            </aside>
        </div>
    </section>
</x-layouts.guest>
