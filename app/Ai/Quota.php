<?php

namespace App\Ai;

use App\Billing\Plan;
use App\Models\AiUsage;
use App\Models\Organisation;
use App\Models\ProductVersion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Per-person AI allowance: units per 24 hours and per 10 minutes
 * (config redact.ai.quota), and the organisation's plan allowance (App\Billing\Plan:
 * a one-time trial on the free product, monthly units per paid product). Same model as CRAted:
 *  - check() is the early, friendly answer;
 *  - reserve() writes the ai_usage row BEFORE the model is called, counting and
 *    inserting in one transaction, and fails closed (an allowance that cannot be
 *    checked is not spent and the call does not happen).
 */
class Quota
{
    /** Outcome of a reserved row until the call ends. */
    public const RESERVED = 'reserved';

    public function perDay(): int
    {
        return max(0, (int) config('redact.ai.quota.per_day', 150));
    }

    public function perBurst(): int
    {
        return max(0, (int) config('redact.ai.quota.per_10_minutes', 40));
    }

    /** `null` when the call may go ahead, else the message to show. Never throws. */
    public function check(User $user, int $units): ?string
    {
        try {
            return $this->refusal($user, $units);
        } catch (Throwable $e) {
            report($e);

            return __('ai.quota.unchecked');
        }
    }

    /**
     * Spend the units now: the row is the reservation, so two calls fired together
     * cannot both pass. Throws an AiException (429) with the reason when refused.
     * The scope is the version worked on, or the organisation of a product not created yet.
     */
    public function reserve(User $user, ProductVersion|Organisation $version, Feature $feature, int $units, string $provider, string $model): AiUsage
    {
        try {
            return DB::transaction(function () use ($user, $version, $feature, $units, $provider, $model) {
                // Serialise this person's reservations (a no-op on SQLite, which locks the whole database).
                User::query()->whereKey($user->getKey())->lockForUpdate()->first();

                $organisation = $version instanceof ProductVersion ? $version->product->organisation : $version;
                if ($message = $this->refusal($user, $units) ?? Plan::fresh($organisation)->aiRefusal($units)) {
                    throw AiException::quota($message);
                }

                return AiUsage::create([
                    'user_id' => $user->getKey(),
                    'organisation_id' => $version instanceof ProductVersion ? $version->product->organisation_id : $version->getKey(),
                    'product_version_id' => $version instanceof ProductVersion ? $version->getKey() : null,
                    'feature' => $feature->value,
                    'units' => $units,
                    'provider' => $provider,
                    'model' => mb_substr($model, 0, 80),
                    'outcome' => self::RESERVED,
                ]);
            });
        } catch (AiException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw AiException::quota(__('ai.quota.unchecked'));
        }
    }

    /**
     * The person's allowance, and the organisation's plan allowance when one is given.
     *
     * @return array{used_day: int, per_day: int, used_burst: int, per_burst: int, plan: ?array{units: int, period: string, used: int, left: int, trial: bool, renews: ?CarbonInterface}}
     */
    public function allowance(User $user, ?Organisation $organisation = null): array
    {
        return [
            'used_day' => $this->usedSince($user, now()->subDay()),
            'per_day' => $this->perDay(),
            'used_burst' => $this->usedSince($user, now()->subMinutes(10)),
            'per_burst' => $this->perBurst(),
            'plan' => $organisation ? Plan::for($organisation)->aiAllowance() : null,
        ];
    }

    private function refusal(User $user, int $units): ?string
    {
        $perDay = $this->perDay();
        $perBurst = $this->perBurst();

        if ($units > $perDay || $units > $perBurst) {
            return __('ai.quota.too_big', ['units' => $units, 'cap' => min($perDay, $perBurst)]);
        }
        if ($this->usedSince($user, now()->subDay()) + $units > $perDay) {
            return __('ai.quota.day', ['limit' => $perDay]);
        }
        if ($this->usedSince($user, now()->subMinutes(10)) + $units > $perBurst) {
            return __('ai.quota.burst', ['limit' => $perBurst]);
        }

        return null;
    }

    private function usedSince(User $user, \DateTimeInterface $since): int
    {
        // Refused calls never spent anything; failed ones did (the provider still charged).
        return (int) AiUsage::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', $since)
            ->where('outcome', '!=', 'refused')
            ->sum('units');
    }
}
