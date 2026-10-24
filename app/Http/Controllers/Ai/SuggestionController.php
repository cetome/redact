<?php

namespace App\Http\Controllers\Ai;

use App\Ai\AiException;
use App\Ai\Availability;
use App\Ai\Feature;
use App\Ai\Features\DraftFromDocuments;
use App\Ai\Provenance;
use App\Ai\Quota;
use App\Ai\ReviewPresenter;
use App\Ai\Targets;
use App\Documents\DocumentStore;
use App\Enums\Origin;
use App\Http\Controllers\Controller;
use App\Models\AiSuggestion;
use App\Models\ProductVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The person's side of the AI channel: the review page, and Accept / Reject.
 * Accepting writes data, so it needs `edit` on the version; the value always
 * comes from the stored suggestion, never from the browser.
 */
class SuggestionController extends Controller
{
    public function index(Request $request, ProductVersion $version, Quota $quota): View
    {
        $this->allow('view', $version);
        $this->access($version->product);
        $user = $request->user();

        return view('ai.review', [
            'version' => $version,
            'groups' => ReviewPresenter::pending($version),
            'decided' => ReviewPresenter::decided($version),
            'canEdit' => Gate::allows('edit', $version),
            'unavailable' => Availability::reason($user, $version),
            'allowance' => $quota->allowance($user, $version->product->organisation),
            'documents' => $version->documents()->count(),
            'maxDocuments' => DocumentStore::maxCount(),
            'draftUnits' => DraftFromDocuments::units($version),
            'draftStatus' => DraftFromDocuments::status($version),
            'draftRunning' => DraftFromDocuments::running($version),
        ]);
    }

    /** Draft progress, polled by the review page while a draft runs. */
    public function status(ProductVersion $version): JsonResponse
    {
        $this->allow('view', $version);

        return response()->json(['running' => DraftFromDocuments::running($version)]);
    }

    public function accept(Request $request, ProductVersion $version, AiSuggestion $suggestion, Provenance $provenance): JsonResponse|RedirectResponse
    {
        $this->allow('edit', $version);
        abort_unless($suggestion->product_version_id === $version->getKey(), 404);

        try {
            $suggestion = $provenance->accept($suggestion, $request->user());
        } catch (AiException $e) {
            return $this->failed($request, $e);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'accepted',
                'value' => $suggestion->payload['value'] ?? null,
                'created_id' => $suggestion->payload['created_id'] ?? null,
                'origin' => ($suggestion->origin ?? Origin::AiGenerated)->value,
            ]);
        }

        return back()->with('status', __('ai.review.accepted'));
    }

    public function reject(Request $request, ProductVersion $version, AiSuggestion $suggestion, Provenance $provenance): JsonResponse|RedirectResponse
    {
        // Dismissing a check or a path suggestion only needs the right to use the AI on the version.
        $acceptable = in_array(Feature::tryFrom($suggestion->feature), Provenance::ACCEPTABLE, true);
        abort_unless(Gate::allows('edit', $version) || (! $acceptable && Gate::allows('useAi', $version)), 404);
        abort_unless($suggestion->product_version_id === $version->getKey(), 404);

        try {
            $provenance->reject($suggestion, $request->user());
        } catch (AiException $e) {
            return $this->failed($request, $e);
        }

        return $request->expectsJson()
            ? response()->json(['status' => 'rejected'])
            : back()->with('status', __($acceptable ? 'ai.review.rejected' : 'ai.review.dismissed'));
    }

    public function acceptGroup(Request $request, ProductVersion $version, Provenance $provenance): JsonResponse|RedirectResponse
    {
        $this->allow('edit', $version);
        $data = $request->validate([
            'target_type' => ['required', 'string', 'in:'.implode(',', array_keys(Targets::FIELDS))],
            'target_key' => ['required', 'string', 'max:191'],
        ]);

        $result = $provenance->acceptGroup($version, $data['target_type'], $data['target_key'], $request->user());
        $message = trans_choice('ai.review.accepted_many', $result['accepted'], ['count' => $result['accepted']])
            .($result['failed'] ? ' '.trans_choice('ai.review.failed_many', $result['failed'], ['count' => $result['failed']]) : '');

        return $request->expectsJson()
            ? response()->json($result + ['message' => $message])
            : back()->with($result['failed'] && ! $result['accepted'] ? 'error' : 'status', $message);
    }

    private function failed(Request $request, AiException $e): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $e->getMessage()], $e->status)
            : back()->with('error', $e->getMessage());
    }
}
