<?php

/*
 * Privacy policy (/privacy). Not privacy.php: a __('Privacy') somewhere would load it on Windows.
 * Placeholders filled by the view from the config: :notice and :contact (links, the contact form on
 * its "Privacy / data request" topic),
 * :hours (sandbox), :unverified_days, :requests_months, :activity_months, :security_months,
 * :contact_months (config redact.retention), :ai_provider and :ai_training (the AI provider in use,
 * see 'providers' and 'training').
 */
return [
    'version' => 'Version 2026-10-24',

    'links' => [
        'notice' => 'legal notice',
        'contact' => 'contact form',
    ],

    // App\Ai\AiSettings providers, as named in the subprocessor list.
    'providers' => [
        'gemini' => 'Google LLC / Google Ireland (Gemini API, paid tier)',
        'anthropic' => 'Anthropic (Claude API)',
        'openai' => 'OpenAI (API)',
        'mistral' => 'Mistral AI (API)',
        'openai_compatible' => 'an OpenAI-compatible AI provider chosen by cetome',
    ],

    // What the provider's terms say about training, when cetome has checked them (empty otherwise).
    'training' => [
        'gemini' => 'On this paid tier, Google does not use prompts and responses to train its models (Gemini API terms for paid services).',
    ],

    'sections' => [
        'controller' => ['Controller', [
            'cetome (see the :notice). Contact: the :contact or the postal address in the legal notice.',
        ]],
        'data' => ['What we process and why', [], [
            'account' => ['Account', 'Name, e-mail address, organisation, language, your product news choice, the date and version of the terms you accepted, your sessions and sign-ins. You sign in with a passkey (we keep only its public key, never your biometrics) or with an e-mail link or code: there is no password. Purpose: provide and secure the service. Basis: contract. Kept while the account exists. An address never confirmed is deleted after :unverified_days days.'],
            'content' => ['Products and documents', 'What your team enters (product descriptions, inventory, assessments, components) and uploads (documents, pictures, bills of materials). Purpose: provide the service. Basis: contract. We act as processor for any personal data they contain. Kept until you delete them.'],
            'history' => ['Activity history', 'Who changed what and when in your products and organisation, shown to your team. Purpose: audit trail and security. Basis: contract and legitimate interest. Kept :activity_months months, security events (sign-ins, passkeys) :security_months months.'],
            'logs' => ['Request logs', 'Pages visited, response status and time, language, browser type and a keyed hash of the IP address (never the raw address). Purpose: security, support and aggregated usage statistics. Basis: legitimate interest. Kept :requests_months months.'],
            'sandbox' => ['Sandbox', 'Work done without an account is deleted :hours hours after the last activity, unless you create an account.'],
            'payment' => ['Payment', 'For paid plans, Stripe collects the billing name and address, VAT number and payment method on its own pages: card numbers never reach REDact. We keep the Stripe customer reference, the card type and its last 4 digits, and the subscription. Invoices are shown from Stripe. Before each payment we record the consent to start the service at once (date, person, terms version, keyed hash of the IP address), kept as proof while the organisation exists. Basis: contract and accounting obligations.'],
            'ai' => ['AI processing', 'Only when a person in your team uses an AI feature, the relevant product data and document extracts are sent to the AI provider of the service, :ai_provider, which processes them on our behalf to produce the proposal. :ai_training Guests never use the AI and your organisation can turn it off. Paid and Enterprise organisations may use their own model or key: their content then goes to the provider they chose, under their own contract with it.'],
            'vulnerabilities' => ['Known-vulnerability lookups', 'Only component identifiers (name, version, supplier, package URL, CPE, CVE identifiers) are sent to ENISA\'s EUVD, NIST\'s NVD and OSV.dev. The CISA catalogue of known exploited vulnerabilities is downloaded as a whole. Never a product, organisation or person name.'],
            'emails' => ['E-mails', 'Sign-in links and codes, invitations, review notifications, and product news only if you opted in. Sent through IONOS.'],
            'contact' => ['Contact form', 'Your name, e-mail address, company, topic and message, with a keyed hash of the IP address and the browser type. Purpose: answer you. Basis: legitimate interest (pre-contractual steps when you ask about an offer). Kept :contact_months months.'],
        ]],
        'sharing' => ['Who can see your data', [
            'The people of your product teams, according to their roles, and your organisation admins. cetome staff may open a product read-only to provide support or investigate abuse or security incidents: every such access is recorded and shown in the product and organisation history. We do not sell data and use no advertising or third-party trackers.',
        ]],
        'processors' => ['Subprocessors', [], [
            'ionos' => ['IONOS SE, Germany', 'Hosting and e-mail delivery, in the European Union'],
            'stripe' => ['Stripe', 'Payments and invoices (paid plans)'],
            'ai' => [':ai_provider', 'AI proposals, only when a person uses an AI feature'],
        ]],
        'where' => ['Where', [
            'Hosting in the European Union. Stripe and the AI provider may process data outside the EU under the European Commission\'s standard contractual clauses.',
        ]],
        'cookies' => ['Cookies', [
            'Only strictly necessary cookies: the session cookie, a security cookie against cross-site request forgery, for one hour, a cookie that ties a sign-in code to your browser and, only if you tick “Keep me signed in”, a cookie that keeps you signed in for :remember_days days. The colour theme is stored in your browser. No consent banner is needed.',
        ]],
        'rights' => ['Your rights', [
            'Access, rectification, erasure, restriction, objection and portability. You can export your data and delete your account from your account page, or write to us through the :contact or at the address in the legal notice. You may complain to the CNIL.',
        ]],
    ],
];
