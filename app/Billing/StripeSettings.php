<?php

namespace App\Billing;

use App\Models\AppSetting;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;

/**
 * The Stripe account billing uses: keys and the webhook (the prices: App\Billing\Catalogue).
 *
 * From .env (STRIPE_KEY, STRIPE_SECRET, STRIPE_WEBHOOK_SECRET), overridden by the console's
 * "Stripe" page (app_settings "stripe"; the secret key and the webhook secret encrypted with the
 * app key, never shown back but their last 4 characters). apply() puts the console's values into
 * the config Cashier and App\Billing\Plan read, at each request and queued job
 * (BillingServiceProvider), never at boot: `config:cache` boots the app and would write the
 * decrypted secrets to bootstrap/cache. A singleton, so it can put back the .env values it replaced.
 *
 * Sandbox first, then live: the mode comes from the keys (pk_test_/sk_test_ or *_live_). The
 * webhook and the customers belong to one mode (prices too, kept per mode in the catalogue): new
 * keys of the other mode drop the stored webhook, and the organisations' Stripe customers and
 * subscriptions are unlinked (forgetCustomers(), confirmed on the page), as they do not exist on
 * the other side.
 */
class StripeSettings
{
    public const KEY = 'stripe';

    /** @var array<string, string> what each setting overrides */
    private const CONFIG = [
        'key' => 'cashier.key',
        'secret' => 'cashier.secret',
        'webhook_secret' => 'cashier.webhook.secret',
    ];

    /** @var array<string, mixed> config path => the .env value apply() replaced */
    private array $original = [];

    /** @return array<string, mixed> what the console stored ([] when nothing) */
    public function stored(): array
    {
        return AppSetting::get(self::KEY);
    }

    /** Put the console's values into the config, or back the .env values when there are none. */
    public function apply(): void
    {
        foreach (self::CONFIG as $name => $path) {
            $console = $this->console($name);
            if ($console !== '') {
                if (! array_key_exists($path, $this->original)) {
                    $this->original[$path] = config($path);
                }
                config([$path => $console]);
            } elseif (array_key_exists($path, $this->original)) {
                config([$path => $this->original[$path]]);
                unset($this->original[$path]);
            }
        }
    }

    /** A setting in use: the console's when set, else the .env value. */
    public function value(string $name): string
    {
        $console = $this->console($name);

        return $console !== '' ? $console : $this->env($name);
    }

    /** "console", "env" or null: where a setting in use comes from. */
    public function source(string $name): ?string
    {
        if ($this->console($name) !== '') {
            return 'console';
        }

        return $this->env($name) !== '' ? 'env' : null;
    }

    /** The last 4 characters of a secret in use ("…a1b2"), null when there is none. Never more. */
    public function hint(string $name): ?string
    {
        $value = $this->value($name);

        return $value === '' ? null : '…'.mb_substr($value, -4);
    }

    /** "test" (a sandbox or test mode), "live", or null without keys. */
    public function mode(): ?string
    {
        return self::modeOf($this->value('secret')) ?? self::modeOf($this->value('key'));
    }

    public static function modeOf(string $key): ?string
    {
        return preg_match('/^(?:pk|sk|rk)_(test|live)_/', $key, $m) ? $m[1] : null;
    }

    /** "100 €", "99.50 €", "100 USD". */
    public static function label(int $cents, string $currency): string
    {
        $amount = $cents % 100 === 0 ? (string) intdiv($cents, 100) : number_format($cents / 100, 2, '.', '');

        return $amount.' '.(strtolower($currency) === 'eur' ? '€' : strtoupper($currency));
    }

    /** The webhook address to give Stripe (the same in the sandbox and live). */
    public static function webhookUrl(): string
    {
        return route('cashier.webhook');
    }

    /** @return list<string> the events the webhook listens to */
    public static function webhookEvents(): array
    {
        return array_values((array) config('cashier.webhook.events', []));
    }

    /** Organisations linked to a Stripe customer (made with the keys in use until now). */
    public static function linkedCustomers(): int
    {
        return Organisation::query()->whereNotNull('stripe_id')->count();
    }

