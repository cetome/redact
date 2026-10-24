<?php

// The four origin marks and their tooltips are word for word CRAted's,
// so that AI is always identified the same way across cetome products.
return [
    'origin' => [
        'ai_generated' => [
            'label' => 'AI Generated',
            'tooltip' => 'Written by the AI and not changed by a person since.',
        ],
        'ai_enhanced' => [
            'label' => 'AI Enhanced',
            'tooltip' => "Improved by the AI at a person's request.",
        ],
        'human_edited' => [
            'label' => 'Human Edited',
            'tooltip' => 'Written by the AI, then changed by a person.',
        ],
        'human' => [
            'label' => 'Human',
            'tooltip' => 'Written by a person.',
        ],
        // Not an AI mark: E.Info text filled in from the inventory (content pack rule), saved by a person.
        'from_inventory' => [
            'label' => 'From inventory',
            'tooltip' => 'Filled in from your inventory and saved by a person without changes.',
            'tooltip_unsaved' => 'Filled in from your inventory. Not saved yet: check it, then save.',
            'tooltip_edited' => 'Filled in from your inventory, then changed by a person.',
        ],
        // Not an AI mark: read exactly from the person's own cetome REDact workbook (no AI).
        'imported' => [
            'label' => 'Imported from your workbook',
            'tooltip' => 'Read from your own cetome REDact workbook, without AI, and not changed since.',
            'tooltip_edited' => 'Imported from your workbook, then changed by a person.',
        ],
        // Not an AI mark: taken from the manufacturer's VEX statement (an imported VEX file).
        'from_vex' => [
            'label' => 'From VEX',
            'tooltip' => 'Taken from the VEX statement of the manufacturer and not changed since.',
            'tooltip_unsaved' => 'Taken from the VEX statement. Not saved yet: check it, then save.',
            'tooltip_edited' => 'Taken from the VEX statement, then changed by a person.',
        ],
    ],
    'provider_label' => 'cetome default',

    // "Uses N AI units." ends every AI tooltip, quietly (never in a toast).
    'units' => [
        'uses' => '{1} Uses :count AI unit.|[2,*] Uses :count AI units.',
    ],

    'features' => [
        'improve_text' => 'Improve text with AI',
        'check' => 'AI check',
        'suggest_path' => 'Suggested path',
        'draft_from_documents' => 'Draft from documentation',
        'suggest_scope' => 'Scoping suggestion',
    ],

    'sources' => 'Sources',

    'improve' => [
        'button' => 'Improve text with AI',
        'working' => 'Improving…',
        'tooltip' => 'Rewrites this text in clearer sentences with the same facts. You see every change before keeping it.',
        'empty' => 'Write the text first, then the AI can improve it.',
        'dialog_title' => 'Improved wording',
        'dialog_intro' => 'Same facts, clearer sentences. Check that nothing was added or lost, then keep it or keep your own words.',
        'proposed' => 'Proposed text',
        'what_changed' => 'What changed',
        'nothing' => 'The AI found nothing to improve: your text already reads well.',
        'keep' => 'Keep my words',
        'accept' => 'Accept',
    ],

    'check' => [
        'button' => 'Check this evaluation',
        'again' => 'Check again',
        'tooltip' => 'Reads the recorded answers, decision node, verdict and justification and points out what looks inconsistent. It changes nothing.',
        'title' => 'AI check',
        'done' => 'The AI has checked the evaluation. Its findings are below the evaluation.',
        'no_findings' => 'The AI found nothing inconsistent.',
        'stale' => 'The evaluation changed since this check.',
        'human_decides' => 'The AI never decides: you weigh these points and keep or change the evaluation yourself.',
        'dismiss' => 'Dismiss',
        'dismiss_tooltip' => 'Hide this AI output. Nothing in the evaluation changes.',
        'severity' => [
            'problem' => 'Problem',
            'warning' => 'Warning',
            'info' => 'Note',
        ],
    ],

    'path' => [
        'button' => 'Suggest a path',
        'again' => 'Suggest again',
        'tooltip' => 'Proposes an answer to each question of the tree, with its reason and source, from the item data and the documents. You choose every answer yourself.',
        'title' => 'Suggested path',
        'done' => 'The AI has suggested answers. They are below the evaluation, for you to check.',
        'human_decides' => 'Suggestions only: choose each answer in the tree yourself. The AI never decides the path or the verdict.',
        'none' => 'The material does not allow the AI to suggest any answer.',
        'suggested' => 'AI suggests: :answer',
        'yours' => 'Your answer: :answer',
        'source' => 'Source: :source',
    ],

    'draft' => [
        'title' => 'Draft from documentation',
        'button' => 'Draft from documentation',
        'help' => 'The AI reads the product documents and proposes the description, the intended use, inventory items, E.Info texts and risks, each with its sources. Nothing is written until you accept it.',
        'tooltip' => 'Reads every document of this version and proposes texts and items for you to accept one by one.',
        'where' => 'The proposals appear on the AI assistance page.',
        'started' => 'The AI is reading the documents. Its proposals will appear on this page.',
        'running' => 'The AI is reading the documents. This page refreshes when the proposals are ready.',
        'done' => '{0} The AI read the documents and found nothing new to propose.|{1} The AI made :count proposal. Accept or reject it below.|[2,*] The AI made :count proposals. Accept or reject them below.',
        'failed' => 'The draft could not be finished. Please try again in a moment.',
        'no_documents' => 'Add at least one document with readable text first.',
        'already_running' => 'A draft is already under way for this version.',
    ],

    'review' => [
        'group_count' => '{0} Nothing to review|{1} 1 group|[2,*] :count groups',
        'title' => 'AI assistance',
        'intro' => 'What the AI proposed for this version. Nothing reaches the assessment until a person accepts it, and an accepted text keeps its AI mark until a person changes it.',
        'open' => 'Open the AI assistance page',
        'pending' => 'Proposals to decide',
        'nothing' => 'No proposal waiting.',
        'nothing_help' => 'Use "Draft from documentation" or "Improve text with AI" next to a text.',
        'current' => 'Current text',
        'accept' => 'Accept',
        'accept_tooltip' => 'Use this text in the assessment.',
        'accept_replace_tooltip' => 'Replace the current text with this one.',
        'accept_all' => 'Accept all',
        'accept_all_tooltip' => 'Use every proposal of this group in the assessment.',
        'reject' => 'Reject',
        'reject_tooltip' => 'Discard this proposal. Nothing changes in the assessment.',
        'accepted' => 'Accepted. The text now carries its AI mark.',
        'rejected' => 'Rejected. Nothing changed.',
        'dismissed' => 'Dismissed. Nothing changed.',
        'accepted_many' => '{0} Nothing was accepted.|{1} :count proposal accepted.|[2,*] :count proposals accepted.',
        'failed_many' => '{1} :count could not be accepted.|[2,*] :count could not be accepted.',
        'decided' => 'Latest decisions',
        'decided_by' => 'by :name on :date',
        'status' => [
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
        ],
        'rules' => 'The AI never gives a verdict and never decides a decision-tree path. A person makes every decision.',
    ],

    'allowance' => [
        'title' => 'AI allowance',
        'day' => ':used of :limit units used in the last 24 hours.',
        'burst' => ':used of :limit units used in the last 10 minutes.',
        'model' => 'AI model: :model.',
    ],

    'quota' => [
        'too_big' => 'This action needs :units AI units and your allowance is :cap at a time. Smaller actions remain available.',
        'day' => 'You have used your AI allowance for today (:limit units per 24 hours). It refills as the day rolls on.',
        'burst' => 'Too many AI requests in a short time (:limit units per 10 minutes). Wait a few minutes and try again.',
        'unchecked' => 'Your AI allowance could not be checked right now. Please try again in a moment.',
    ],

    'unavailable' => [
        'short' => 'AI unavailable',
        'disabled' => 'AI assistance is switched off on this service.',
        'organisation' => 'Your organisation has turned AI assistance off. An organisation admin can turn it back on.',
        'role' => 'Only the product owner, contributors and organisation admins can use AI assistance.',
        'not_draft' => 'AI assistance is available while the version is a draft.',
        'not_configured' => 'AI assistance is not available right now.',
        'guest' => 'AI assistance comes with a paid plan.',
    ],

    'errors' => [
        'unavailable' => 'The AI could not answer right now. Please try again in a moment.',
        'target_not_allowed' => 'The AI cannot propose a value for this field.',
        'target_gone' => 'This field no longer exists.',
        'already_decided' => 'This proposal has already been decided.',
        'not_acceptable' => 'This AI output is advice only: there is nothing to accept.',
        'value_invalid' => 'This proposal cannot be used as it is. Reject it or ask again.',
        'no_tree' => 'There is no decision tree for this requirement.',
    ],

    'groups' => [
        'product' => 'Product: :name',
        'new_item' => 'New :list: :name',
        'new_risk' => 'New risk: :threat',
        'risk' => 'Risk: :threat',
        'gone' => 'A record that no longer exists',
    ],

    'targets' => [
        'product_description' => 'Product description',
        'intended_use' => 'Intended use',
        'justification' => 'Justification of :requirement',
        'risk' => 'Risk :threat · :field',
        'einfo' => 'E.Info :code',
    ],

    'fields' => [
        'name' => 'Name',
        'description' => 'Description',
        'intended_use' => 'Intended use',
        'justification' => 'Justification',
        'text' => 'Text',
        'threat' => 'Threat',
        'risk' => 'Risk',
        'remediation' => 'Remediation',
        'implementation' => 'Implementation',
    ],

    'documents' => [
        'title' => 'Product documents',
        'intro' => 'Datasheets, manuals and architecture documents of this version. They stay private and are read only by "Draft from documentation" and "Suggest a path".',
        'none' => 'No document yet.',
        'count' => '{0} No document (up to :max).|{1} :count document (up to :max).|[2,*] :count documents (up to :max).',
        'manage' => 'Manage documents',
        'add' => 'Add a document',
        'upload' => 'Upload',
        'limits' => 'PDF, Word (.docx), Excel (.xlsx), CSV, text or Markdown, up to :mb MB each and :max per version.',
        'download_tooltip' => 'Download the file as uploaded.',
        'delete_tooltip' => 'Delete this document for good.',
        'confirm_delete' => 'Delete :name for good?',
        'uploaded' => ':name added.',
        'uploaded_no_text' => ':name added, but no text could be read from it (a scanned PDF?). The AI will not be able to use it.',
        'deleted' => ':name deleted.',
        'too_many' => 'A version can hold up to :max documents. Delete one first.',
        'wrong_type' => 'This file is not a PDF, Word (.docx), Excel (.xlsx), CSV, text or Markdown document.',
        'not_saved' => 'The document could not be saved. Please try again.',
        'locked' => 'Create an account to upload',
        'locked_tooltip' => 'Documents can be uploaded once the sandbox is saved as an account',
    ],
];
