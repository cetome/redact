<?php

namespace App\Http\Controllers\Admin;

use App\Billing\Catalogue;
use App\Billing\Plan;
use App\Billing\StripeSettings;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * "Stripe" (console): the keys and the webhook billing uses (prices: "Plans and prices"),
 * sandbox first, then live. Staff see the settings (secrets only as their last 4 characters); a
 * super-admin changes them, creates the webhook endpoint in Stripe, and resets to
 * .env. Every change is logged ("admin"), never a secret.
 */
class StripeSettingsController extends AdminController
{
    public function __construct(private readonly StripeSettings $settings) {}

    public function edit(Request $request): View
    {
        $stored = $this->settings->stored();
        $editor = isset($stored['_updated_by']) ? User::query()->find($stored['_updated_by']) : null;

        return view('admin.stripe.edit', [
            'settings' => $this->settings,
            'canEdit' => $this->isSuperAdmin($request),
            'updatedAt' => isset($stored['_updated_at']) ? Carbon::parse($stored['_updated_at']) : null,
            'editor' => $editor,
            'linked' => StripeSettings::linkedCustomers(),
            'prices' => count(array_filter(app(Catalogue::class)->prices(), fn ($r) => $r['current'] && $r['mode'] === $this->settings->mode())),
            'billing' => Plan::billingEnabled(),
            'selfHosted' => Plan::selfHosted(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->requireSuperAdmin($request);

        if ($request->input('action') === 'reset') {
            $this->settings->reset();
            $this->log($request, 'stripe settings reset', []);

            return redirect()->route('admin.stripe.edit')->with('status', __('stripe_settings.reset_done'));
        }

        $data = $request->validate([
            'key' => ['nullable', 'string', 'max:255', 'regex:/^pk_(test|live)_[A-Za-z0-9]+$/'],
            'secret' => ['nullable', 'string', 'max:255', 'regex:/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/'],
            'webhook_secret' => ['nullable', 'string', 'max:255', 'regex:/^whsec_[A-Za-z0-9]+$/'],
            'forget_customers' => ['nullable', 'boolean'],
        ], [
            'key.regex' => __('stripe_settings.errors.key'),
            'secret.regex' => __('stripe_settings.errors.secret'),
            'webhook_secret.regex' => __('stripe_settings.errors.webhook_secret'),
        ]);
        $values = array_map(fn ($v) => $v === null || trim((string) $v) === '' ? null : trim((string) $v), array_intersect_key($data, array_flip(['key', 'secret', 'webhook_secret'])));

        // The two keys come from the same place: the sandbox, or live.
        $key = $values['key'] ?? $this->settings->value('key');
        $secret = $values['secret'] ?? $this->settings->value('secret');
        $mode = StripeSettings::modeOf($secret) ?? StripeSettings::modeOf($key);
        if ($key !== '' && $secret !== '' && StripeSettings::modeOf($key) !== StripeSettings::modeOf($secret)) {
            return back()->withInput($request->only('key'))->withErrors(['key' => __('stripe_settings.errors.modes')]);
        }

        // Customers and subscriptions exist only on one side: switching asks to unlink them.
        $switching = $this->settings->mode() !== null && $mode !== null && $mode !== $this->settings->mode();
        $linked = StripeSettings::linkedCustomers();
        if ($switching && $linked > 0 && ! ($data['forget_customers'] ?? false)) {
            return back()->withInput($request->only('key'))->withErrors(['forget_customers' => trans_choice('stripe_settings.errors.forget', $linked, ['count' => $linked])]);
        }

        $before = $this->summary();
        $forgotten = $switching && $linked > 0 ? StripeSettings::forgetCustomers() : 0;
        $this->settings->save($values, $this->staffUser($request));

        $this->log($request, 'stripe settings changed', [
            'before' => $before,
            'after' => $this->summary(),
            'secret_replaced' => isset($values['secret']),
            'webhook_secret_replaced' => isset($values['webhook_secret']),
            'customers_unlinked' => $forgotten,
        ]);

        return redirect()->route('admin.stripe.edit')->with('status', $forgotten > 0
            ? trans_choice('stripe_settings.saved_unlinked', $forgotten, ['count' => $forgotten])
            : __('stripe_settings.saved'));
    }

    public function webhook(Request $request): RedirectResponse
    {
        $this->requireSuperAdmin($request);
        abort_if($this->settings->value('secret') === '', 404);

        try {
            $id = $this->settings->createWebhook($this->staffUser($request));
        } catch (Throwable $e) {
            return back()->withErrors(['webhook' => __('stripe_settings.errors.stripe', ['error' => $e->getMessage()])]);
        }
        $this->log($request, 'stripe webhook created', ['endpoint' => $id, 'url' => StripeSettings::webhookUrl(), 'mode' => $this->settings->mode()]);

        return redirect()->route('admin.stripe.edit')->with('status', __('stripe_settings.webhook.created'));
    }

    /** @return array<string, mixed> what a log line may say: never a secret, only where it comes from */
    private function summary(): array
    {
        return [
            'mode' => $this->settings->mode(),
            'key' => $this->settings->source('key'),
            'secret' => $this->settings->source('secret'),
            'webhook_secret' => $this->settings->source('webhook_secret'),
        ];
    }

    /** @param  array<string, mixed>  $properties */
    private function log(Request $request, string $description, array $properties): void
    {
        activity('admin')->causedBy($this->staffUser($request))->withProperties($properties)->log($description);
    }
}