    /**
     * Store new keys (and, optionally, a webhook secret pasted from the dashboard). null keeps
     * the stored value. Keys of another mode drop the stored webhook (it belongs to the other mode).
     *
     * @param  array{key?: ?string, secret?: ?string, webhook_secret?: ?string}  $values
     */
    public function save(array $values, User $by): void
    {
        $stored = $this->withoutMeta($this->stored());
        $before = $this->mode();

        foreach (['key', 'secret', 'webhook_secret'] as $name) {
            $value = trim((string) ($values[$name] ?? ''));
            if ($value !== '') {
                $stored[$name] = $name === 'key' ? $value : Crypt::encryptString($value);
            }
        }

        $after = self::modeOf((string) ($values['secret'] ?? '')) ?? self::modeOf((string) ($values['key'] ?? ''));
        if ($before !== null && $after !== null && $after !== $before && ($values['webhook_secret'] ?? null) === null) {
            unset($stored['webhook_secret'], $stored['webhook_id']);
        }

        $this->put($stored, $by);
    }

    /**
     * Create the webhook endpoint at this site's address and keep its signing secret (Stripe
     * gives it only once). Endpoints already at this address are replaced.
     */
    public function createWebhook(User $by): string
    {
        $endpoints = Cashier::stripe()->webhookEndpoints;
        $url = self::webhookUrl();
        foreach ($endpoints->all(['limit' => 100])->data as $endpoint) {
            if ($endpoint->url === $url) {
                $endpoints->delete($endpoint->id);
            }
        }

        $endpoint = $endpoints->create([
            'url' => $url,
            'enabled_events' => self::webhookEvents(),
            'api_version' => Cashier::STRIPE_VERSION,
            'description' => 'REDact',
        ]);

        $stored = $this->withoutMeta($this->stored());
        $this->put(array_merge($stored, ['webhook_id' => $endpoint->id, 'webhook_secret' => Crypt::encryptString((string) $endpoint->secret)]), $by);

        return $endpoint->id;
    }

    /**
     * Remove the organisations' links to Stripe customers and their subscriptions (records of
     * the other mode). Paid products stay, read-only until a new subscription covers them.
     */
    public static function forgetCustomers(): int
    {
        return DB::transaction(function () {
            $subscriptions = DB::table('subscriptions')->pluck('id');
            DB::table('subscription_items')->whereIn('subscription_id', $subscriptions)->delete();
            DB::table('subscriptions')->whereIn('id', $subscriptions)->delete();

            return Organisation::query()->whereNotNull('stripe_id')->update(['stripe_id' => null, 'pm_type' => null, 'pm_last_four' => null, 'trial_ends_at' => null]);
        });
    }

    /** Back to the .env values. */
    public function reset(): void
    {
        AppSetting::forget(self::KEY);
        $this->apply();
    }

    /** The console's value of a setting ("" when none; a secret decrypted). */
    private function console(string $name): string
    {
        $value = trim((string) ($this->stored()[$name] ?? ''));
        if ($value !== '' && in_array($name, ['secret', 'webhook_secret'], true)) {
            try {
                $value = Crypt::decryptString($value);
            } catch (DecryptException) {
                // The app key changed: the stored secret is unusable (the page asks for it again).
                $value = '';
            }
        }

        return $value;
    }

    /** The .env value of a setting (the one apply() replaced, if it did). */
    private function env(string $name): string
    {
        $path = self::CONFIG[$name];

        return trim((string) (array_key_exists($path, $this->original) ? $this->original[$path] : config($path)));
    }

    /** @param  array<string, mixed>  $stored */
    private function put(array $stored, User $by): void
    {
        $stored = array_filter($stored, fn ($v) => $v !== '' && $v !== null);
        $stored === [] ? AppSetting::forget(self::KEY) : AppSetting::put(self::KEY, $stored, $by);
        $this->apply();
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function withoutMeta(array $stored): array
    {
        unset($stored['_updated_at'], $stored['_updated_by']);

        return $stored;
    }
}
