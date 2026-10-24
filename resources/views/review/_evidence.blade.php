{{--
    The read-only evidence of one evaluation (Review step, #evidence panel): the path through the
    decision tree (each question and the answer given), the verdict, the justification (E.Just), the
    E.Info of that requirement for that entry, the documents attached to its assessment units, the
    VEX statements of a known vulnerability, then the reviews. No input, no link to an editor.
    $evaluation, $evidence (ReviewController::evidence), $row (my review, stale reviews), $changes, $date, $isStale, $counts.
--}}
@php
    $check = $evidence['check'];
    $status = $evidence['status'];
@endphp
<div class="a-evidence">
    <p class="a-evidence-verdict">
        <x-verdict :verdict="$evaluation->verdict" />
        @if (in_array($status, ['unjustified', 'inconclusive', 'incomplete', 'invalid'], true))
            <x-eval-status :status="$status" />
        @endif
        @if ($check->toCheck() > 0)
            <span class="a-badge a-badge-warning" title="{{ __('assessment.to_check_help') }}"><x-lucide-eye aria-hidden="true" />{{ __('assessment.to_check') }}</span>
        @endif
        <span class="a-case-by">
            @if ($evaluation->updater)
                {{ __('review.last_changed_by', ['name' => $evaluation->updater->name, 'date' => $date($evaluation->updated_at)]) }}
            @else
                {{ __('review.last_changed', ['date' => $date($evaluation->updated_at)]) }}
            @endif
        </span>
    </p>

    <section class="a-evidence-section" aria-labelledby="evidence-path">
        <h3 class="a-micro" id="evidence-path">{{ __('review.evidence.path') }}</h3>
        @if ($evidence['steps'] !== [])
            <ol class="a-evidence-path">
                @foreach ($evidence['steps'] as $step)
                    <li>
                        <span class="a-mono a-evidence-ref">{{ $step['ref'] }}</span>
                        <span class="a-evidence-question">{{ $step['question'] }}</span>
                        <span class="a-chip a-chip-sm a-evidence-answer">{{ $step['answer'] }}</span>
                    </li>
                @endforeach
            </ol>
        @elseif ($evaluation->decision_node)
            {{-- Expert entry: the last decision node and the verdict, without the answers. --}}
            <p class="a-help">{{ __('review.evidence.expert', ['node' => $evaluation->nodeReference()]) }}</p>
        @else
            <p class="a-help">{{ __('review.no_path') }}</p>
        @endif
    </section>

    <section class="a-evidence-section" aria-labelledby="evidence-just">
        <h3 class="a-micro" id="evidence-just">{{ __('evaluations.justification') }}</h3>
        @if (filled($evaluation->justification))
            <p class="whitespace-pre-line">{{ $evaluation->justification }}</p>
        @else
            <span class="a-badge a-badge-warning">{{ __('review.no_justification') }}</span>
        @endif
        @if (! $check->justified && $check->reasons !== [])
            <ul class="a-evidence-reasons">
                @foreach ($check->reasons as $reason)
                    <li><x-lucide-scale aria-hidden="true" />{{ $reason }}</li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($evidence['einfo'] !== [])
        <section class="a-evidence-section" aria-labelledby="evidence-einfo">
            <h3 class="a-micro" id="evidence-einfo">{{ __('workflow.steps.einfo') }}</h3>
            <dl class="a-evidence-einfo">
                @foreach ($evidence['einfo'] as $field)
                    <div>
                        <dt><span class="a-mono">{{ $field['code'] }}</span> <span class="a-muted">{{ $field['label'] }}</span></dt>
                        <dd>
                            @if ($field['text'] !== '')
                                <span class="whitespace-pre-line">{{ $field['text'] }}</span>
                            @else
                                <span class="a-badge a-badge-warning">{{ __('review.evidence.einfo_missing') }}</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($evidence['documents']->isNotEmpty() && Route::has('versions.documents.download'))
        <section class="a-evidence-section" aria-labelledby="evidence-docs">
            <h3 class="a-micro" id="evidence-docs">{{ __('review.evidence.documents') }}</h3>
            <ul class="a-evidence-docs">
                @foreach ($evidence['documents'] as $document)
                    <li><a class="a-link-btn" href="{{ route('versions.documents.download', [$version, $document]) }}"><x-lucide-paperclip aria-hidden="true" />{{ $document->original_name }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($evidence['vex']->isNotEmpty())
        <section class="a-evidence-section" aria-labelledby="evidence-vex">
            <h3 class="a-micro" id="evidence-vex">VEX</h3>
            <ul class="a-evidence-docs">
                @foreach ($evidence['vex'] as $statement)
                    <li>
                        <span class="a-badge {{ \App\Sbom\Vex\VexStatus::TONES[$statement->status] ?? 'a-badge-neutral' }}"><x-lucide-shield-check aria-hidden="true" />{{ __('sbom.vex.status.'.$statement->status) }}</span>
                        <span>{{ $statement->component ? $statement->component->code.' '.$statement->component->name : __('sbom.vex.product') }}@if ($statement->explanation()) · {{ $statement->explanation() }}@endif</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($evaluation->reviews->isNotEmpty())
        <section class="a-evidence-section" aria-labelledby="evidence-reviews">
            <h3 class="a-micro" id="evidence-reviews">{{ __('review.reviews') }}</h3>
            @foreach ($row['stale']->groupBy('fingerprint') as $group)
                @include('review._changes', ['changes' => $changes[$group->first()->id], 'group' => $group, 'evaluation' => $evaluation, 'open' => $group->contains('reviewer_id', $me->id), 'date' => $date])
            @endforeach
            @include('review._opinions', ['reviews' => $evaluation->reviews, 'stale' => $isStale, 'counts' => $counts])
        </section>
    @endif
</div>
