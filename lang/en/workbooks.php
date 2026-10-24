<?php

// "Your cetome REDact workbooks" on the reports page: the owner's Excel templates filled with the version.
// British English, no semicolons in UI copy (cetome house style).
return [
    'title' => 'Your cetome REDact workbooks',
    'intro' => 'The cetome REDact Excel templates, filled in with this version: the same sheets, formulas, tables and dropdowns, ready to work on in Excel. They are not protected.',
    'download' => 'Download',
    'download_tooltip' => 'Builds the workbook from this version now',
    'download_all' => 'All four (zip)',
    'download_all_tooltip' => 'The four workbooks and a notes file in one zip',
    'locked' => 'Create an account to download',
    'locked_tooltip' => 'Workbooks can be downloaded once the sandbox is saved as an account',
    'recalculate' => 'Excel recomputes every formula when the workbook opens. Other spreadsheet programs may need a manual recalculation.',
    'fixes_title' => 'Corrections made in the delivered copy',
    'fixes_count' => '{1} :count correction, see it|[2,*] :count corrections, see them',
    'fixes_made' => '{1} :count correction in the delivered copy|[2,*] :count corrections in the delivered copy',
    'fixes_none' => 'No correction in this workbook.',
    'left_title' => 'Left as in the template, for cetome to review',
    'overflow_title' => 'Rows that will not fit',
    'none' => 'No workbook template is available in the content pack.',
    'workbook' => 'Workbook',

    'notes' => [
        'overflow' => ':sheet has room for :max rows: :count more are left out (:names…).',
        'overflow_risks' => 'The risk register has room for :max risks: :count more are left out.',
        'inconclusive' => ':requirement on :item is INCONCLUSIVE in REDact: no verdict is written.',
        'autofill' => ':count E.Info texts belong to columns the workbook fills in itself from the inventory (marked Autofill): they are not copied.',
        'settled' => ':sheet :ref: :reason. Written as met over the workbook’s formula, which only reads the questions.',
        'matrix_kept' => 'The version’s risk matrix does not use five levels: the workbook keeps its own matrix.',
        'refused' => 'Cells holding a formula were left untouched: :cells.',
    ],

    'notes_file' => [
        'name' => 'REDact notes',
        'title' => 'cetome REDact workbooks for :product, filled in by REDact',
        'applied' => 'Corrected in this copy:',
        'skipped' => 'Not corrected (the template differs from the expected one):',
        'left' => 'Left as in the templates, for cetome to review:',
        'recalculate' => 'Excel recomputes every formula when a workbook opens. The workbooks are not protected.',
    ],

    'overflow' => ':list: :count of :max rows',
];
