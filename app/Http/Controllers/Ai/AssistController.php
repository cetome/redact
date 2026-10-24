<?php

namespace App\Http\Controllers\Ai;

use App\Ai\AiException;
use App\Ai\Features\CheckEvaluation;
use App\Ai\Features\DraftFromDocuments;
use App\Ai\Features\ImproveText;
use App\Ai\Features\SuggestPath;
use App\Ai\Targets;
use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\ProductVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The AI buttons: improve a text, check an evaluation, suggest a path, draft
 * from the documentation. Each call needs `useAi` on the version (draft,
 * owner/contributor/org admin, AI allowed by the organisation), is
 * rate-limited and spends units. Results land in the AI channel only.
 */
class AssistController extends Controller
{
    public function improve(Request $request, ProductVersion $version, ImproveText $improve): JsonResponse
    {
        $this->allow('useAi', $version);
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys(Targets::FIELDS))],
            'key' => ['required', 'string', 'max:191'],
            'field' => ['required', 'string', 'max:80'],
            'text' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $suggestion = $improve($request->user(), $version, $data['type'], $data['key'], $data['field'], $data['text']);
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json([
            'id' => $suggestion->id,
            'original' => $suggestion->payload['original'],
            'improved' => $suggestion->payload['value'],
            'changes' => $suggestion->payload['changes'],
            'unchanged' => $suggestion->payload['unchanged'],
            'accept_url' => route('ai.suggestions.accept', [$version, $suggestion]),
            'reject_url' => route('ai.suggestions.reject', [$version, $suggestion]),
        ]);
    }

    public function check(Request $request, ProductVersion $version, Evaluation $evaluation, CheckEvaluation $check): JsonResponse|RedirectResponse
    {
        $this->allow('useAi', $version);
        abort_unless($evaluation->product_version_id === $version->getKey(), 404);

        try {
            $suggestion = $check($request->user(), $version, $evaluation);
        } catch (AiException $e) {
            return $this->failed($request, $e);
        }

        return $request->expectsJson()
            ? response()->json(['id' => $suggestion->id] + $suggestion->payload)
            : back()->with('status', __('ai.check.done'));
    }

    public function suggestPath(Request $request, ProductVersion $version, Evaluation $evaluation, SuggestPath $suggest): JsonResponse|RedirectResponse
    {
        $this->allow('useAi', $version);
        abort_unless($evaluation->product_version_id === $version->getKey(), 404);

        try {
            $suggestion = $suggest($request->user(), $version, $evaluation);
        } catch (AiException $e) {
            return $this->failed($request, $e);
        }

        return $request->expectsJson()
            ? response()->json(['id' => $suggestion->id] + $suggestion->payload)
            : back()->with('status', __('ai.path.done'));
    }

    public function draft(Request $request, ProductVersion $version, DraftFromDocuments $draft): JsonResponse|RedirectResponse
    {
        $this->allow('useAi', $version);

        try {
            $draft->start($request->user(), $version);
        } catch (AiException $e) {
            return $this->failed($request, $e);
        }

        $status = DraftFromDocuments::status($version);
        $message = ($status['state'] ?? null) === 'done'
            ? trans_choice('ai.draft.done', $status['count'] ?? 0, ['count' => $status['count'] ?? 0])
            : (($status['state'] ?? null) === 'failed' ? __('ai.draft.failed') : __('ai.draft.started'));

        return $request->expectsJson()
            ? response()->json(['status' => $status, 'message' => $message])
            : redirect()->route('versions.ai', $version)->with(($status['state'] ?? null) === 'failed' ? 'error' : 'status', $message);
    }

    private function failed(Request $request, AiException $e): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $e->getMessage()], $e->status)
            : back()->with('error', $e->getMessage());
    }
}
