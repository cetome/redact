<?php

/*
 * Terms and legal notice (/terms). Publisher and host as stated on cetome.com/legal/ (cetome, France).
 * Placeholders, filled by the view from the config: :hours = sandbox lifetime, :email = security contact
 * (mailto link), :price = price of one paid product, :ai_units = AI units a month per paid product,
 * :free_team = the free product's team ("Owner + 1 team member"), :talk = "talk to us" link (contact form,
 * self-hosted topic, like the pricing page).
 */
return [
    'version' => 'Version 2026-10-23',

    'links' => [
        'pgp' => 'PGP key',
        'talk' => 'talk to us',
    ],

    'notice_title' => 'Legal notice',
    'notice' => [
        'publisher' => ['Publisher', 'cetome, registered as Cédric LÉVY-BENCHETON EI'],
        'siret' => ['SIRET', '849 836 101 00014'],
        'vat' => ['VAT', 'FR85849836101'],
        'office' => ['Registered office', '37 Rue Antoine Charial, 69003 Lyon, France'],
        'director' => ['Director of publication', 'Cédric Lévy-Bencheton'],
        'contact' => ['Contact', 'Contact form'],
        'host' => ['Host', 'IONOS SE, Elgendorfer Str. 57, 56410 Montabaur, Germany (European Union)'],
    ],

    'terms_title' => 'Terms of use',
    'terms' => [
        'scope' => ['Scope', [
            'These terms apply to every use of REDact, with or without an account. They form the contract between you and cetome, the publisher named above.',
        ]],
        'service' => ['What REDact is', [
            'REDact helps you document the assessment of radio equipment against the EU Radio Equipment Directive (RED) for the cyber security essential requirements (articles 3.3 d), e) and f)) with the harmonised standards EN 18031. It is not legal advice. The manufacturer alone is responsible for the conformity of its products, its technical documentation and its declaration of conformity.',
        ]],
        'account' => ['Your account', [
            'Your account is personal: keep your passkeys and mailbox secure. You are responsible for what is done with it. We may suspend an account that breaches these terms or puts the service at risk.',
        ]],
        'sandbox' => ['Trying without an account', [
            'A sandbox keeps your work for :hours hours after your last activity, then deletes it, unless you create an account. It has no AI, no documents and no team. Downloads and uploads need an account.',
        ]],
        'plans' => ['Plans', [
            'Free: your organisation\'s first product, free forever (:free_team), with the cetome rules, downloads and imports. No AI.',
            'Paid: :price per month excluding VAT for 2 products (the free one plus one paid product), then :price per month for each additional product. Paid products include an unlimited team, support and the AI (:ai_units AI units a month per paid product, shared by the organisation).',
            'Enterprise (self-hosted or dedicated, on request): under a separate contract, :talk.',
        ]],
        'billing' => ['Payment and invoices', [
            'Paid plans are billed monthly in advance through Stripe, our payment provider. A product added during a period is charged pro rata on the next invoice. Stripe adds the VAT that applies: EU businesses with a valid VAT number are invoiced under reverse charge. Invoices are on your billing page and in the Stripe customer portal.',
        ]],
        'cancellation' => ['Cancellation and failed payments', [
            'An organisation admin can cancel at any time from the billing page. Cancellation takes effect at the end of the paid period. The current period is not refunded, unless the law requires it.',
            'When a subscription ends, paid products become read-only: nothing is deleted, every download keeps working and the free product stays editable. Reactivating makes them editable again. If a payment fails, Stripe retries it and your products stay editable meanwhile. If it still fails, the subscription ends.',
        ]],
        'prices' => ['Price changes', [
            'We announce price changes by e-mail at least 30 days before they apply. You can cancel before then.',
        ]],
        'consumers' => ['Consumers', [
            'REDact is designed for businesses. If you subscribe as a consumer in the European Union, you may withdraw within 14 days of subscribing, without giving a reason, through the contact form. Before paying, you expressly ask us to start the service at once and acknowledge that you lose this right once the service has been fully performed: if you withdraw within the 14 days, you pay for the period used.',
        ]],
        'ai' => ['AI assistance', [
            'The AI is optional. Its output is always marked as AI and is only a proposal: nothing enters your assessment until a person accepts it. The AI never gives a verdict, and the manufacturer remains responsible for what it accepts. Your organisation can turn the AI off. Paid and Enterprise organisations may use their own model or key: their content then goes to the provider they chose, under their own contract with it.',
        ]],
        'use' => ['Acceptable use', [
            'Do not extract, copy in bulk, scrape or access by automated means the content of REDact (decision trees, assessment criteria, pre-fill texts, threat library, rules), and do not reverse engineer the cetome content pack. Do not get around the rate limits and plan limits, or use REDact to provide a service to third parties or build a competing product, without cetome\'s written permission. We apply rate limits and may suspend access that breaches this section.',
        ]],
        'licence' => ['Open source and intellectual property', [
            'The REDact engine is free software under the GNU Affero General Public License v3.0 (AGPL-3.0), which alone governs its source code. The cetome content pack (rules, decision-tree transcriptions, assessment criteria, threat library, pre-fill texts, report templates, AI instructions) and the hosted service are cetome\'s property and are not covered by that licence. You may use them, and the documents you produce with them, for your organisation\'s own compliance work.',
        ]],
        'data' => ['Your data', [
            'What you enter and upload remains yours. cetome hosts and processes it only to provide the service, as set out in the privacy policy. You can export or delete it.',
        ]],
        'security' => ['Security', [
            'Report vulnerabilities to :email. We reply within 2 business days and take no legal action against good-faith research that respects users\' privacy and the availability of the service.',
            'Encrypt sensitive details with our :pgp (fingerprint :fingerprint).',
        ]],
        'liability' => ['Liability', [
            'The service is provided as is. REDact does not guarantee the conformity of your products, nor the decision of a notified body, a laboratory or a market surveillance authority. To the extent permitted by law, cetome is not liable for indirect damage, loss of business or regulatory sanctions, and its total liability is limited to the fees you paid for REDact in the 12 months before the claim.',
        ]],
        'law' => ['Changes, applicable law and courts', [
            'We will tell you of material changes to these terms in advance. French law applies. Any dispute goes to the courts of Lyon, France, unless the law gives a consumer another court.',
        ]],
    ],
];
