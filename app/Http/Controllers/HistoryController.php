<?php

namespace App\Http\Controllers;

use App\Admin\Csv;
use App\History\Describer;
use App\History\Feed;
use App\Models\Organisation;
use App\Models\Product;
use App\Support\SessionFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer audit history (App\History\Feed): what happened on a product (every version) and in an
 * organisation, by whom and when, as cetome staff see it in the console's activity explorer.
 *
 * Product: its owner, the organisation's admins and its contributors, read-only
 * (ProductAccess::canSeeHistory); cetome staff read it as support access (logged like any
 * support visit). Organisation: its admins only. Reviewers, members, strangers and sandbox guests
 * get a 404 (guests are sent to "Keep my work" by GuestSandbox first). The CSV export is for
 * owners and admins, throttled (routes/app.php) and logged in the `history` log.
 *
 * No query strings: the filter form POSTs (…Filter), the filters live in the session
 * (App\Support\SessionFilters, one key per product / organisation) and the CSV export reads them;
 * older pages are /history/before/{activity id}.
 */
class HistoryController extends Controller
{
    public function product(Request $request, Product $product, ?int $before = null): View
    {
        abort_if($request->user()->is_guest, 404);
        $access = $this->access($product);
        abort_unless($access->canSeeHistory(), 404);

        // E-mail addresses for the owner, admins and support; contributors read names only.
        $feed = Feed::forProduct($product, $request->user(), $access->canExportHistory() || $access->isSupportAccess());
        $filters = $this->filters($request, $feed, $product, $before);

        return view('history.index', [
            'product' => $product,
            'organisation' => null,
            'feed' => $feed,
            'filters' => $filters,
            'page' => $feed->page($filters),
            'people' => $feed->people(),
            'versions' => array_reverse(Describer::versionLabels([$product->id]), true),
            'products' => [],
            'canExport' => $access->canExportHistory(),
            'csvUrl' => route('products.history.csv', $product),
            'here' => route('products.history', $product),
            'filterUrl' => route('products.history.filter', $product),
            'olderUrl' => fn (int $next) => route('products.history.before', [$product, $next]),
            'access' => $access,
        ]);
    }

    public function productCsv(Request $request, Product $product): StreamedResponse
    {
        abort_if($request->user()->is_guest, 404);
        $access = $this->access($product);
        abort_unless($access->canExportHistory(), 404);

        $feed = Feed::forProduct($product, $request->user(), true);
        $filters = $this->filters($request, $feed, $product);
        $this->logExport($request, $product, $filters);

        return Csv::download(
            'redact-history-'.Str::slug($product->name).'-'.now()->format('Y-m-d-His').'.csv',
            $feed->csvHeader(true),
            $feed->csvRows($filters, true),
        );
    }

    /** The filter form: remembered for this product, then back to the first page. */
    public function productFilter(Request $request, Product $product): RedirectResponse
    {
        abort_if($request->user()->is_guest, 404);
        abort_unless($this->access($product)->canSeeHistory(), 404);

        $this->remember($request, Feed::forProduct($product, $request->user(), false), $product);

        return redirect()->route('products.history', $product);
    }

    public function organisation(Request $request, Organisation $organisation, ?int $before = null): View
    {
        $this->authorizeAdmin($request, $organisation);

        $feed = Feed::forOrganisation($organisation, $request->user(), true);
        $filters = $this->filters($request, $feed, $organisation, $before);

        return view('history.index', [
            'product' => null,
            'organisation' => $organisation,
            'feed' => $feed,
            'filters' => $filters,
            'page' => $feed->page($filters),
            'people' => $feed->people(),
            'versions' => [],
            'products' => Describer::productNames($organisation),
            'canExport' => true,
            'csvUrl' => route('organisations.history.csv', $organisation),
            'here' => route('organisations.history', $organisation),
            'filterUrl' => route('organisations.history.filter', $organisation),
            'olderUrl' => fn (int $next) => route('organisations.history.before', [$organisation, $next]),
            'access' => null,
        ]);
    }

    public function organisationCsv(Request $request, Organisation $organisation): StreamedResponse
    {
        $this->authorizeAdmin($request, $organisation);

        $feed = Feed::forOrganisation($organisation, $request->user(), true);
        $filters = $this->filters($request, $feed, $organisation);
        $this->logExport($request, $organisation, $filters);

        return Csv::download(
            'redact-history-'.Str::slug($organisation->name).'-'.now()->format('Y-m-d-His').'.csv',
            $feed->csvHeader(true),
            $feed->csvRows($filters, true),
        );
    }

    public function organisationFilter(Request $request, Organisation $organisation): RedirectResponse
    {
        $this->authorizeAdmin($request, $organisation);

        $this->remember($request, Feed::forOrganisation($organisation, $request->user(), true), $organisation);

        return redirect()->route('organisations.history', $organisation);
    }

    /**
     * The remembered filters of this history, and the page cursor from the address.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request, Feed $feed, Product|Organisation $subject, ?int $before = null): array
    {
        $filters = SessionFilters::get($request, $this->key($subject), array_fill_keys(array_keys($this->rules($feed)), null));

        // A product filter of the organisation history names one of its products, or "all".
        if (! empty($filters['products']) && $filters['products'] !== 'all'
            && ! array_key_exists($filters['products'], $subject instanceof Organisation ? Describer::productNames($subject) : [])) {
            $filters['products'] = null;
        }

        return $filters + ['before' => $before !== null && $before > 0 ? $before : null];
    }

    private function remember(Request $request, Feed $feed, Product|Organisation $subject): void
    {
        if (SessionFilters::resetting($request)) {
            SessionFilters::forget($request, $this->key($subject));

            return;
        }
        SessionFilters::remember($request, $this->key($subject), $request->validate($this->rules($feed)));
    }

    /** @return array<string, list<mixed>> */
    private function rules(Feed $feed): array
    {
        return array_diff_key($feed->rules(), ['before' => true]);
    }

    private function key(Product|Organisation $subject): string
    {
        return ($subject instanceof Product ? 'history.product.' : 'history.organisation.').$subject->getKey();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function query(array $filters): array
    {
        return array_filter(array_diff_key($filters, ['before' => true]), fn ($v) => $v !== null && $v !== '');
    }

    /** @param  array<string, mixed>  $filters */
    private function logExport(Request $request, Product|Organisation $subject, array $filters): void
    {
        activity('history')->causedBy($request->user())->performedOn($subject)->event('exported')
            ->withProperties(['format' => 'csv', 'filters' => $this->query($filters)])
            ->log('exported');
    }

    private function authorizeAdmin(Request $request, Organisation $organisation): void
    {
        $user = $request->user();
        abort_if($user->is_guest, 404);
        abort_unless($organisation->isAdmin($user), 404);
    }
}
