<?php

namespace App\Http\Controllers\Admin;

use App\Ai\AiClient;
use App\Ai\AiSettings;
use App\Billing\Plan;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "AI settings" (console): the provider, model, endpoint and API key of the install, bring your own
 * key and model. Staff see the settings (the key only as its last 4 characters); a super-admin
 * changes them, resets them to .env and tests the connection. Every change is logged ("admin"),
 * never the key itself.
 */
class AiSettingsController extends AdminController
{
    public function __construct(private readonly AiSettings $settings) {}

    public function edit(Request $request): View
    {
        $stored = $this->settings->stored();
        $editor = isset($stored['_updated_by']) ? User::query()->find($stored['_updated_by']) : null;

        return view('admin.ai.edit', [
            'settings' => $this->settings,
            'providers' => AiSettings::PROVIDERS,
            'canEdit' => $this->isSuperAdmin($request),
            'updatedAt' => isset($stored['_updated_at']) ? Carbon::parse($stored['_updated_at']) : null,
            'editor' => $editor,
            'selfHosted' => Plan::selfHosted(),
            'test' => session('ai_test'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->requireSuperAdmin($request);

        if ($request->input('action') === 'reset') {
            $this->settings->reset();
            $this->log($request, 'ai settings reset', []);

            return redirect()->route('admin.ai.edit')->with('status', __('ai_settings.reset_done'));
        }

        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(AiSettings::PROVIDERS))],
            'model' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/@+-]+$/'],
            'base_url' => [
                Rule::requiredIf(fn () => $request->input('provider') === 'openai_compatible' && trim((string) config('redact.ai.base_url')) === ''),
                'nullable', 'string', 'max:300', 'url:http,https',
            ],
            'api_key' => ['nullable', 'string', 'max:500', 'regex:/^\S+$/'],
            'clear_key' => ['nullable', 'boolean'],
        ], [
            'model.regex' => __('ai_settings.errors.model'),
            'api_key.regex' => __('ai_settings.errors.key'),
        ]);
        if ($data['provider'] === 'openai_compatible' && trim((string) ($data['model'] ?? '')) === '') {
            return back()->withInput($request->except('api_key'))->withErrors(['model' => __('ai_settings.errors.model_required')]);
        }

        $before = $this->summary();
        $newKey = isset($data['api_key']) && $data['api_key'] !== '' ? (string) $data['api_key'] : null;
        $this->settings->save($data['provider'], (string) ($data['model'] ?? ''), (string) ($data['base_url'] ?? ''),
            $newKey, (bool) ($data['clear_key'] ?? false), $this->staffUser($request));

        $this->log($request, 'ai settings changed', [
            'before' => $before,
            'after' => $this->summary(),
            'key_replaced' => $newKey !== null,
            'key_cleared' => (bool) ($data['clear_key'] ?? false),
        ]);

        return redirect()->route('admin.ai.edit')->with('status', __('ai_settings.saved'));
    }

    public function test(Request $request, AiClient $client): RedirectResponse
    {
        $this->requireSuperAdmin($request);

        $result = $client->test();
        $this->log($request, 'ai connection tested', ['ok' => $result['ok'], 'provider' => $this->settings->provider(), 'model' => $result['model'], 'ms' => $result['ms']]);

        return redirect()->route('admin.ai.edit')->with('ai_test', $result);
    }

    /** @return array<string, mixed> what a log line may say: never the key, only where it comes from */
    private function summary(): array
    {
        return [
            'provider' => $this->settings->provider(),
            'model' => $this->settings->model(),
            'base_url' => $this->settings->baseUrl(),
            'key' => $this->settings->keySource(),
        ];
    }

    /** @param  array<string, mixed>  $properties */
    private function log(Request $request, string $description, array $properties): void
    {
        activity('admin')->causedBy($this->staffUser($request))->withProperties($properties)->log($description);
    }
}
