{{--
    One requirement of the item shown on the E.Info page: its code and title, where its decision tree
    stands for this item (node reached, verdict or state) and, for an editor, "Edit the decision tree"
    (the modal tree-{code}; without script, the evaluation page of that item).
    @include('einfo._tree-head', ['code' => 'ACM-1', 'title' => '…', 'tree' => $trees['ACM-1'] ?? null, 'itemKey' => …])
--}}
<div class="a-einfo-req-head">
    <p class="a-einfo-req-name" title="{{ $code }} · {{ $title }}"><span class="a-code">{{ $code }}</span> <span class="a-einfo-req-title">{{ $title }}</span></p>
    @if ($tree)
        <span class="a-einfo-req-state">
            @if ($tree['evaluation']?->decision_node)<span class="a-chip a-chip-sm a-chip-code" title="{{ $tree['evaluation']->nodeReference() }}">{{ $tree['evaluation']->decision_node }}</span>@endif
            @if ($tree['status'] === 'done')
                <x-verdict :verdict="$tree['evaluation']?->verdict" :icon="false" />
            @else
                <x-eval-status :status="$tree['status']" />
            @endif
        </span>
        @if ($editable)
            <a href="{{ \App\Support\Links::walk($version, $standard, $code, $itemKey) }}" class="a-btn a-btn-ghost a-btn-sm a-einfo-req-edit"
               data-dialog-open="tree-{{ $code }}" aria-label="{{ __('einfo.trees.edit_label', ['code' => $code]) }}" title="{{ __('einfo.trees.edit_label', ['code' => $code]) }}">
                <x-lucide-git-fork aria-hidden="true" /><span class="a-einfo-req-edit-label">{{ __('einfo.trees.edit') }}</span>
            </a>
        @endif
    @endif
</div>
