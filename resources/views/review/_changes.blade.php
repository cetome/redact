{{--
    What changed in an evaluation since one or more reviews of the same content.
    $changes App\Assessment\ReviewChanges, $group the reviews, $evaluation, $open, $date (formatter).
--}}
@php
    $first = $group->first();
    $title = $group->count() === 1
        ? __('review.changes_since', ['name' => $first->reviewer->name, 'date' => $date($first->updated_at)])
        : __('review.changes_since_many', ['names' => $group->map(fn ($r) => $r->reviewer->name)->join(', ', ' '.__('review.and').' ')]);

    // Removed text struck through, added text underlined; screen readers hear "Removed" / "Added".
    $removed = e(__('review.removed'));
    $added = e(__('review.added'));
    $diffHtml = collect($changes->justification ?? [])->map(fn ($s) => match ($s['type']) {
        'del' => '<del class="a-diff-del"><span class="a-sr-only">['.$removed.'] </span>'.e($s['text']).'</del>',
        'ins' => '<ins class="a-diff-ins"><span class="a-sr-only">['.$added.'] </span>'.e($s['text']).'</ins>',
        'gap' => '<span class="a-diff-gap" aria-hidden="true">…</span>',
        default => e($s['text']),
    })->implode('');

    // Answers step by step: a step differs when its node or answer differs at the same place.
    $steps = function (array $path, array $other, string $tag) {
        return collect($path)->map(function ($step, $i) use ($other, $tag) {
            $same = isset($other[$i]) && $other[$i] == $step;
            $text = '<span class="a-mono">'.e($step['node'] ?? '').'</span> '.e($step['answer'] ?? '');

            return $same ? '<span class="a-changes-step">'.$text.'</span>' : '<'.$tag.' class="a-changes-step a-diff-'.$tag.'">'.$text.'</'.$tag.'>';
        })->implode('<span class="a-changes-sep" aria-hidden="true">›</span>');
    };
@endphp
<details class="a-changes" @if ($open) open @endif>
    <summary><x-lucide-chevron-right class="a-changes-chevron" aria-hidden="true" /><span>{{ $title }}</span></summary>
    <div class="a-changes-body">
        @if (! $changes->recorded)
            <p class="a-changes-note">{{ __('review.changes_not_recorded') }}</p>
        @else
            <dl class="a-changes-list">
                @if ($changes->verdict)
                    <div>
                        <dt>{{ __('evaluations.verdict') }}</dt>
                        <dd class="a-changes-move">
                            <span class="a-sr-only">{{ __('review.before') }}</span><x-verdict :verdict="$changes->verdict['old']" class="is-old" />
                            <x-lucide-arrow-right class="a-changes-arrow" aria-hidden="true" />
                            <span class="a-sr-only">{{ __('review.now') }}</span><x-verdict :verdict="$changes->verdict['new']" />
                        </dd>
                    </div>
                @endif
                @if ($changes->node)
                    <div>
                        <dt>{{ __('evaluations.decision_node') }}</dt>
                        <dd class="a-changes-move">
                            <span class="a-sr-only">{{ __('review.before') }}</span><code class="a-changes-node is-old">{{ $changes->node['old'] ?? __('review.no_node') }}</code>
                            <x-lucide-arrow-right class="a-changes-arrow" aria-hidden="true" />
                            <span class="a-sr-only">{{ __('review.now') }}</span><code class="a-changes-node">{{ $changes->node['new'] ?? __('review.no_node') }}</code>
                        </dd>
                    </div>
                @endif
                @if ($changes->path)
                    <div>
                        <dt>{{ __('review.field_path') }}</dt>
                        <dd class="a-changes-paths">
                            <span class="a-micro">{{ __('review.before') }}</span>
                            <span>@if ($changes->path['old']){!! $steps($changes->path['old'], $changes->path['new'], 'del') !!}@else<span class="a-muted">{{ __('review.no_path') }}</span>@endif</span>
                            <span class="a-micro">{{ __('review.now') }}</span>
                            <span>@if ($changes->path['new']){!! $steps($changes->path['new'], $changes->path['old'], 'ins') !!}@else<span class="a-muted">{{ __('review.no_path') }}</span>@endif</span>
                        </dd>
                    </div>
                @endif
                @if ($changes->justification !== null)
                    <div>
                        <dt>{{ __('evaluations.justification') }}</dt>
                        <dd><p class="a-diff">{!! $diffHtml !!}</p></dd>
                    </div>
                @endif
            </dl>
        @endif
        <p class="a-changes-foot">
            @if ($evaluation->updater)
                {{ __('review.last_changed_by', ['name' => $evaluation->updater->name, 'date' => $date($evaluation->updated_at)]) }}
            @else
                {{ __('review.last_changed', ['date' => $date($evaluation->updated_at)]) }}
            @endif
            @if ($changes->source === 'activity')
                · {{ __('review.changes_rebuilt') }}
            @endif
            @if ($changes->justification !== null)
                <span class="a-diff-legend" aria-hidden="true"><del class="a-diff-del">{{ __('review.removed') }}</del> <ins class="a-diff-ins">{{ __('review.added') }}</ins></span>
            @endif
        </p>
    </div>
</details>
