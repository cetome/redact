<?php

namespace App\Http\Controllers\Ai;

use App\Ai\AiClient;
use App\Ai\AiSettings;
use App\Ai\OrganisationAi;
use App\Billing\Plan;
use App\Http\Controllers\Controller;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * An organisation's Settings page: the "AI model" card (CRAted's). Its admins choose what answers the
 * organisation's AI buttons (the install's default, or their own key or model, App\Ai\OrganisationAi)
 * and test the connection; members read what answers them and change nothing. Everyone else: 404.
 * Bring-your-own is for every organisation of a self-hosted install, paid and Enterprise ones on the
 * SaaS (the free plan has no AI at all).
 */
class OrganisationAiController extends Controller
{
    public function __construct(private readonly OrganisationAi $ai) {}

    public function show(Request $request, Organisation $organisation): View
    {
        $user = $this->member($request, $organisation);
        $stored = $this->ai->stored($organisation);
        $choices = [];
        foreach (array_keys(OrganisationAi::CHOICES) as $choice) {
            $choices[$choice] = [
                'model' => OrganisationAi::defaultModel($choice),
                'key' => $this->ai->keyHint($organisation, $choice),
            ];
        }

        return view('organisations.settings', [
            'organisation' => $organisation,
            'isAdmin' => $organisation->isAdmin($user),
            'allowed' => $this->ai->allowed($organisation),
            'selfHosted' => Plan::selfHosted(),
            'installReady' => app(AiSettings::class)->configured(),
            'choice' => $this->ai->choice($organisation),
            'active' => $this->ai->active($organisation),
            'model' => (string) ($stored['model'] ?? ''),
            'endpoint' => $this->ai->endpoint($organisation),
            'readsPictures' => $this->ai->readsPictures($organisation),
            'keySetAt' => isset($stored['key_set_at']) ? Carbon::parse($stored['key_set_at']) : null,
            'choices' => $choices,
            'test' => session('org_ai_test'),
            // Free plan: no AI at all. An admin is shown where to add a paid product (or "Talk to us").
            'upgradeUrl' => Plan::for($organisation)->aiUpgradeUrl($user),
        ]);
    }

    public function update(Request $request, Organisation $organisation): RedirectResponse
    {
        $user = $this->admin($request, $organisation);

        $data = $request->validate([
            'choice' => ['required', Rule::in(array_keys(OrganisationAi::CHOICES))],
            'model' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/@+-]+$/'],
            'endpoint' => ['nullable', 'required_if:choice,openai_compatible', 'string', 'max:300', 'url:http,https'],
            'api_key' => ['nullable', 'string', 'max:500', 'regex:/^\S+$/'],
            'reads_pictures' => ['nullable', 'boolean'],
        ], [
            'model.regex' => __('org_ai.errors.model'),
            'api_key.regex' => __('org_ai.errors.key'),
        ]);
        $choice = $data['choice'];
        $key = isset($data['api_key']) && $data['api_key'] !== '' ? (string) $data['api_key'] : null;
        $errors = [];
        if ($choice === 'openai_compatible') {
            if (trim((string) ($data['model'] ?? '')) === '') {
                $errors['model'] = __('org_ai.errors.model_required');
            }
            if (($refusal = $this->ai->endpointRefusal((string) $data['endpoint'])) !== null) {
                $errors['endpoint'] = __('org_ai.errors.endpoint_'.$refusal);
            }
        } elseif ($choice !== 'default' && $key === null && ! $this->ai->hasKeyFor($organisation, $choice)) {
            // A stored key serves the provider it was given for: another provider needs its own key.
            $errors['api_key'] = __('org_ai.errors.key_required');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $before = $this->summary($organisation);
        $this->ai->save($organisation, $choice, (string) ($data['model'] ?? ''), (string) ($data['endpoint'] ?? ''), $key,
            (bool) ($data['reads_pictures'] ?? false), $user);

        activity('ai')->causedBy($user)->performedOn($organisation)->withProperties([
            'before' => $before, 'after' => $this->summary($organisation->fresh()), 'key_replaced' => $key !== null,
        ])->log('organisation ai model changed');

        return redirect()->route('organisations.settings', $organisation)->with('status', __('org_ai.answers.'.$choice));
    }

    public function test(Request $request, Organisation $organisation, AiClient $client): RedirectResponse
    {
        $user = $this->admin($request, $organisation);

        $result = $client->test($organisation);
        activity('ai')->causedBy($user)->performedOn($organisation)->withProperties([
            'ok' => $result['ok'], 'choice' => $this->ai->active($organisation) ? $this->ai->choice($organisation) : 'default', 'ms' => $result['ms'],
        ])->log('organisation ai connection tested');

        return redirect()->route('organisations.settings', $organisation)->with('org_ai_test', $result);
    }

    /** @return array<string, mixed> never the key */
    private function summary(Organisation $organisation): array
    {
        return [
            'choice' => $this->ai->choice($organisation),
            'model' => $this->ai->model($organisation),
            'endpoint' => $this->ai->endpoint($organisation),
        ];
    }

    private function member(Request $request, Organisation $organisation): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_if($user->is_guest, 404);
        abort_unless($organisation->hasMember($user), 404);

        return $user;
    }

    /** An admin of the organisation, allowed to bring its own model (404 otherwise, like every refusal). */
    private function admin(Request $request, Organisation $organisation): User
    {
        $user = $this->member($request, $organisation);
        abort_unless($organisation->isAdmin($user) && $this->ai->allowed($organisation), 404);

        return $user;
    }
}
