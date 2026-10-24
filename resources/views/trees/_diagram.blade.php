{{--
    A decision tree as a readable vertical flow: one card per decision node in reading order, each
    branch leading to an outcome (verdict chip) or to another node.

    @include('trees._diagram', [
        'tree' => $tree,                 required
        'order' => [...],                decision node ids to show, in order (required)
        'idPrefix' => 'x',               prefix of the card ids, so two diagrams can share a page
        'current' => ['DN-1', 'DN-3'],   nodes on the walked path: card marked .is-on-path
        'answers' => ['DN-1' => 'No'],   the answer given at a node: that branch is marked .is-taken
        'focus' => 'DN-2',               the question asked now: card marked .is-current
        'outcome' => 'DN-3',             last node of a finished walk: its taken branch is .is-reached
        'interactive' => true,           questions and answers are buttons (walk page, when editing):
                                         tree-walk.js jumps there and repaints the classes live
        'compact' => true,               denser cards for a side column
    ])
    'highlight' is the older name of 'current' and still works.
    Classes: .a-tree, .a-tree-node (.is-on-path, .is-current, .is-start), .a-tree-head, .a-tree-id,
    .a-tree-flag, .a-tree-text, .a-tree-branch (.is-taken, .is-reached), .a-tree-pick (buttons).
--}}
@php
    $idPrefix ??= 'tree';
    $compact ??= false;
    $interactive ??= false;
    $focus ??= null;
    $outcome ??= null;
    $current = array_values(array_unique(array_merge($current ?? [], $highlight ?? [])));
    $answers ??= [];
    $nodes = $tree->nodes();
    $reference = 'DT.'.$tree->requirement().'.';
@endphp
<ol @class(['a-tree', 'is-compact' => $compact, 'is-interactive' => $interactive]) data-tree-diagram>
    @foreach ($order as $id)
        @php
            $node = $nodes[$id];
            $isCurrent = $focus === $id;
            $onPath = ! $isCurrent && in_array($id, $current, true);
        @endphp
        <li id="{{ $idPrefix }}-{{ $id }}" data-tree-node="{{ $id }}" @class(['a-tree-node', 'is-on-path' => $onPath, 'is-current' => $isCurrent, 'is-start' => $loop->first])>
            @if ($interactive)
                <button type="button" class="a-tree-head a-tree-pick" data-node="{{ $id }}" @click="pickNode"
                        title="{{ __('trees.pick_node') }}" @if ($isCurrent) aria-current="step" @endif>
            @else
                <div class="a-tree-head">
            @endif
                <span class="a-tree-meta">
                    <span class="a-tree-id">{{ $reference }}{{ $id }}</span>
                    @if ($loop->first)<span class="a-micro">{{ __('trees.start') }}</span>@endif
                    <span class="a-tree-flag a-tree-flag-path">{{ __('trees.on_path') }}</span>
                    <span class="a-tree-flag a-tree-flag-current">{{ __('trees.current') }}</span>
                </span>
                <span class="a-tree-text">{{ $node['text'] }}</span>
            @if ($interactive)
                </button>
            @else
                </div>
            @endif
            <ul class="a-tree-branches">
                @foreach ($node['branches'] as $branch)
                    @php
                        $target = $nodes[$branch['to']] ?? null;
                        $taken = isset($answers[$id]) && mb_strtolower($answers[$id]) === mb_strtolower($branch['label']);
                        $reached = $taken && $outcome === $id && ($target['kind'] ?? null) === 'result';
                        $label = ucfirst(mb_strtolower($branch['label']));
                    @endphp
                    <li>
                        @if ($interactive)
                            <button type="button" @class(['a-tree-branch a-tree-pick', 'is-taken' => $taken, 'is-reached' => $reached])
                                    data-tree-branch data-node="{{ $id }}" data-label="{{ $branch['label'] }}" @click="pickBranch"
                                    aria-pressed="{{ $taken ? 'true' : 'false' }}" title="{{ __('trees.pick_branch', ['answer' => $label]) }}">
                        @else
                            <span @class(['a-tree-branch', 'is-taken' => $taken, 'is-reached' => $reached]) data-tree-branch data-node="{{ $id }}" data-label="{{ $branch['label'] }}">
                        @endif
                            <span class="a-tree-answer">{{ $label }}</span>
                            <x-lucide-arrow-right class="a-tree-arrow" aria-hidden="true" />
                            @if ($target && $target['kind'] === 'result')
                                <x-verdict :verdict="\App\Enums\Verdict::fromTreeResult($target['result'])" />
                                <span class="a-tree-flag a-tree-flag-reached">{{ __('trees.reached') }}</span>
                            @elseif ($target && $interactive)
                                <span class="a-tree-goto">{{ __('trees.go_to', ['node' => $branch['to']]) }}</span>
                            @elseif ($target)
                                <a href="#{{ $idPrefix }}-{{ $branch['to'] }}" class="a-link-btn a-tree-goto">{{ __('trees.go_to', ['node' => $branch['to']]) }}</a>
                            @endif
                            @unless ($interactive)<span class="a-sr-only a-tree-taken">{{ __('trees.taken') }}</span>@endunless
                        @if ($interactive)
                            </button>
                        @else
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </li>
    @endforeach
</ol>
