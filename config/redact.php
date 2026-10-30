<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content pack
    |--------------------------------------------------------------------------
    |
    | The method (scoping questions, lists, requirements, decision trees,
    | E.Info catalogue and rules) is NOT part of the engine: it is a content pack
    | (docs/CONTENT-PACK.md). The engine ships packs/demo, an invented demo pack,
    | used by default. (Tests use tests/Fixtures/content-pack.)
    |
    */

    'content_path' => env('REDACT_CONTENT_PATH', 'packs/demo'),

    // Release of the app, stamped in the workbooks it fills ("REDact version" document property).
    'release' => env('REDACT_RELEASE', '1.0.0'),

    // HTTP cron key: GET /cron/<key> runs "schedule:run" (hosts whose cron only calls a URL). Empty = off.
    'cron_key' => env('REDACT_CRON_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    'auth' => [
        // Sign-in links and codes are valid this long (minutes).
        'token_ttl' => 15,
        // Wrong codes allowed per sign-in e-mail before link and code are void.
        'code_attempts' => 5,
        // Absolute session lifetime from sign-in (minutes), whatever the activity.
        'session_absolute' => (int) env('REDACT_SESSION_ABSOLUTE', 600),
        // "Keep me signed in" (ticked at sign-in, App\Auth\Remember): days from that sign-in. 0 hides the box.
        'remember_days' => max(0, (int) env('REDACT_REMEMBER_DAYS', 30)),
        // "Recent sign-in" window for sensitive actions such as adding a passkey (minutes).
        'recent_auth' => 10,
        // Self-service sign-up.
        'signup' => (bool) env('REDACT_SIGNUP', true),
        // Unverified accounts are deleted after this many days.
        'purge_unverified_days' => 7,
        // Version of the terms a new account accepts.
        'terms_version' => '2026-10-23',
        // Minimum response times, so timing never reveals whether an account exists (ms).
        'pad_send_ms' => 1000,
        'pad_code_ms' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Guest sandbox ("Try without an account", App\Guest)
    |--------------------------------------------------------------------------
    |
    | The full workflow and the reports, no AI, no documents, no team. The data
    | lives this many hours after the last activity, then redact:purge-guests
    | deletes it, unless the person creates an account first.
    |
    */

    'guest' => [
        'enabled' => (bool) env('REDACT_GUEST', true),
        'ttl_hours' => 24,
        // guest_expires_at is pushed forward at most this often (minutes), not on every request.
        'touch_minutes' => 10,
        // New sandboxes per IP address (IPv6: per /64) and day.
        'per_ip_per_day' => (int) env('REDACT_GUEST_PER_IP_PER_DAY', 5),
        'max_products' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Plans and billing (App\Billing\Plan)
    |--------------------------------------------------------------------------
    |
    | Every organisation's FIRST product is free forever. Each additional product
    | is paid (Stripe subscription "products", quantity = paid products, see
    | docs/BILLING.md) or granted by cetome staff (admin console overrides).
    | Without Stripe keys billing is off: limits come from here and the staff
    | overrides, and the UI says "Talk to us". Organisations on the `enterprise`
    | plan (custom contract, set by staff) have no product or team limit.
    | Per-organisation overrides (organisations.limits, admin console) win over
    | these numbers. AI periods: once (a one-time trial), day, month, year.
    | Downloads and imports are never limited by a plan. Guest sandboxes keep
    | their own rules (redact.guest).
    |
    */

    'plans' => [
        'free' => [
            'products' => (int) env('REDACT_FREE_PRODUCTS', 1),
            // People invited to the free product besides its owner (any role).
            'team_members' => (int) env('REDACT_FREE_TEAM_MEMBERS', 1),
            // No AI on the free plan (owner, 2026-10-23): AI comes with a paid (or granted) product.
            'ai_units' => (int) env('REDACT_FREE_AI_UNITS', 0),
            'ai_period' => env('REDACT_FREE_AI_PERIOD', 'once'),
            'support' => false,
        ],
        // Each paid (or granted) product: unlimited team, AI units pooled per organisation.
        'paid' => [
            'price_label' => env('REDACT_PRODUCT_PRICE_LABEL', '100 €'),
            'ai_units' => (int) env('REDACT_PAID_AI_UNITS', 500),
            'ai_period' => env('REDACT_PAID_AI_PERIOD', 'month'),
            'support' => true,
        ],
        'enterprise' => [
            'ai_units' => (int) env('REDACT_ENTERPRISE_AI_UNITS', 5000),
            'ai_period' => env('REDACT_ENTERPRISE_AI_PERIOD', 'month'),
            'support' => true,
        ],
    ],

    'billing' => [
        // Recurring price of one additional product (100 EUR / month), created in the Stripe dashboard.
        'price' => env('STRIPE_PRICE_PRODUCT_MONTHLY'),
        // Cashier subscription type of an organisation.
        'subscription' => 'products',
        // "Talk to us" (billing off, enterprise, larger teams). Empty: the REDact contact form (/contact/pricing).
        'contact_url' => env('REDACT_SALES_URL'),
    ],

    // Self-hosted install (App\Billing\Plan::selfHosted): the AI is not limited by the SaaS plans, the
    // install pays its own AI provider (per-person quotas and staff overrides still apply). true/false,
    // or empty for automatic: self-hosted when billing is not configured. The cetome SaaS sets false.
    'self_hosted' => in_array(env('REDACT_SELF_HOSTED'), [null, ''], true)
        ? null : filter_var(env('REDACT_SELF_HOSTED'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),

    /*
    | Contact form (/contact, App\Http\Controllers\ContactController). Messages go to `to` from
    | MAIL_FROM_ADDRESS (no-reply@cetome.com) with the visitor in Reply-To only; nothing is mailed to
    | the visitor. Limits: sends per IP (every attempt counts) and messages per e-mail address.
    */
    'contact' => [
        'to' => env('REDACT_CONTACT_TO', 'info@cetome.com'),
        'per_ip' => (int) env('REDACT_CONTACT_PER_IP', 10),
        'per_ip_minutes' => 15,
        'per_email' => (int) env('REDACT_CONTACT_PER_EMAIL', 3),
        'per_email_minutes' => 60,
    ],

    // Public repository of the open-source engine (/pricing "Source code"). Empty until it is
    // published: the page then says "Coming soon".
    'open_source_url' => env('REDACT_OPEN_SOURCE_URL') ?: 'https://github.com/cetome/redact',

    // Self-hosted proof-of-work captcha on sign-up and on the guest sandbox.
    'altcha' => [
        'hmac_key' => env('ALTCHA_HMAC_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    |
    | Provider and model are never shown to customers: the UI says
    | "cetome default", like CRAted. Units per day / per 10 minutes.
    |
    | Bring your own key and model: provider anthropic | openai | gemini | mistral |
    | openai_compatible (any OpenAI-compatible API or gateway, with
    | REDACT_AI_BASE_URL and REDACT_AI_API_KEY), the model as free text (empty:
    | the provider's suggested default, App\Ai\AiSettings::PROVIDERS), the key
    | in the provider's own variable (ANTHROPIC_API_KEY, OPENAI_API_KEY,
    | GEMINI_API_KEY, MISTRAL_API_KEY). The console's "AI settings" page (super-admin) overrides
    | all of this from the database, the key encrypted at rest.
    |
    */

    'ai' => [
        'enabled' => (bool) env('REDACT_AI_ENABLED', true),
        'provider' => env('REDACT_AI_PROVIDER', 'gemini'),
        'model' => env('REDACT_AI_MODEL', ''),
        // An OpenAI-compatible API (required for openai_compatible), or a gateway in front of any provider.
        'base_url' => env('REDACT_AI_BASE_URL', ''),
        // The key of an OpenAI-compatible API (the other providers read their own variable, config/prism.php).
        'api_key' => env('REDACT_AI_API_KEY', ''),
        'fallback_models' => array_filter(explode(',', (string) env('REDACT_AI_FALLBACK_MODELS', ''))),
        'timeout' => (int) env('REDACT_AI_TIMEOUT', 90),
        'quota' => [
            'per_day' => (int) env('REDACT_AI_UNITS_PER_DAY', 150),
            'per_10_minutes' => (int) env('REDACT_AI_UNITS_PER_10_MIN', 40),
        ],
        'max_document_mb' => 25,
        'max_documents_per_version' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (GDPR: keep only as long as needed)
    |--------------------------------------------------------------------------
    */

    'retention' => [
        'request_logs_days' => (int) env('REDACT_RETENTION_REQUEST_LOGS', 395),
        'activity_log_days' => (int) env('REDACT_RETENTION_ACTIVITY', 730),
        'security_log_days' => (int) env('REDACT_RETENTION_SECURITY', 365),
        // Contact form messages (admin console, Contact).
        'contact_messages_days' => (int) env('REDACT_RETENTION_CONTACT', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Operator alerts (new admin passkey, suspicious sign-ins, ...)
    |--------------------------------------------------------------------------
    */

    'security_contact' => env('REDACT_SECURITY_CONTACT', 'security@example.com'),
    // cetome's PGP key for security reports (the same as cetome.com/security and its security.txt).
    'security_pgp' => [
        'url' => env('REDACT_SECURITY_PGP_URL', 'https://cetome.com/security/cetome-security.asc'),
        'fingerprint' => env('REDACT_SECURITY_PGP_FINGERPRINT', 'BA3F 45B4 928E DF7A 2C1A EE3A 3CB7 7CFA 4CE0 B1E0'),
    ],

    /*
    | Shared hosting has no long-running queue worker: when true, the
    | every-minute scheduler drains the database queue (routes/console.php).
    */
    'queue_via_scheduler' => (bool) env('REDACT_QUEUE_VIA_SCHEDULER', false),

    /*
    | Local development only: `php artisan redact:dev-login` signed sign-in links
    | (screenshots, manual tests). Ignored unless APP_ENV=local.
    */
    'dev_login' => (bool) env('REDACT_DEV_LOGIN', false),

    /*
    | Interface languages (i18n): add one by translating lang/en into lang/<code>, listing it here,
    | and giving it its details below (App\Support\Locales; missing details fall back to English).
    */
    'locales' => [
        'en' => 'English',
        'fr' => 'Français',
    ],

    'locale_details' => [
        'en' => ['ai' => 'British English', 'altcha' => 'en', 'og' => 'en_GB', 'stripe' => 'auto', 'colon' => ': ', 'thousands' => ','],
        'fr' => ['ai' => 'French', 'altcha' => 'fr-fr', 'og' => 'fr_FR', 'stripe' => 'fr', 'colon' => ' : ', 'thousands' => ' '],
    ],

];
