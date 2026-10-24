<?php

namespace App\Http\Controllers\Admin;

use App\Admin\Csv;
use App\Admin\UserDirectory;
use App\Admin\UserInsight;
use App\Models\Product;
use App\Models\User;
use App\Support\PathPaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends AdminController
{
    /** Platform roles the console can grant or revoke. */
    public const ROLES = ['staff', 'super-admin'];

    public function index(Request $request, UserDirectory $directory, int $page = 1): View
    {
        $filters = $this->filters($request);

        return view('admin.users.index', [
            'users' => PathPaginator::of($directory->query($filters), 25, $page, route('admin.users.index')),
            'filters' => $filters,
            'statuses' => UserDirectory::STATUSES,
            'canExport' => $this->isSuperAdmin($request),
        ]);
    }

    /** The search form (POST): remembered in the session, then the clean list address. */
    public function filter(Request $request): RedirectResponse
    {
        return $this->rememberFilters($request, 'admin.users', $this->rules(), route('admin.users.index'));
    }

    public function export(Request $request, UserDirectory $directory): StreamedResponse
    {
        $this->requireSuperAdmin($request);
        $filters = $this->filters($request);

        activity('admin')->causedBy($this->staffUser($request))
            ->withProperties(['filters' => array_filter($filters)])
            ->log('users exported');

        return Csv::download('redact-users-'.now()->format('Y-m-d-His').'.csv', UserDirectory::csvHeader(), $directory->csvRows($filters));
    }

    public function show(Request $request, User $user): View
    {
        $filters = array_filter(
            $this->rememberedFilters($request, $this->timelineKey($user), $this->timelineRules()),
            fn ($value) => $value !== null,
        );

        $insight = new UserInsight($user);
        $timeline = $insight->timeline($filters);

        $productIds = $timeline->pluck('product_id')->filter()->unique()->values();
        $timelineProducts = $productIds->isEmpty()
            ? collect()
            : Product::withTrashed()->whereIn('id', $productIds)->get(['id', 'name', 'deleted_at'])->keyBy('id');

        $user->load(['organisations', 'roles', 'passkeys' => fn ($q) => $q->orderByDesc('created_at')]);

        return view('admin.users.show', [
            'user' => $user,
            'memberships' => $insight->memberships(),
            'sessions' => $insight->sessions(),
            'security' => $insight->securityEvents(),
            'timeline' => $timeline,
            'timelineProducts' => $timelineProducts,
            'filters' => $filters + ['source' => 'all', 'from' => null, 'to' => null],
            'sources' => UserInsight::SOURCES,
            'isSuperAdmin' => $this->isSuperAdmin($request),
            'isSelf' => $user->is($request->user()),
            'canManage' => $this->canManage($request, $user),
            'roles' => self::ROLES,
        ]);
    }

    /** The timeline filter form (POST): remembered for this person, then back to their page. */
    public function timeline(Request $request, User $user): RedirectResponse
    {
        return $this->rememberFilters($request, $this->timelineKey($user), $this->timelineRules(), route('admin.users.show', $user));
    }

    private function timelineKey(User $user): string
    {
        return 'admin.user.'.$user->id.'.timeline';
    }

    /** @return array<string, list<mixed>> */
    private function timelineRules(): array
    {
        return [
            'source' => ['nullable', Rule::in(UserInsight::SOURCES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function disable(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', __('admin.users.errors.self_disable'));
        }
        abort_unless($this->canManage($request, $user), 404);

        $user->forceFill(['active' => false])->save();
        $this->endSessions($user);
        $this->audit($request, $user, 'user disabled');

        return back()->with('status', __('admin.users.flash.disabled'));
    }

    public function enable(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request, $user), 404);

        $user->forceFill(['active' => true])->save();
        $this->audit($request, $user, 'user enabled');

        return back()->with('status', __('admin.users.flash.enabled'));
    }

    public function revokeSessions(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request, $user) || $user->is($request->user()), 404);

        $this->endSessions($user);
        $this->audit($request, $user, 'sessions revoked');

        return back()->with('status', __('admin.users.flash.sessions_revoked'));
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'role' => ['required', Rule::in(self::ROLES)],
            'action' => ['required', Rule::in(['grant', 'revoke'])],
        ]);

        $role = Role::findOrCreate($data['role'], 'web');

        if ($data['action'] === 'grant') {
            // A guest sandbox account never holds a platform role.
            abort_if($user->is_guest, 404);
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
                $this->audit($request, $user, 'role granted', ['role' => $role->name]);
            }

            return back()->with('status', __('admin.users.flash.role_granted', ['role' => $role->name]));
        }

        if ($role->name === 'super-admin') {
            if ($user->is($request->user())) {
                return back()->with('error', __('admin.users.errors.self_super_admin'));
            }
            if (User::role('super-admin')->count() <= 1) {
                return back()->with('error', __('admin.users.errors.last_super_admin'));
            }
        }

        if ($user->hasRole($role)) {
            $user->removeRole($role);
            // New rights apply at the next sign-in, old ones end now.
            $this->endSessions($user);
            $this->audit($request, $user, 'role revoked', ['role' => $role->name]);
        }

        return back()->with('status', __('admin.users.flash.role_revoked', ['role' => $role->name]));
    }

    /**
     * Staff may act on customer accounts. Accounts of other staff and super-admins
     * are managed by super-admins only.
     */
    private function canManage(Request $request, User $target): bool
    {
        if ($target->is($request->user())) {
            return false;
        }

        return $this->isSuperAdmin($request) || ! $target->isStaff();
    }

    /** Signs the account out everywhere: session epoch (checked on every request) and stored sessions. */
    private function endSessions(User $user): void
    {
        $user->revokeSessions();

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(Request $request, User $user, string $description, array $properties = []): void
    {
        activity('admin')
            ->causedBy($this->staffUser($request))
            ->performedOn($user)
            ->event(str_replace(' ', '_', $description))
            ->withProperties($properties + ['email' => $user->email])
            ->log($description);
    }

    /** @return array{q: ?string, status: ?string} */
    private function filters(Request $request): array
    {
        $data = $this->rememberedFilters($request, 'admin.users', $this->rules());

        return ['q' => $data['q'] ?? null, 'status' => $data['status'] ?? null];
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(UserDirectory::STATUSES)],
        ];
    }
}
