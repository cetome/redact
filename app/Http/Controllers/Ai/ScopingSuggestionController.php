<?php

namespace App\Http\Controllers\Ai;

use App\Ai\AiException;
use App\Ai\Availability;
use App\Ai\Features\SuggestScoping;
use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\ProductVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Suggest with AI" on the scoping: on New product (before the product exists, for one
 * of the person's organisations) and on the scoping step of a draft version. JSON only:
 * the answer is shown on the cards, nothing is saved until the person saves the form.
 */
class ScopingSuggestionController extends Controller
{
    public function forNew(Request $request, SuggestScoping $suggest): JsonResponse
    {
        $user = $request->user();
        abort_if($user->is_guest, 404);
        $data = $request->validate([
            'organisation_id' => ['required', 'string', 'max:26'],
            'name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        $organisation = $user->organisations()->whereKey($data['organisation_id'])->first();
        if (! $organisation instanceof Organisation) {
            abort(404);
        }

        if ($reason = Availability::reasonForOrganisation($user, $organisation)) {
            return response()->json(['message' => $reason], 503);
        }

        try {
            return response()->json($suggest->forNew($user, $organisation, (string) ($data['name'] ?? ''), (string) ($data['description'] ?? '')));
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    public function forVersion(Request $request, ProductVersion $version, SuggestScoping $suggest): JsonResponse
    {
        $this->allow('useAi', $version);

        try {
            return response()->json($suggest->forVersion($request->user(), $version));
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
