{{--
    The contact form for a signed-in account (Support in the account menu), in the app layout and its
    form style (owner 2026-11-05). Only the topic and the message: who writes is the account
    (ContactController::send). The same invisible ALTCHA and honeypot as the public page
    (public/contact.blade.php, contact-form.js).
--}}
@php
    $altchaVisible = $errors->has('altcha');
    $months = max(1, (int) round(((int) config('redact.retention.contact_messages_days', 365)) / 30.4));
    $securityUrl = trim((string) config('redact.security_url'));
@endphp
<x-layouts.app :title="__('contact.title')">
    <x-ui.page-header :title="__('contact.title')" :subtitle="__('contact.lead')" />

    <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        @if ($sent)
            <section class="a-panel" role="status">
                <div class="a-panel-body a-stack gap-2!">
                    <p class="flex items-center gap-2 font-bold"><x-lucide-circle-check class="size-5 text-brand-blue" aria-hidden="true" />{{ __('contact.sent_title') }}</p>
                    <p class="a-help">{{ __('contact.sent_text') }}</p>
                    <p><a href="{{ route('contact.topic', 'support') }}" class="a-link-btn">{{ __('contact.another') }}</a></p>
                </div>
            </section>
        @else
            <form method="POST" action="{{ route('contact.send') }}" class="a-panel" data-contact-form aria-label="{{ __('contact.title') }}">
                @csrf
                {{-- Honeypot: off-screen, never filled by a person. --}}
                <div class="absolute -left-[10000px] top-0 h-px w-px overflow-hidden" aria-hidden="true">
                    <label for="contact-website">Website</label>
                    <input type="text" id="contact-website" name="website" value="" tabindex="-1" autocomplete="off">
                </div>
                <div class="a-panel-body a-stack gap-4!">
                    <p class="a-help">{{ __('contact.signed_in_as') }} <strong class="text-ink">{{ $name }}</strong> · {{ $email }}@if ($company !== '') · {{ $company }}@endif</p>

                    <div class="a-field max-w-80">
                        <label for="contact-topic" class="a-label">{{ __('contact.fields.topic') }}</label>
                        <select id="contact-topic" name="topic" class="a-select" required @error('topic') aria-invalid="true" aria-describedby="contact-topic-error" @enderror>
                            <option value="" disabled @selected(old('topic', $topic) === '')>{{ __('contact.choose') }}</option>
                            @foreach ($topics as $value)
                                <option value="{{ $value }}" @selected(old('topic', $topic) === $value)>{{ __('contact.topics.'.$value) }}</option>
                            @endforeach
                        </select>
                        @error('topic')<p id="contact-topic-error" class="a-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="a-field">
                        <label for="contact-message" class="a-label">{{ __('contact.fields.message') }}</label>
                        <textarea id="contact-message" name="message" class="a-textarea" rows="8" maxlength="5000" required
                            placeholder="{{ __('contact.placeholders.message') }}" @error('message') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ old('message') }}</textarea>
                        @error('message')<p id="contact-message-error" class="a-error">{{ $message }}</p>@enderror
                    </div>

                    @error('altcha')<p class="a-error" role="alert">{{ $message }}</p>@enderror
                    <altcha-widget challenge="{{ route('contact.altcha') }}" name="altcha" auto="onload" @unless ($altchaVisible) display="invisible" @endunless
                        language="{{ \App\Support\Locales::detail('altcha') }}"></altcha-widget>
                    @error('form')<p class="a-error" role="alert">{{ $message }}</p>@enderror
                    <p class="a-help">{{ __('contact.privacy', ['months' => $months]) }} <a href="{{ route('legal.privacy') }}" class="a-link">{{ __('contact.privacy_link') }}</a></p>
                </div>
                <div class="a-panel-foot">
                    <button type="submit" class="a-btn a-btn-primary" data-contact-submit>
                        <x-lucide-send aria-hidden="true" />
                        <span data-contact-label data-busy="{{ __('contact.sending') }}">{{ __('contact.submit') }}</span>
                    </button>
                </div>
            </form>
        @endif

        <aside class="a-panel" aria-labelledby="contact-next-heading">
            <div class="a-panel-head"><h2 id="contact-next-heading" class="a-h3">{{ __('contact.next_title') }}</h2></div>
            <div class="a-panel-body a-stack gap-3!">
                <p class="a-help">{{ __('contact.next') }}</p>
                {{-- A security issue: the security page (how to report, the PGP key), in a new tab. --}}
                @if ($securityUrl !== '')
                    <p class="a-help">{{ __('contact.security') }}
                        <a class="a-link" href="{{ $securityUrl }}" target="_blank" rel="noopener">{{ preg_replace('#^https?://(www\.)?#', '', rtrim($securityUrl, '/')) }}<x-lucide-external-link class="ml-1 inline size-3.5 align-[-2px]" aria-hidden="true" /><span class="a-sr-only"> {{ __('contact.new_tab') }}</span></a></p>
                @endif
            </div>
        </aside>
    </div>
</x-layouts.app>
