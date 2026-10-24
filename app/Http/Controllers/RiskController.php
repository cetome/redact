<?php

namespace App\Http\Controllers;

use App\Assessment\ArticlesCheck;
use App\Assessment\Assessment;
use App\Assessment\ThreatSuggestions;
use App\Content\ContentPack;
use App\Content\Text;
use App\Models\ProductVersion;
use App\Models\RiskEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * STRIDE risk register with an editable 5×5 matrix (the level is looked up,
 * never computed from the score), as in the REDact Risk assessment workbook.
 */
class RiskController extends Controller
{
    /** One risk in the side panel (without script; data-dialog-fetch loads it in place). */
    public function edit(Request $request, ProductVersion $version, RiskEntry $risk, ContentPack $pack): View
    {
        abort_unless($risk->product_version_id === $version->id, 404);

        return $this->index($request, $version, $pack, $risk);
    }

    /** The register, or with a panel open: /new, /library, /matrix, /{risk} (edit()). */
    public function index(Request $request, ProductVersion $version, ContentPack $pack, ?RiskEntry $open = null): View
    {
        $panel = (string) $request->route('panel');
        $access = $this->access($version->product);
        abort_unless($access->canView(), 404);

        $levels = $version->riskLevels()->get()->keyBy('rank');
        $cells = $version->riskMatrixCells()->get()->mapWithKeys(fn ($c) => [$c->impact.'-'.$c->likelihood => $c->level_rank]);
        $entries = $version->riskEntries()->get();

        // The risks come after "Essential requirements": its answers put the articles and EN 18031
        // parts in scope first (null: not answered yet, nothing is marked), and the panel under the
        // register checks the risks against them (ArticlesCheck). The risk page never depends on
        // the inventory.
        $assessment = Assessment::for($version);
        $check = new ArticlesCheck($assessment);
        $answered = $check->answered();
        $scopedStandards = $answered ? $assessment->standards() : null;
        $scopedArticles = $answered ? array_keys(array_filter($assessment->scoping()->articles)) : null;

        // The EN 18031 mechanisms to pick from: the families, then their requirements; those of the
        // parts in scope first (family and requirement "in_scope"), the others after them.
        $mechanisms = [];
        foreach ($pack->families() as $code => $family) {
            $mechanisms[$code] = ['code' => $code, 'name' => Text::of($family['name'] ?? ''), 'requirements' => [], 'in_scope' => $scopedStandards === null];
        }
        foreach ($pack->requirements() as $code => $requirement) {
            $in = $scopedStandards === null || array_intersect($requirement['standards'] ?? [], $scopedStandards) !== [];
            $mechanisms[$requirement['family']] ??= ['code' => $requirement['family'], 'name' => '', 'requirements' => [], 'in_scope' => false];
            $mechanisms[$requirement['family']]['requirements'][$code] = ['title' => Text::of($requirement['title'] ?? ''), 'in_scope' => $in];
            $mechanisms[$requirement['family']]['in_scope'] = $mechanisms[$requirement['family']]['in_scope'] || $in;
        }
        foreach ($mechanisms as &$family) {
            $family['requirements'] = collect($family['requirements'])->sortBy(fn (array $r) => $r['in_scope'] ? 0 : 1)->all();
        }
        unset($family);
        $mechanisms = collect($mechanisms)->sortBy(fn (array $f) => $f['in_scope'] ? 0 : 1)->all();

        // The articles, those in scope first.
        $articles = collect($pack->articles())
            ->sortBy(fn ($name, $code) => $scopedArticles === null || in_array($code, $scopedArticles, true) ? 0 : 1)->all();

        // Which record a side panel shows: /{risk}, /new, or the form that just failed validation.
        $form = (string) old('_risk');
        $editId = $form !== '' && $form !== 'new' ? $form : $open?->id;

        return view('risks.index', [
            'version' => $version,
            'access' => $access,
            'entries' => $entries,
            'levels' => $levels,
            'cells' => $cells,
            'stride' => $pack->risk()['stride'] ?? [],
            'library' => (new ThreatSuggestions($assessment))->rows(),
            'articles' => $articles,
            'scopedArticles' => $scopedArticles,
            'mechanisms' => $mechanisms,
            'editing' => is_string($editId) ? $entries->firstWhere('id', $editId) : null,
            'adding' => $form === 'new' || $panel === 'new',
            'panel' => in_array($panel, ['library', 'matrix'], true) ? $panel : null,
            'cover' => ['articles' => $check->articles(), 'coverage' => $check->coverage(), 'outside' => $check->namedOutsideScope()],
        ]);
    }

    public function store(Request $request, ProductVersion $version): RedirectResponse
    {
        $this->allow('edit', $version);

        $data = $this->validated($request, $version);
        $data['position'] = (int) $version->riskEntries()->max('position') + 1;
        $data['updated_by'] = $request->user()->id;
        $version->riskEntries()->create($data);

        return redirect()->route('versions.risks', $version)->with('status', __('risks.added'));
    }

    public function update(Request $request, ProductVersion $version, RiskEntry $risk): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($risk->product_version_id === $version->id, 404);

        $risk->update($this->validated($request, $version) + ['updated_by' => $request->user()->id]);

