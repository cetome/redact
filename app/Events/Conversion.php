<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A step worth counting, raised where it happens (sign-up, a sandbox opened, the demo loaded, an
 * upgrade started or paid, a product created, a message sent). The engine only raises it: what
 * is done with it (a hosted install's audience measurement, for instance) is a listener's choice.
 */
class Conversion
{
    use Dispatchable;

    public const ACCOUNT_CREATED = 'account_created';

    public const SANDBOX_STARTED = 'sandbox_started';

    public const DEMO_LOADED = 'demo_loaded';

    public const UPGRADE_STARTED = 'upgrade_started';

    public const SUBSCRIPTION_STARTED = 'subscription_started';

    public const PRODUCT_CREATED = 'product_created';

    public const CONTACT_SENT = 'contact_sent';

    /**
     * @param  float|null  $revenue  in the shop's currency, when the step has a price (a subscription)
     * @param  array<string, mixed>  $context  anything a listener may want (e.g. 'guest' => true)
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?User $user = null,
        public readonly ?float $revenue = null,
        public readonly array $context = [],
    ) {}
}
