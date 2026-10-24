<?php

namespace App\Http\Controllers\Admin;

use App\Admin\ActivityExplorer;
use App\Admin\Csv;
use App\Support\PathPaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Audit trail explorer (spatie/laravel-activitylog). Filters in the session (POST …/filter),
 * pages /admin/activity/page/{n}.
 */
class ActivityController extends AdminController
{
    private const FILTERS = 'admin.activity';

    public function index(Request $request, ActivityExplorer $explorer, int $page = 1): View
    {
        $filters = $this->filters($request);

        return view('admin.activity.index', [
            'activities' => PathPaginator::of($explorer->query($filters), 50, $page, route('admin.activity.index')),
            'filters' => $filters,
            'logNames' => $explorer->logNames(),
            'subjectTypes' => $explorer->subjectTypes(),
            'canExport' => $this->isSuperAdmin($request),
        ]);
    }

    public function filter(Request $request): RedirectResponse
    {
        return $this->rememberFilters($request, self::FILTERS, ActivityExplorer::rules(), route('admin.activity.index'));
    }

    public function export(Request $request, ActivityExplorer $explorer): StreamedResponse
    {
        $this->requireSuperAdmin($request);
        $filters = $this->filters($request);

        activity('admin')->causedBy($this->staffUser($request))
            ->withProperties(['filters' => array_filter($filters)])
            ->log('activity exported');

        return Csv::download('redact-activity-'.now()->format('Y-m-d-His').'.csv', ActivityExplorer::csvHeader(), $explorer->csvRows($filters));
    }

    /** @return array<string, ?string> */
    private function filters(Request $request): array
    {
        /** @var array<string, ?string> */
        return $this->rememberedFilters($request, self::FILTERS, ActivityExplorer::rules());
    }
}
