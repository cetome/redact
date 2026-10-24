@props(['status'])
@php
    $class = match ($status->value) {
        'draft' => 'a-badge-neutral',
        'in_review' => 'a-badge-warning',
        'approved' => 'a-badge-success',
        default => 'a-badge-neutral',
    };
@endphp
<span {{ $attributes->class(['a-badge', $class]) }}>{{ $status->label() }}</span>
