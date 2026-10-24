<?php

// Free templates (/templates): the original cetome REDact workbooks, with the owner's wording from
// redact.cetome.com (shortened only where it repeated itself). Few words, "RED cyber".

return [
    'nav' => 'Templates',
    'meta' => [
        'title' => 'Free RED cyber templates for EN 18031 (Excel) ◼ REDact',
        'description' => 'The original cetome REDact workbooks, free and without an account: scoping questionnaire, risk assessment, decision trees (E.DT, E.Just), E.Info and REDux.',
    ],
    'kicker' => 'cetome REDact',
    'title' => 'Free templates',
    'lead' => 'IoT manufacturers can use this free toolkit to complete a risk assessment, EN 18031 decision trees and E.Info templates.',

    // Sections of the old page: the workbooks of each, in this order (others follow in "more").
    'groups' => [
        'pre' => ['kicker' => '01 — Pre-compliance', 'title' => 'Evaluate your product scope for RED cyber.'],
        'en18031' => ['kicker' => '02 — EN 18031 templates', 'title' => 'Accelerate compliance with RED cyber.'],
        'redux' => ['kicker' => '03 — REDux', 'title' => 'The REDact unified experience.'],
        'more' => ['kicker' => 'More', 'title' => 'More templates.'],
    ],

    'files' => [
        'scoping' => [
            'name' => 'Scoping questionnaire',
            'text' => 'Determine your product scope and identify which articles 3.3 d / e / f apply.',
            'alt' => 'Scoping questionnaire: capabilities of the radio equipment ticked, articles in scope 3.3 d and e.',
        ],
        'risk' => [
            'name' => 'Risk assessment',
            'text' => 'Identify threats and risks and map them to the security mechanisms of EN 18031.',
            'alt' => 'Risk assessment sheet: a threat with its STRIDE categories, risk level, articles and EN 18031 remediation.',
        ],
        'edt' => [
            'name' => 'Decision trees',
            'codes' => ['E.DT', 'E.Just'],
            'text' => 'Complete and verify decision trees and evidence justification.',
            'alt' => 'Decision tree sheet: decision nodes per requirement with PASS, FAIL and N/A columns.',
        ],
        'einfo' => [
            'name' => 'Evidence information',
            'codes' => ['E.Info'],
            'text' => 'Automation speeds up the completion of E.Info.',
            'alt' => 'E.Info sheet: assets with their type, decision node and the E.Info fields filled automatically.',
        ],
        'redux' => [
            'name' => 'Unified template for complex products',
            'codes' => ['E.DT', 'E.Just', 'E.Info'],
            'text' => 'E.DT, E.Just and E.Info in a single file. Complete your product information once, reuse it across your E.Info.',
            'alt' => 'REDux summary: RED self-assessment is possible, with the checks on EN 18031, passwords and protection of children.',
        ],
    ],
    'version' => 'v:version',
    'size' => ':size KB',
    'xlsx' => 'Excel',
    'zip' => 'Zip',
    'download' => 'Download',
    'download_label' => 'Download :name',
    'all' => 'Download all',
    'all_label' => 'Download all the workbooks in one zip',
    'app' => 'Prefer the app? Start here',
    'none' => 'No template is offered on this installation.',

    // Console, Usage page.
    'admin' => [
        'title' => 'Template downloads',
        'help' => 'Free workbooks downloaded from /templates',
        'file' => 'Template',
        'downloads' => 'Downloads',
        'all' => 'All (zip)',
    ],
];
