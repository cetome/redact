<?php

/*
 * Contact form (/contact, App\Http\Controllers\ContactController), its e-mails and the console list.
 * Topics: App\Models\ContactMessage::TOPICS.
 */
return [
    'meta' => [
        'title' => 'Contact the REDact team ◼ cetome',
        'description' => 'Questions about REDact pricing, self-hosting, support or a partnership: write to the cetome team.',
    ],
    'title' => 'Talk to us',
    'lead' => 'Pricing, self-hosting, support or a partnership: tell us what you need.',

    'fields' => [
        'name' => 'Name',
        'email' => 'E-mail',
        'company' => 'Company',
        'topic' => 'Topic',
        'message' => 'Message',
    ],
    'optional' => 'optional',
    'placeholders' => [
        'name' => 'Your name',
        'email' => 'you@company.com',
        'company' => 'Your company',
        'message' => 'Your products, your question…',
    ],
    'choose' => 'Choose a topic',
    'topics' => [
        'pricing' => 'Pricing / Web-based',
        'self-hosted' => 'Self-hosted',
        'expert' => 'Expert help',
        'support' => 'Support',
        'partnership' => 'Partnership',
        'privacy' => 'Privacy / data request',
        'other' => 'Other',
    ],
    'privacy' => 'We use your details only to answer you and keep them :months months.',
    'privacy_link' => 'Privacy policy',
    'submit' => 'Send message',
    'sending' => 'Sending…',

    'next_title' => 'What happens next',
    'next' => 'We reply by e-mail, in English or French.',
    'security' => 'A security issue in REDact? Write to :email.',

    'sent_title' => 'Message sent',
    'sent_text' => 'Thanks. We will reply by e-mail.',
    'another' => 'Send another message',

    'errors' => [
        'throttled' => 'Too many messages from here. Please try again later.',
        'altcha_missing' => 'The security check did not run. Once the box below is ticked, send again.',
        'altcha_failed' => 'The security check failed or expired. Once the box below is ticked, send again.',
        'name_link' => 'Enter your name without a web address.',
        'topic' => 'Choose a topic.',
    ],

    'mail' => [
        // To cetome (always in English).
        'staff_subject' => 'Contact: :topic, :name',
        'language' => 'Language',
        'account' => 'Account',
        'no_account' => 'Not signed in',
        'organisation' => 'Organisation',
        'open_console' => 'Open in the console',
        'staff_footer' => 'Sent from the REDact contact form. Reply to this e-mail to answer the sender.',
    ],

    'admin' => [
        'nav' => 'Contact',
        'title' => 'Contact messages',
        'lead' => 'Sent with the contact form, deleted after :days days.',
        'count' => '{0} No message|{1} :count message|[2,*] :count messages',
        'search_placeholder' => 'Name, e-mail or company',
        'all_topics' => 'All topics',
        'account' => 'Account',
        'status' => 'Status',
        'received' => 'Received',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'queued' => 'Queued',
        'none' => 'No message.',
        'back' => 'Contact messages',
        'reply' => 'Reply',
        'details' => 'Details',
        'browser' => 'Browser',
    ],
];
