<?php

// Guest sandbox ("Try without an account"). British English, short, friendly,
// no semicolons (cetome house style).
return [

    'name' => 'Guest',
    'organisation' => 'Guest workspace',
    'keep' => 'Keep my work',
    'leave' => 'Leave',
    'leave_confirm' => 'Leave the sandbox? Your work stays until :date, but a sandbox has no sign-in, so you cannot come back to it. To keep it, create an account first.',
    'banner' => 'Guest sandbox: your work is deleted on :date unless you create an account.',
    'banner_label' => 'Guest sandbox',
    'expired' => 'Your sandbox has expired and its work has been deleted. You can start a new one.',
    'product_limit' => 'A sandbox holds one product. Create a free account to assess more.',
    'documents' => 'Uploading product documents needs an account. AI assistance comes with a paid plan.',
    'team' => 'Inviting colleagues and reviewers needs a free account. Your work comes with you.',
    'try_link' => 'Try without an account',
    // "See a demo" (App\Guest\DemoProduct): a finished assessment of a fictitious product in a sandbox.
    'demo' => [
        'button' => 'See a demo',
        'card' => 'A smart bulb, assessed end to end.',
        'loaded' => 'Demo loaded: a fictitious smart bulb, ready to explore.',
        'full' => 'Your sandbox already holds a product. Delete it to load the demo.',
    ],
    // First screen after "Start with this product" on the home page (a flash, once).

    'start' => [
        'title' => 'Try REDact without an account',
        'heading' => 'Try REDact without an account',
        'lead' => 'A private sandbox with the full workflow. Nothing to install, no e-mail address needed.',
        'included' => 'How the sandbox works',
        'full' => 'The full workflow for one product: description, scoping, risks, inventory, decision trees, E.Info and review.',
        'reports' => 'The Word and Excel reports.',
        'no_ai' => 'No AI assistance. It comes with a paid plan.',
        'files' => 'Account needed to download or import your documents (reports, workbooks, product documents, SBOM).',
        'kept' => 'Your work is kept for :hours hours after your last activity, then deleted for good.',
        'keep' => 'Create an account at any time to keep it: your work moves into the account.',
        'browser' => 'The sandbox lives in this browser. If you leave it or stay away too long, you cannot get back in.',
        'submit' => 'Start my sandbox',
        'have_account' => 'Already have an account?',
        'sign_in' => 'Sign in',
        'started' => 'Your sandbox is ready. Start by describing a product.',
        'too_many' => 'Too many sandboxes were started from your network today. Please try again tomorrow or create a free account.',
        // The invisible anti-spam check (sandbox-start.js): solved on page load, then the form goes by itself.
        'opening' => 'Opening…',
        'check' => [
            'verifying' => 'Anti-spam check running in the background…',
            'ready' => 'Anti-spam check done. Ready to start.',
            'preparing' => 'Preparing your sandbox…',
            'failed' => 'The anti-spam check did not work. Press the button to try again.',
        ],
    ],

    'claim' => [
        'title' => 'Keep my work',
        'heading' => 'Keep my work',
        'lead' => 'Create your free account and everything in this sandbox comes with you. No password needed.',
        'existing' => 'Already have a REDact account? Use its address and we add this work to it.',
        'submit' => 'E-mail me a code',
        'sent' => 'Check your inbox for your 6-digit code.',
        'code_heading' => 'Enter your code',
        'code_lead' => 'If :email can be used, we have sent it a 6-digit code. Type it here to keep your work.',
        'code_submit' => 'Keep my work',
        'other_address' => 'Use another address',
        'reserved' => 'Please use a real e-mail address.',
        'converted' => 'Your account is ready and your work is safe.',
        'merged' => 'Welcome back. Your sandbox work is now in your account.',
    ],

    'mail' => [
        'subject' => 'Your code to keep your REDact work',
        'intro' => 'Use this code to keep the work from your REDact sandbox.',
        'existing' => 'If this address already has a REDact account, the work is added to it.',
    ],

    'account_required' => 'Create your free account to download files or bring in your documents. Your sandbox work comes with you.',
];
