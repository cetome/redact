<?php

namespace App\Http\Controllers;

use App\Assessment\Assessment;
use App\Assessment\Justification;
use App\Assessment\SharedWalks;
use App\Assessment\TargetNames;
use App\Content\Text;
use App\Content\Tree;
use App\Enums\Verdict;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\ProductVersion;
use App\Support\Links;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * E.DT / E.Just: walking each EN 18031 decision tree for each object it
 * applies to, recording the last decision node, the verdict and the
 * justification. The tree decides which verdicts are possible: an
 * impossible node/verdict pair is refused (the Excel "grey box").
 */
class EvaluationController extends Controller
{
    public function index(ProductVersion $version, string $standard): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);
        $assessment = Assessment::for($version);
        abort_unless(isset($assessment->pack->standards()[$standard]), 404);

        $rows = [];
        $names = new TargetNames($assessment->pack);
        $shared = SharedWalks::for($assessment);
        $working = $assessment->workingStandards();
        foreach ($assessment->pack->requirementsFor($standard) as $code => $requirement) {
            $tree = $assessment->pack->tree($standard, $code);
            $targets = $assessment->targets($standard, $code);
            $counts = ['total' => count($targets), 'done' => 0, 'invalid' => 0, 'unjustified' => 0, 'inconclusive' => 0, 'recheck' => 0, 'fail' => 0, 'pass' => 0, 'na' => 0];
            foreach ($targets as $item) {
                $evaluation = $assessment->evaluation($standard, $code, $item);
                $status = $assessment->status($evaluation, $tree);
                $counts['done'] += $status === 'done' ? 1 : 0;
                $counts['invalid'] += $status === 'invalid' ? 1 : 0;
                $counts['unjustified'] += $status === 'unjustified' ? 1 : 0;
                $counts['inconclusive'] += $status === 'inconclusive' ? 1 : 0;
                $counts['recheck'] += $status === 'recheck' ? 1 : 0;
                $counts['fail'] += $evaluation?->verdict === Verdict::Fail ? 1 : 0;
                $counts['pass'] += $evaluation?->verdict === Verdict::Pass ? 1 : 0;
                $counts['na'] += $evaluation?->verdict === Verdict::NotApplicable ? 1 : 0;
            }
            // Walks settled by another walk (PackRules::followsFrom): FAIL with it, not walked, or
            // still to link in the inventory. Counted apart, never as walks of their own.
            $follows = $assessment->followsFrom($standard, (string) $code);
            $counts['follows'] = count(array_filter($follows, fn (array $f) => $f['state'] === 'fail'));
            $counts['unlinked'] = count($follows) - $counts['follows'];
            $rows[$requirement['family']][] = [
                'code' => $code,
                'title' => $tree?->title() ?: Text::of($requirement['title'] ?? ''),
                'list' => $requirement['list'] ?? null,
                'targets' => $names->count($targets, $requirement),
                'shared' => array_values(array_intersect($shared->equivalent($standard, $code), $working)),
                'counts' => $counts,
                'sources' => array_values(array_unique(array_column($follows, 'requirement'))),
            ];
        }

        return view('evaluations.index', [
            'version' => $version,
            'access' => $access,
            'standard' => $standard,
            'inScope' => in_array($standard, $assessment->workingStandards(), true),
            'standards' => $assessment->workingStandards(),
            'pack' => $assessment->pack,
            'families' => $rows,
            'progress' => $assessment->evaluationProgress($standard),
        ]);
    }

    public function show(ProductVersion $version, string $standard, string $requirement, ?string $target = null): View
    {
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);
        $assessment = Assessment::for($version);
        $definition = $assessment->pack->requirement($requirement);
        abort_unless($definition && in_array($standard, $definition['standards'], true), 404);

        $tree = $assessment->pack->tree($standard, $requirement);
        $targets = [];
        foreach ($assessment->targets($standard, $requirement) as $item) {
            $evaluation = $assessment->evaluation($standard, $requirement, $item);
            $targets[] = [
                'item' => $item,
                'key' => $item->id ?? 'product',
                'evaluation' => $evaluation,
                'status' => $assessment->status($evaluation, $tree),
            ];
        }

        // The item shown (/{target}), else the first one still to do, else the first.
        $selected = collect($targets)->firstWhere('key', (string) $target)
            ?? collect($targets)->first(fn (array $t) => $t['status'] !== 'done')
            ?? ($targets[0] ?? null);

        // Walks settled by another walk: shown under the walks, never walked here.
        $follows = array_map(fn (array $f) => $f + [
            'href' => $f['state'] === 'fail' || $f['item'] === null
                ? Links::walk($version, $standard, $f['requirement'], $f['item'])
                : Links::item($version, $f['item']),
        ], $assessment->followsFrom($standard, $requirement));

        // The previous and next requirements of the standard that have something to evaluate.
        $codes = $this->navigable($assessment, $standard);
        $at = array_search($requirement, $codes, true);

        // Is the saved walk of the object shown justified the way the standard asks?
        $selectedEvaluation = $selected['evaluation'] ?? null;
        $names = new TargetNames($assessment->pack);
        $items = array_column($targets, 'item');

        return view('evaluations.show', [
            'check' => $selectedEvaluation ? $assessment->justification()->check($selectedEvaluation) : null,
            'criteria' => $assessment->pack->assessment($standard, $requirement),
            'version' => $version,
            'access' => $access,
            'standard' => $standard,
            'requirement' => $definition,
            'tree' => $tree,
            'targets' => $targets,
            'selected' => $selected,
            'previous' => $at !== false && $at > 0 ? $codes[$at - 1] : null,
            'next' => $at !== false && $at < count($codes) - 1 ? $codes[$at + 1] : null,
            'pack' => $assessment->pack,
            'shared' => $selected ? SharedWalks::for($assessment)->state($standard, $requirement, $selected['item'], $selectedEvaluation) : null,
            'heading' => $names->heading($definition, $items),
            'listName' => $names->heading($definition, []),
            'follows' => $follows,
            'inner' => $selected ? $assessment->innerTargets($standard, $requirement, $selected['item']) : [],
            'canEdit' => $access->canEdit($version),
        ]);
    }

    public function update(Request $request, ProductVersion $version, string $standard, string $requirement): RedirectResponse
    {
        $this->allow('edit', $version);
        $assessment = Assessment::for($version);
        $definition = $assessment->pack->requirement($requirement);
        abort_unless($definition && in_array($standard, $definition['standards'], true), 404);
        $tree = $assessment->pack->tree($standard, $requirement);

        $data = $request->validate([
            'target' => ['required', 'string', 'max:26'],
            'answers' => ['nullable', 'array', 'max:30'],
            'answers.*' => ['string', 'max:40'],
            'decision_node' => ['nullable', 'string', 'max:16'],
            'verdict' => ['nullable', Rule::enum(Verdict::class)],
            'justification' => ['nullable', 'string', 'max:20000'],
            'inconclusive' => ['nullable', 'boolean'],
            'partial' => ['nullable', 'array', 'max:30'],
            'partial.*' => ['string', 'max:40'],
            // Saved from the E.Info page's modal: back there (einfoReturn() rebuilds the address).
            'return' => ['nullable', 'string', 'max:1000'],
        ]);

        $item = $this->target($assessment, $standard, $requirement, $data['target']);

        // INCONCLUSIVE (not a verdict of EN 18031): the path so far and the last node reached are
        // kept; never without a justification saying what is missing.
        $inconclusive = $request->boolean('inconclusive');
        [$node, $verdict, $path] = $inconclusive ? $this->partialWalk($tree, $data) : $this->outcome($tree, $data);
        if ($verdict === Verdict::Inconclusive && mb_strlen(trim((string) ($data['justification'] ?? ''))) < Justification::MIN_LENGTH) {
            throw ValidationException::withMessages(['justification' => __('assessment.inconclusive_needs_reason', ['min' => Justification::MIN_LENGTH])]);
        }

        $parts = DB::transaction(function () use ($assessment, $version, $standard, $requirement, $item, $node, $verdict, $path, $data, $request) {
            $evaluation = Evaluation::query()
                ->where('product_version_id', $version->id)
                ->where('standard', $standard)
                ->where('requirement', $requirement)
                ->where('inventory_item_id', $item?->id)
                ->lockForUpdate()
                ->first() ?? new Evaluation([
                    'product_version_id' => $version->id,
                    'standard' => $standard,
                    'requirement' => $requirement,
                    'inventory_item_id' => $item?->id,
                ]);

            $evaluation->fill([
                'decision_node' => $node,
                'verdict' => $verdict,
                'path' => $path,
                'justification' => $data['justification'] ?? null,
                'updated_by' => $request->user()->id,
            ]);

            // The same answer in the other parts where the tree is equivalent (SharedWalks).
            $parts = SharedWalks::for($assessment)->apply($evaluation, $request->user()->id);
            $evaluation->sharedWith = $this->names($assessment, $parts);
            $evaluation->save();
            if ($parts !== [] && ! $evaluation->wasRecentlyCreated && ! $evaluation->wasChanged(['decision_node', 'verdict', 'justification'])) {
                // Nothing changed here but in the other parts: one entry still tells it.
                activity('evaluation')->performedOn($evaluation)->causedBy($request->user())->event('shared')
                    ->withProperties(['shared_with' => implode(', ', $evaluation->sharedWith)])->log('shared');
            }

            return $parts;
        });
        $also = $parts === [] ? '' : ' '.__('evaluations.shared.saved', ['parts' => $this->join($this->names($assessment, $parts))]);

        $key = $item->id ?? 'product';
        $einfo = $this->einfoReturn($assessment, $version, $data['return'] ?? null, $key);
        if ($einfo !== null) {
            return redirect()->to($einfo)->with('status', ($inconclusive ? __('assessment.inconclusive_saved') : __('evaluations.saved')).$also);
        }
        if ($inconclusive) {
            return redirect()->to(Links::walk($version, $standard, $requirement, $key))
                ->with('status', __('assessment.inconclusive_saved').$also);
        }
        if ($request->boolean('next')) {
            $assessment->refresh();
            $keys = [];
            foreach ($assessment->targets($standard, $requirement) as $target) {
                $keys[$target->id ?? 'product'] = $assessment->status($assessment->evaluation($standard, $requirement, $target), $tree);
            }
            $after = array_slice($keys, (int) array_search($key, array_keys($keys), true) + 1, null, true);
            $todo = array_key_first(array_filter($after, fn (string $status) => $status !== 'done'))
                ?? array_key_first(array_filter($keys, fn (string $status) => $status !== 'done'));
            if ($todo !== null) {
                $key = (string) $todo;
            } else {
                $codes = $this->navigable($assessment, $standard);
                $at = array_search($requirement, $codes, true);
                if ($at !== false && isset($codes[$at + 1])) {
                    $names = new TargetNames($assessment->pack);

                    return redirect()->route('versions.evaluate.requirement', [$version, $standard, $codes[$at + 1]])
                        ->with('status', __('evaluations.saved_next', ['code' => $requirement, 'count' => $names->count($assessment->targets($standard, $requirement), $definition)]).$also);
                }
            }
        }

        return redirect()->to(Links::walk($version, $standard, $requirement, $key))
            ->with('status', __('evaluations.saved').$also);
    }

    /**
     * Where "Save" goes when the tree was edited from the E.Info page (modal): that page of this
     * version, on the same object. The address given is never followed as is: it must be one of this
     * version's E.Info pages (same host when absolute, no user info, query or fragment), and the
     * redirect is rebuilt from its route with only the "missing only" filter kept. Anything else: null
     * (the usual evaluation page), so no open redirect.
     */
    private function einfoReturn(Assessment $assessment, ProductVersion $version, ?string $return, string $key): ?string
    {
        if ($return === null || $return === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $return) === 1) {
            return null;
        }
        $parts = parse_url($return);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['port'])) {
            $app = parse_url(url('/'));
            foreach (['scheme', 'host', 'port'] as $part) {
                if (($parts[$part] ?? null) !== ($app[$part] ?? null)) {
                    return null;
                }
            }
        }
        $path = (string) ($parts['path'] ?? '');
        foreach (array_keys($assessment->pack->standards()) as $standard) {
            $page = (string) parse_url(route('versions.einfo', [$version, $standard]), PHP_URL_PATH);
            $rest = str_starts_with($path, $page) ? substr($path, strlen($page)) : null;
            // The page itself, /missing, /{object} or /missing/{object}.
            if ($rest === null || preg_match('#^(/missing)?(/(product|[0-9a-z]{26}))?$#', $rest, $m) !== 1) {
                continue;
            }

            return Links::einfo($version, $standard, $key, ($m[1] ?? '') !== '');
        }

        return null;
    }

    /**
     * The requirements of a standard the previous / next links go through: those with something to
     * walk, and those whose walks all follow from another walk (shown, not walked).
     *
     * @return list<string>
     */
    private function navigable(Assessment $assessment, string $standard): array
    {
        $codes = [];
        foreach (array_keys($assessment->pack->requirementsFor($standard)) as $code) {
            if ($assessment->targets($standard, (string) $code) !== [] || $assessment->followsFrom($standard, (string) $code) !== []) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }

    /** Decide this part separately: the evaluation leaves its shared group (SharedWalks). */
    public function untie(Request $request, ProductVersion $version, string $standard, string $requirement): RedirectResponse
    {
        [$assessment, $evaluation, $key] = $this->sharedTarget($request, $version, $standard, $requirement);
        abort_unless($evaluation && ! $evaluation->shared_untied, 404);

        SharedWalks::for($assessment)->untie($evaluation);
        activity('evaluation')->performedOn($evaluation)->causedBy($request->user())->event('untied')->log('untied');

        return redirect()->to(Links::walk($version, $standard, $requirement, $key))
            ->with('status', __('evaluations.shared.untied'));
    }

    /** Use the answer shared with the other parts again (SharedWalks). */
    public function retie(Request $request, ProductVersion $version, string $standard, string $requirement): RedirectResponse
    {
        [$assessment, $evaluation, $key] = $this->sharedTarget($request, $version, $standard, $requirement);
        abort_unless($evaluation && $evaluation->shared_untied, 404);

        $parts = DB::transaction(fn () => SharedWalks::for($assessment)->retie($evaluation, $request->user()->id));
        activity('evaluation')->performedOn($evaluation)->causedBy($request->user())->event('tied')
            ->withProperties(['shared_with' => implode(', ', $this->names($assessment, $parts))])->log('tied');

        return redirect()->to(Links::walk($version, $standard, $requirement, $key))
            ->with('status', $parts === [] ? __('evaluations.shared.tied_alone') : __('evaluations.shared.tied', ['parts' => $this->join($this->names($assessment, $parts))]));
    }

    /**
     * @return array{0: Assessment, 1: Evaluation|null, 2: string}
     */
    private function sharedTarget(Request $request, ProductVersion $version, string $standard, string $requirement): array
    {
        $this->allow('edit', $version);
        $assessment = Assessment::for($version);
        $definition = $assessment->pack->requirement($requirement);
        abort_unless($definition && in_array($standard, $definition['standards'], true), 404);
        $data = $request->validate(['target' => ['required', 'string', 'max:26']]);
        $item = $this->target($assessment, $standard, $requirement, $data['target']);

        return [$assessment, $assessment->evaluation($standard, $requirement, $item), $item->id ?? 'product'];
    }

    /**
     * @param  list<string>  $standards
     * @return list<string>
     */
    private function names(Assessment $assessment, array $standards): array
    {
        return array_map(fn (string $s) => $assessment->pack->standardName($s), $standards);
    }

    /** @param  list<string>  $names  "A", "A and B", "A, B and C" */
    private function join(array $names): string
    {
        $last = array_pop($names);

        return $names === [] ? (string) $last : __('evaluations.shared.and', ['first' => implode(', ', $names), 'last' => $last]);
    }

    private function target(Assessment $assessment, string $standard, string $requirement, string $key): ?InventoryItem
    {
        foreach ($assessment->targets($standard, $requirement) as $item) {
            if (($item->id ?? 'product') === $key) {
                return $item;
            }
        }

        throw ValidationException::withMessages(['target' => __('evaluations.unknown_target')]);
    }

    /**
     * Either a walk through the tree (answers) or an expert entry (node + verdict).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?Verdict, 2: ?array<int, array<string, string>>}
     */
    private function outcome(?Tree $tree, array $data): array
    {
        if ($tree && ! empty($data['answers'])) {
            try {
                $walk = $tree->walk(array_values($data['answers']));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['answers' => __('evaluations.invalid_path')]);
            }
            if (! $walk['verdict']) {
                throw ValidationException::withMessages(['answers' => __('evaluations.incomplete_path')]);
            }

            return [$walk['last_decision'], $walk['verdict'], $walk['path']];
        }

        $node = $data['decision_node'] ?? null;
        $verdict = isset($data['verdict']) ? Verdict::from($data['verdict']) : null;

        if ($node === null && $verdict === null) {
            return [null, null, null];
        }
        if ($tree) {
            if ($node === null || ! in_array($node, $tree->decisionNodes(), true)) {
                throw ValidationException::withMessages(['decision_node' => __('evaluations.unknown_node')]);
            }
            if ($verdict === null || ! $tree->allows($node, $verdict)) {
                throw ValidationException::withMessages(['verdict' => __('evaluations.impossible_verdict', [
                    'node' => 'DT.'.$tree->requirement().'.'.$node,
                    'allowed' => collect($tree->verdictsFrom($node))->map->label()->join(', '),
                ])]);
            }
        }

        return [$node, $verdict, null];
    }

    /**
     * The walk so far for an INCONCLUSIVE evaluation: the answers given (guided) or the decision
     * node chosen (expert entry), never a verdict of the tree.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: Verdict, 2: ?array<int, array<string, string>>}
     */
    private function partialWalk(?Tree $tree, array $data): array
    {
        if ($tree && ! empty($data['partial'])) {
            try {
                $walk = $tree->walk(array_values($data['partial']));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['answers' => __('evaluations.invalid_path')]);
            }

            return [$walk['last_decision'], Verdict::Inconclusive, $walk['path'] ?: null];
        }
        $node = $data['decision_node'] ?? null;
        if ($node !== null && $tree && ! in_array($node, $tree->decisionNodes(), true)) {
            throw ValidationException::withMessages(['decision_node' => __('evaluations.unknown_node')]);
        }

        return [$tree ? $node : null, Verdict::Inconclusive, null];
    }
}
