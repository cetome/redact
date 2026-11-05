<?php

// Console: Stripe (App\Http\Controllers\Admin\StripeSettingsController). Keys and webhook, sandbox then live.
return [
    'nav' => 'Stripe',
    'title' => 'Stripe',
    'lead' => 'The Stripe account billing uses. Set it up in a sandbox first, then do the same with the live keys. Settings saved here override the .env values.',
    'read_only' => 'Only a super-admin can change these settings.',

    'status' => [
        'title' => 'In use now',
        'state' => 'Billing',
        'ready' => 'On',
        'not_ready' => 'Off',
        'not_ready_help' => 'Keys and a price needed',
        'mode' => 'Mode',
        'mode_test' => 'Sandbox (test)',
        'mode_live' => 'Live',
        'mode_none' => 'No keys',
        'key' => 'Publishable key',
        'secret' => 'Secret key',
        'webhook_secret' => 'Webhook secret',
        'hint_console' => ':hint, saved here',
        'hint_env' => ':hint, from :variable',
        'none' => 'None',
        'prices' => 'Prices',
        'prices_count' => '{0} None in this mode yet|{1} 1 price in this mode|[2,*] :count prices in this mode',
        'customers' => 'Stripe customers',
        'plans' => 'Plans',
        'plans_self' => 'Self-hosted, no limits',
        'plans_saas' => 'Free, then paid products',
        'changed' => 'Last change',
        'changed_by' => ':date by :name',
    ],

    'keys' => [
        'title' => '1. Keys',
        'help' => 'In Stripe, Developers, API keys. Sandbox keys start with pk_test_ and sk_test_, live keys with pk_live_ and sk_live_.',
        'key' => 'Publishable key',
        'secret' => 'Secret key',
        'secret_help' => 'A secret key, or a restricted key (rk_) with write access to Customers, Checkout Sessions, Subscriptions, Billing Portal, Products, Prices and Webhook Endpoints. Stored encrypted, never shown again. Leave empty to keep the saved key.',
        'saved' => 'Saved key :hint',
        'webhook_secret' => 'Webhook signing secret (optional)',
        'webhook_secret_help' => 'Only for an endpoint made in the Stripe dashboard (whsec_…). Step 3 fills it in for you.',
        'forget' => '{1} I am switching between sandbox and live: forget the Stripe customer and subscription of the organisation that has one|[2,*] I am switching between sandbox and live: forget the Stripe customers and subscriptions of the :count organisations that have one',
        'forget_help' => 'Only when you change from sandbox keys to live keys, or back. A sandbox customer does not exist in live (and the other way round), so REDact forgets it. The organisations, people and products stay. Their paid products become read-only until they subscribe again. Not needed to save new keys of the same mode.',
        'save' => 'Save',
        'reset' => 'Back to the .env values',
        'reset_confirm' => 'Remove the Stripe settings saved here and use the .env values again?',
    ],

    'plans' => [
        'title' => '3. Plans and prices',
        'help' => 'The prices of each plan, per month and per year, and of each additional product: created in Stripe from the Plans and prices page, in the mode of these keys.',
        'open' => 'Plans and prices',
    ],

    'webhook' => [
        'title' => '2. Webhook',
        'help' => 'Stripe tells REDact about payments and subscriptions at this address. It is the same in the sandbox and live. Each mode has its own endpoint and signing secret.',
        'url' => 'Endpoint',
        'events' => 'Events',
        'create' => 'Create the endpoint in Stripe',
        'recreate' => 'Create the endpoint again',
        'create_confirm' => 'Create the endpoint in Stripe? An endpoint already at this address is replaced.',
        'created' => 'Endpoint created in Stripe, its signing secret is saved.',
        'needs_keys' => 'Save the secret key first.',
        'public' => 'Stripe only accepts a public https address.',
    ],

    'saved' => 'Stripe settings saved.',
    'saved_unlinked' => '{1} Stripe settings saved. 1 organisation was unlinked from its Stripe customer.|[2,*] Stripe settings saved. :count organisations were unlinked from their Stripe customer.',
    'reset_done' => 'Stripe settings removed: the .env values apply.',

    'errors' => [
        'key' => 'A publishable key starts with pk_test_ or pk_live_.',
        'secret' => 'A secret key starts with sk_test_, sk_live_, rk_test_ or rk_live_.',
        'webhook_secret' => 'A signing secret starts with whsec_.',
        'modes' => 'Both keys must come from the same place: the sandbox, or live.',
        'forget' => '{1} These keys are of the other mode (sandbox or live): tick the box to forget the Stripe customer of the organisation that has one.|[2,*] These keys are of the other mode (sandbox or live): tick the box to forget the Stripe customers of the :count organisations that have one.',
        'stripe' => 'Stripe refused: :error',
    ],
];
