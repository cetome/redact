<?php

/*
|--------------------------------------------------------------------------
| Bills of materials and known vulnerabilities (App\Sbom, App\Vulnerabilities)
|--------------------------------------------------------------------------
|
| Import SBOM / HBOM files into the component list, then look the components up
| in public vulnerability databases. Only component identifiers (name, version,
| supplier, purl, CPE, CVE ids) ever leave the server: never a product, customer
| or person. See docs/VULNERABILITY-SOURCES.md for the terms of each source.
|
*/

return [

    // Content-pack keys the feature works on (it hides itself if the pack lacks them).
    'lists' => [
        'components' => 'software',
        'vulnerabilities' => 'vulnerabilities',
        'relation' => 'vulnerability_component',
    ],

    'import' => [
        'max_kb' => 10 * 1024,          // one uploaded file
        'max_files' => 50,              // SWID tags: one per software, several files or a zip
        'max_components' => 5000,
        'max_statements' => 5000,       // VEX statements of one upload
        'max_unzipped_mb' => 50,        // zip (and xlsx) bomb guard: total uncompressed size
        'max_zip_ratio' => 200,         // ... and compression ratio of any entry
        'json_depth' => 64,
    ],

    'checks' => [
        // Calls to the public databases (off: import still works, the check says it is unavailable).
        'enabled' => (bool) env('REDACT_VULN_CHECKS', true),
        // Look a component up again when it is created or its name, version, purl or CPE change.
        'auto' => (bool) env('REDACT_VULN_AUTO', true),
        // Re-check every version in work once a week (Monday 04:00, redact:recheck-vulnerabilities).
        'weekly' => (bool) env('REDACT_VULN_WEEKLY', false),
        // Wait this long after the last change before looking it up (debounce, seconds).
        'auto_delay' => 60,
        // A job stops after this many seconds and queues itself again for the rest
        // (shared hosting: the scheduler's queue:work runs at most 50 s).
        'time_budget' => 35,
        'user_agent' => env('REDACT_VULN_USER_AGENT', 'REDact/1.0 (+https://redact.example.com; security@example.com)'),
        'timeout' => 20,
        // EUVD look-ups by CVE (EUVD id, EU exploited flag) per check, the rest stays without.
        'max_euvd_lookups' => 150,
        'cache_days' => 1,
    ],

    'sources' => [
        'osv' => ['url' => 'https://api.osv.dev'],
        'nvd' => [
            'url' => 'https://services.nvd.nist.gov/rest/json/cves/2.0',
            'key' => env('NVD_API_KEY'),
            'timeout' => 45,
            // Requests per rolling 30 seconds: 5 without a key, 50 with one (NVD terms).
            'per_30s' => env('NVD_API_KEY') ? 45 : 5,
        ],
        'euvd' => ['url' => 'https://euvdservices.enisa.europa.eu/api'],
        'kev' => ['url' => 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json'],
    ],

];
