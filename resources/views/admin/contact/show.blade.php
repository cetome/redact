{{-- One contact form message, read-only. "Reply" opens the staff member's own mail program. --}}
<x-layouts.app :title="$message->name" :wide="true">
    @include('admin.partials.nav')

    <x-ui.page-header :title="$message->name" :subtitle="$message->topicLabel()">
        <x-slot:eyebrow><a href="{{ route('admin.contact.index') }}">← {{ __('contact.admin.back') }}</a></x-slot:eyebrow>
        <x-slot:actions>
            <a href="mailto:{{ $message->email }}" class="a-btn a-btn-primary"><x-lucide-reply aria-hidden="true" />{{ __('contact.admin.reply') }}</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid items-start gap-4 xl:grid-cols-3">
        <section class="a-panel xl:col-span-2" aria-labelledby="message-heading">
            <div class="a-panel-head"><h2 id="message-heading" class="a-h3">{{ __('contact.fields.message') }}</h2></div>
            <div class="a-panel-body">
                <p class="whitespace-pre-wrap break-words">{{ $message->message }}</p>
            </div>
        </section>

        <section class="a-panel" aria-labelledby="details-heading">
            <div class="a-panel-head"><h2 id="details-heading" class="a-h3">{{ __('contact.admin.details') }}</h2></div>
            <div class="a-panel-body">
                <dl class="a-dl">
                    <dt>{{ __('contact.fields.email') }}</dt>
                    <dd class="break-all"><a href="mailto:{{ $message->email }}" class="a-link-btn">{{ $message->email }}</a></dd>
                    <dt>{{ __('contact.fields.company') }}</dt>
                    <dd>{{ $message->company ?? '—' }}</dd>
                    <dt>{{ __('contact.admin.account') }}</dt>
                    <dd>
                        @if ($message->user)
                            <a href="{{ route('admin.users.show', $message->user) }}" class="a-link-btn">#{{ $message->user->id }} · {{ $message->user->email }}</a>
                        @else
                            —
                        @endif
                    </dd>
                    <dt>{{ __('contact.mail.language') }}</dt>
                    <dd>{{ strtoupper($message->locale) }}</dd>
                    <dt>{{ __('contact.admin.received') }}</dt>
                    <dd>{{ $message->created_at->isoFormat('D MMMM YYYY, HH:mm:ss') }}</dd>
                    <dt>{{ __('contact.admin.status') }}</dt>
                    <dd>
                        @include('admin.contact._status', ['item' => $message])
                        @if ($message->failure)
                            <span class="a-row-sub break-all">{{ $message->failure }}</span>
                        @endif
                    </dd>
                    <dt>{{ __('contact.admin.browser') }}</dt>
                    <dd class="break-all font-normal!">{{ $message->user_agent ?? '—' }}</dd>
                </dl>
            </div>
        </section>
    </div>
</x-layouts.app>
