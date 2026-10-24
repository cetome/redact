{{--
    Alert (.a-alert): the icon in a round badge, optional bold title, the text, an optional action on the right
    (under the text on phones) and an optional dismiss (x, Alpine "dismissible").
    <x-ui.alert tone="warning" :title="…" :action="['href' => …, 'label' => …]" dismissible flash>Text</x-ui.alert>
    post: a second action that is a POST (['action' => path, 'label' => …, 'fields' => [name => value]]), next to it.
    tone: info (default) | success | warning | danger | neutral. icon: a lucide name (default per tone).
    flash: slides in once (x-flash). role: alert for danger, status otherwise (override with role="…").
--}}
@props(['tone' => 'info', 'icon' => null, 'title' => null, 'action' => null, 'post' => null, 'dismissible' => false, 'flash' => false])
@php
    $icon ??= match ($tone) { 'success' => 'circle-check', 'warning' => 'triangle-alert', 'danger' => 'circle-x', default => 'info' };
    $action = is_array($action) && isset($action['href'], $action['label']) ? $action : null;
    $post = is_array($post) && isset($post['action'], $post['label']) ? $post : null;
@endphp
<div {{ $attributes->class(['a-alert', 'a-alert-'.$tone => $tone !== 'info', 'is-flash' => $flash])->merge(['role' => $tone === 'danger' ? 'alert' : 'status']) }} @if ($dismissible) x-data="dismissible" x-show="open" @endif>
    <x-dynamic-component :component="'lucide-'.$icon" aria-hidden="true" />
    <div class="a-alert-body">
        @if ($title)
            <p class="a-alert-title">{{ $title }}</p>
        @endif
        {{ $slot }}
    </div>
    @if ($action || $post)
        <div class="a-alert-actions">
            @if ($action)<a class="a-alert-action" href="{{ $action['href'] }}">{{ $action['label'] }}<x-lucide-arrow-right aria-hidden="true" /></a>@endif
            @if ($post)
                <form method="POST" action="{{ $post['action'] }}">
                    @csrf
                    @foreach ((array) ($post['fields'] ?? []) as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                    <button type="submit" class="a-alert-action">{{ $post['label'] }}<x-lucide-arrow-right aria-hidden="true" /></button>
                </form>
            @endif
        </div>
    @endif
    @if ($dismissible)
        <button type="button" class="a-close a-alert-close" @click="close" aria-label="{{ __('common.close') }}"><x-lucide-x aria-hidden="true" /></button>
    @endif
</div>
