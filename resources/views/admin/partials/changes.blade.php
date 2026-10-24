{{--
    Readable change set of one activity entry: field, old → new. Expects $changes (ActivityExplorer::changes).
    The first three fields are shown; the rest fold behind "+N more fields" so long records stay one row.
--}}
@if ($changes !== [])
    @foreach (array_chunk($changes, 3) as $i => $chunk)
        @if ($i === 1)
            <details class="a-more mt-1">
                <summary class="a-link-btn text-label">{{ trans_choice('admin.activity.more_fields', count($changes) - 3, ['count' => count($changes) - 3]) }}</summary>
        @endif
        <dl class="mt-1 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 text-label">
            @foreach ($chunk as $change)
                <dt class="font-semibold text-ink-muted">{{ $change['field'] }}</dt>
                <dd class="line-clamp-3 break-all" title="{{ \Illuminate\Support\Str::limit($change['new'], 400) }}">
                    @if ($change['old'] !== '')
                        <del class="text-ink-subtle">{{ $change['old'] }}</del>
                        <span aria-hidden="true">→</span>
                        <span class="sr-only">{{ __('admin.activity.changed_to') }}</span>
                    @endif
                    <ins class="no-underline font-medium">{{ $change['new'] !== '' ? $change['new'] : '∅' }}</ins>
                </dd>
            @endforeach
        </dl>
    @endforeach
    @if (count($changes) > 3)
        </details>
    @endif
@endif