        return redirect()->to(route('versions.risks', $version).'#risk-'.$risk->id)->with('status', __('common.saved'));
    }

    public function destroy(ProductVersion $version, RiskEntry $risk): RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($risk->product_version_id === $version->id, 404);

        $risk->delete();

        return redirect()->route('versions.risks', $version)->with('status', __('risks.deleted'));
    }

    public function addFromLibrary(Request $request, ProductVersion $version, ContentPack $pack): RedirectResponse
    {
        $this->allow('edit', $version);

        $library = $pack->risk()['threats'] ?? [];
        $data = $request->validate([
            'threats' => ['required', 'array'],
            'threats.*' => ['integer', 'min:0', 'max:'.max(count($library) - 1, 0)],
        ]);

        $position = (int) $version->riskEntries()->max('position');
        foreach (array_unique($data['threats']) as $index) {
            $threat = $library[$index];
            $version->riskEntries()->create([
                'position' => ++$position,
                'threat' => Text::of($threat['threat']),
                'description' => Text::of($threat['description'] ?? ''),
                'risk' => Text::of($threat['risk'] ?? '') ?: null,
                'stride' => array_values(array_intersect($threat['stride'] ?? [], RiskEntry::STRIDE)),
                'articles' => array_values(array_intersect($threat['articles'] ?? [], array_keys($pack->articles()))),
                'impact' => $this->scale($threat['impact'] ?? null),
                'likelihood' => $this->scale($threat['likelihood'] ?? null),
                'residual_rank' => $this->scale($threat['residual_rank'] ?? null),
                'requirements' => $threat['requirements'] ?? [],
                'remediation' => Text::of($threat['remediation'] ?? '') ?: null,
                'updated_by' => $request->user()->id,
            ]);
        }

        return redirect()->route('versions.risks', $version)->with('status', trans_choice('risks.added_many', count(array_unique($data['threats'])), ['count' => count(array_unique($data['threats']))]));
    }

    /** A 1-5 rating from the pack, or null. */
    private function scale(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 && $value <= 5 ? $value : null;
    }

    public function updateMatrix(Request $request, ProductVersion $version): RedirectResponse
    {
        $this->allow('edit', $version);

        $data = $request->validate([
            'levels' => ['required', 'array', 'size:5'],
            'levels.*.name' => ['required', 'string', 'max:40'],
            'levels.*.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'cells' => ['required', 'array'],
            'cells.*' => ['required', 'integer', 'between:1,5'],
        ]);

        DB::transaction(function () use ($version, $data) {
            foreach ($data['levels'] as $rank => $level) {
                abort_unless(in_array((int) $rank, [1, 2, 3, 4, 5], true), 422);
                $version->riskLevels()->updateOrCreate(['rank' => (int) $rank], $level);
            }
            foreach ($data['cells'] as $key => $rank) {
                abort_unless(preg_match('/^([1-5])-([1-5])$/', (string) $key, $m) === 1, 422);
                $version->riskMatrixCells()->updateOrCreate(
                    ['impact' => (int) $m[1], 'likelihood' => (int) $m[2]],
                    ['level_rank' => (int) $rank],
                );
            }
        });

        return redirect()->route('versions.risks', $version)->with('status', __('risks.matrix_saved'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ProductVersion $version): array
    {
        $pack = app(ContentPack::class);
        $articles = array_keys($pack->articles());

        $data = $request->validate([
            'threat' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'stride' => ['nullable', 'array'],
            'stride.*' => [Rule::in(RiskEntry::STRIDE)],
            'risk' => ['nullable', 'string', 'max:5000'],
            'impact' => ['nullable', 'integer', 'between:1,5'],
            'likelihood' => ['nullable', 'integer', 'between:1,5'],
            'cell' => ['nullable', 'regex:/^[1-5]-[1-5]$/'],
            'articles' => ['nullable', 'array'],
            'articles.*' => [Rule::in($articles)],
            'requirements' => ['nullable', 'max:500'],
            'requirements.*' => ['string', 'max:20'],
            'remediation' => ['nullable', 'string', 'max:5000'],
            'implementation' => ['nullable', 'string', 'max:5000'],
            'residual_rank' => ['nullable', 'integer', 'between:1,5'],
        ]);

        // The picker sends a list; "ACM, AUM-1-1, GEC-2" is read too. Only known families and requirements stay.
        $known = array_merge(array_keys($pack->families()), array_keys($pack->requirements()));
        $given = $data['requirements'] ?? [];
        $given = is_array($given) ? $given : preg_split('/[,\s]+/', (string) $given, -1, PREG_SPLIT_NO_EMPTY);
        $data['requirements'] = array_values(array_unique(array_intersect(array_map('trim', $given), $known)));

        // The heat map gives impact and likelihood as one cell, "impact-likelihood" (empty: not rated).
        if ($request->has('cell')) {
            [$data['impact'], $data['likelihood']] = filled($data['cell'] ?? null) ? array_map('intval', explode('-', $data['cell'])) : [null, null];
        }
        unset($data['cell']);
        $data['stride'] ??= [];
        $data['articles'] ??= [];

        return $data;
    }
}
