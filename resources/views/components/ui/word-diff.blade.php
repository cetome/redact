{{--
    A word diff (App\Support\WordDiff::segments): removed text struck through on red, added text
    underlined on green, "…" for unchanged text left out. Screen readers hear "Removed" / "Added".
    <x-ui.word-diff :segments="$segments" />
--}}
@props(['segments' => []])
@php
    $removed = e(__('history.removed'));
    $added = e(__('history.added'));
    $html = collect($segments)->map(fn ($s) => match ($s['type']) {
        'del' => '<del class="a-diff-del"><span class="a-sr-only">['.$removed.'] </span>'.e($s['text']).'</del>',
        'ins' => '<ins class="a-diff-ins"><span class="a-sr-only">['.$added.'] </span>'.e($s['text']).'</ins>',
        'gap' => '<span class="a-diff-gap" aria-hidden="true">…</span>',
        default => e($s['text']),
    })->implode('');
@endphp
<p {{ $attributes->class(['a-diff']) }}>{!! $html !!}</p>
