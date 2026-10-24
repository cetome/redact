<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Plan;
use App\Models\Organisation;
use App\Support\PathPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Customer organisations, their plan and billing state. Staff set the plan (free, enterprise)
 * and per-organisation overrides (e.g. extra products granted to a partner): logged in the
 * console log with the before and after values. No payment happens here.
 * Search in the session (POST …/filter), pages /admin/organisations/page/{n}.
 */
class OrganisationController extends AdminController
{
    private const FILTERS = 'admin.organisations';

    private const RULES = ['q' => ['nullable', 'string', 'max:120']];

    public function index(Request $request, int $page = 1): View
    {
        $filters = $this->rememberedFilters($request, self::FILTERS, self::RULES);
        $term = trim((string) ($filters['q'] ?? ''));

        $organisations = Organisation::query()
            ->withCount(['products', 'members'])
            ->with(['subscriptions'])
            ->when($term !== '', function (Builder $query) use ($term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'like', $like)
                    ->orWhereHas('members', fn (Builder $m) => $m->where('email', 'like', $like)));
            })
            ->orderBy('name');

        return view('admin.organisations.index', [
            'organisations' => PathPaginator::of($organisations, 25, $page, route('admin.organisations.index')),
            'filters' => ['q' => $filters['q']],
        ]);
    }

    public function filter(Request $request): RedirectResponse
    {
        return $this->rememberFilters($request, self::FILTERS, self::RULES, route('admin.organisations.index'));
    }

    public function show(Organisation $organisation): View
    {
        $organisation->load(['members']);

        return view('admin.organisations.show', [
            'organisation' => $organisation,
            'plan' => Plan::fresh($organisation),
            'products' => $organisation->products()->withTrashed()->with('owner:id,name,email')->orderBy('created_at')->get(),
            'billingEnabled' => Plan::billingEnabled(),
        ]);
    }

    public function update(Request $request, Organisation $organisation): RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(Plan::PLANS)],
            'extra_products' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'team_members' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'ai_units' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'ai_period' => ['nullable', Rule::in(Plan::PERIODS)],
            'support' => ['nullable', Rule::in(['yes', 'no'])],
        ]);

        $limits = array_filter([
            'extra_products' => isset($data['extra_products']) ? (int) $data['extra_products'] : null,
            'team_members' => isset($data['team_members']) ? (int) $data['team_members'] : null,
            'ai_units' => isset($data['ai_units']) ? (int) $data['ai_units'] : null,
            'ai_period' => $data['ai_period'] ?? null,
            'support' => isset($data['support']) ? $data['support'] === 'yes' : null,
        ], fn ($v) => $v !== null);

        $before = ['plan' => $organisation->plan, 'limits' => $organisation->limits];
        $organisation->forceFill(['plan' => $data['plan'], 'limits' => $limits === [] ? null : $limits])->save();
        $after = ['plan' => $organisation->plan, 'limits' => $organisation->limits];

        if ($before != $after) {
            activity('admin')
                ->causedBy($this->staffUser($request))
                ->performedOn($organisation)
                ->event('plan_changed')
                ->withProperties(['old' => $before, 'attributes' => $after, 'organisation' => $organisation->name])
                ->log('plan changed');
        }

        return redirect()->route('admin.organisations.show', $organisation)->with('status', __('plans.admin.saved'));
    }
}
