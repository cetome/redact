<?php

namespace App\Http\Controllers;

use App\Content\ContentPack;
use App\Content\Text;
use App\Content\Tree;
use App\Models\ProductVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The EN 18031 decision trees, for reading: standards → requirements → tree.
 * Opened from an assessment page (/versions/{v}/evaluate/{standard}/trees), every trees page offers
 * the way back (owner, 2026-10-23: never stuck). The way back is flashed into the session and kept
 * while the person reads the trees: the next page elsewhere drops it. No address carries it.
 */
class DecisionTreeController extends Controller
{
    private const BACK = 'trees.back';

    /** "View the trees" on an assessment page: remember the way back, then open the standard's trees. */
    public function fromAssessment(ProductVersion $version, string $standard, ContentPack $pack): RedirectResponse
    {
        abort_unless($this->access($version->product)->canView(), 404);
        abort_unless(isset($pack->standards()[$standard]), 404);
        session()->flash(self::BACK, route('versions.evaluate', [$version, $standard]));

        return redirect()->route('trees.standard', $standard);
    }

    /** The assessment page to come back to, kept for the next trees page. */
    public static function back(): ?string
    {
        $back = session(self::BACK);
        if (! is_string($back) || ! str_starts_with($back, url('/versions').'/')) {
            return null;
        }
        session()->keep(self::BACK);

        return $back;
    }

    public function index(ContentPack $pack): View
    {
        $standards = [];
        foreach ($pack->standards() as $code => $standard) {
            $standards[$code] = [
                'name' => Text::of($standard['name']),
                'article' => $standard['article'] ?? null,
                'count' => count($pack->treesFor($code)),
            ];
        }

        return view('trees.index', ['standards' => $standards, 'articles' => $pack->articles(), 'back' => self::back()]);
    }

    public function standard(ContentPack $pack, string $standard): View
    {
        abort_unless(isset($pack->standards()[$standard]), 404);

        $families = [];
        foreach ($pack->treesFor($standard) as $code => $tree) {
            $family = $pack->requirement($code)['family'] ?? strtok($code, '-');
            $families[$family][] = $tree;
        }
        foreach ($families as &$trees) {
            usort($trees, fn (Tree $a, Tree $b) => strnatcmp($a->requirement(), $b->requirement()));
        }

        return view('trees.standard', [
            'standard' => $standard,
            'name' => $pack->standardName($standard),
            'families' => $families,
            'familyNames' => $pack->families(),
            'back' => self::back(),
        ]);
    }

    public function show(ContentPack $pack, string $standard, string $requirement): View
    {
        $tree = $pack->tree($standard, $requirement) ?? abort(404);

        return view('trees.show', [
            'standard' => $standard,
            'name' => $pack->standardName($standard),
            'tree' => $tree,
            'order' => $this->readingOrder($tree),
            'others' => array_values(array_filter(
                array_keys($pack->standards()),
                fn ($s) => $s !== $standard && $pack->tree($s, $requirement) !== null,
            )),
            'back' => self::back(),
        ]);
    }

    /**
     * Decision nodes in the order a reader meets them (breadth-first from the start).
     *
     * @return list<string>
     */
    private function readingOrder(Tree $tree): array
    {
        $order = [];
        $queue = [$tree->start()];
        while ($queue) {
            $id = array_shift($queue);
            if (in_array($id, $order, true) || ! $tree->has($id)) {
                continue;
            }
            $node = $tree->node($id);
            if ($node['kind'] !== 'decision') {
                continue;
            }
            $order[] = $id;
            foreach ($node['branches'] as $branch) {
                $queue[] = $branch['to'];
            }
        }

        return $order;
    }
}
