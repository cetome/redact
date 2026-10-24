{{--
    What changed in an assessment unit result since one or more reviews of the same content (their
    snapshot): verdict, evidence (word diff), document. Same look as review/_changes.
    $group the reviews, $result, $open, $date (formatter).
--}}
@php
    $first = $group->first();
    $then = (array) ($first->snapshot ?? []);
    $now = $result->reviewSnapshot();
    $title = $group->count() === 1
        ? __('review.changes_since', ['name' => $first->reviewer->name, 'date' => $date($first->updated_at)])
        : __('review.changes_since_many', ['names' => $group->map(fn ($r) => $r->reviewer->name)->join(', ', ' '.__('review.and').' ')]);
    $verdictChanged = ($then['verdict'] ?? null) !== $now['verdict'];
    $evidenceChanged = trim((string) ($then['evidence'] ?? '')) !== trim($now['evidence']);
    $documentChanged = ($then['document_id'] ?? null) !== $now['document_id'];
    $removed = e(__('review.removed'));
    $added = e(__('review.added'));
    $diffHtml = $evidenceChanged ? collect(\App\Support\WordDiff::segments((string) ($then['evidence'] ?? ''), $now['evidence']))->map(fn ($s) => match ($s['type']) {
        'del' => '<del class="a-diff-del"><span class="a-sr-only">['.$removed.'] </span>'.e($s['text']).'</del>',
        'ins' => '<ins class="a-diff-ins"><span class="a-sr-only">['.$added.'] </span>'.e($s['text']).'</ins>',
        'gap' => '<span class="a-diff-gap" aria-hidden="true">…</span>',
        default => e($s['text']),
    })->implode('') : '';
@endphp
<details class="a-changes" @if ($open) open @endif>
    <summary><x-lucide-chevron-right class="a-changes-chevron" aria-hidden="true" /><span>{{ $title }}</span></summary>
    <div class="a-changes-body">
        @if ($then === [] || (! $verdictChanged && ! $evidenceChanged && ! $documentChanged))
            <p class="a-changes-note">{{ __('review.changes_not_recorded') }}</p>
        @else
            <dl class="a-changes-list">
                @if ($verdictChanged)
                    <div>
                        <dt>{{ __('evaluations.verdict') }}</dt>
                        <dd class="a-changes-move">
                            <span class="a-sr-only">{{ __('review.before') }}</span><x-verdict :verdict="$then['verdict'] ?? null" class="is-old" />
                            <x-lucide-arrow-right class="a-changes-arrow" aria-hidden="true" />
                            <span class="a-sr-only">{{ __('review.now') }}</span><x-verdict :verdict="$now['verdict']" />
                        </dd>
                    </div>
                @endif
                @if ($evidenceChanged)
                    <div>
                        <dt>{{ __('assessment.evidence') }}</dt>
                        <dd><p class="a-diff">{!! $diffHtml !!}</p></dd>
                    </div>
                @endif
                @if ($documentChanged)
                    <div>
                        <dt>{{ __('assessment.document') }}</dt>
                        <dd>{{ $result->document?->original_name ?? __('assessment.document_none') }}</dd>
                    </div>
                @endif
            </dl>
        @endif
        <p class="a-changes-foot">
            {{ __('review.last_changed_by', ['name' => $result->recorder?->name ?? '—', 'date' => $date($result->updated_at)]) }}
            @if ($evidenceChanged)
                <span class="a-diff-legend" aria-hidden="true"><del class="a-diff-del">{{ __('review.removed') }}</del> <ins class="a-diff-ins">{{ __('review.added') }}</ins></span>
            @endif
        </p>
    </div>
</details>
