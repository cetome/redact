<?php

/*
 * The engine's own SBOM and VEX (CycloneDX 1.6, JSON), in sbom/. We publish what we ask
 * manufacturers to publish.
 *
 *   php scripts/sbom.php            # writes sbom/redact-engine.cdx.json and sbom/redact-engine.vex.cdx.json
 *   php scripts/sbom.php --offline  # SBOM only, no vulnerability look-up
 *
 * What ships, and only that:
 *  - PHP: every production package of composer.lock ("packages", never "packages-dev");
 *  - JavaScript: the libraries bundled into public/build by Vite (BUNDLED below) and the packages
 *    they pull in, from package-lock.json. Build tools (Vite, Tailwind CSS, the Laravel plugin) are
 *    not shipped and not listed.
 *
 * VEX: every component is looked up in OSV (https://osv.dev, free, Packagist and npm), by purl.
 * Each vulnerability found becomes a VEX statement: "in_triage" until a person records the
 * analysis in sbom/vex-analysis.json ({"<vuln id>|<purl>": {"state": "not_affected",
 * "justification": "code_not_reachable", "detail": "..."}}). Nothing is ever marked not affected
 * without that written analysis. No vulnerability found: the VEX says so (an empty list, with the
 * date and source of the look-up).
 */

const BUNDLED = ['@alpinejs/csp', '@laravel/passkeys', 'altcha', 'chart.js'];
const OSV_BATCH = 'https://api.osv.dev/v1/querybatch';
const OSV_VULN = 'https://api.osv.dev/v1/vulns/';

$root = dirname(__DIR__);
$offline = in_array('--offline', $argv, true);
$out = $root.'/sbom';
if (! is_dir($out)) {
    mkdir($out, 0755, true);
}

$json = fn (string $file) => json_decode((string) file_get_contents($root.'/'.$file), true, 512, JSON_THROW_ON_ERROR);
$composerJson = $json('composer.json');
$composerLock = $json('composer.lock');
$packageLock = $json('package-lock.json');
$config = (string) file_get_contents($root.'/config/redact.php');
$release = preg_match("/'release'\s*=>\s*env\('REDACT_RELEASE',\s*'([^']+)'\)/", $config, $m) ? $m[1] : '1.0.0';
$now = gmdate('Y-m-d\TH:i:s\Z');

function purlComposer(string $name, string $version): string
{
    return 'pkg:composer/'.$name.'@'.ltrim($version, 'v');
}

function purlNpm(string $name, string $version): string
{
    return 'pkg:npm/'.str_replace('@', '%40', $name).'@'.$version;
}

/** @param list<string>|string|null $licences */
function licences(array|string|null $licences): array
{
    $ids = array_values(array_filter((array) $licences, 'is_string'));

    return array_map(fn (string $id) => preg_match('/^[A-Za-z0-9.+-]+$/', $id) ? ['license' => ['id' => $id]] : ['license' => ['name' => $id]], $ids);
}

$components = [];
$dependencies = [];

// --- PHP (production only) -------------------------------------------------------------------
$phpNames = [];
foreach ($composerLock['packages'] as $package) {
    $phpNames[strtolower($package['name'])] = purlComposer($package['name'], $package['version']);
}
foreach ($composerLock['packages'] as $package) {
    $ref = $phpNames[strtolower($package['name'])];
    [$group, $name] = array_pad(explode('/', $package['name'], 2), 2, '');
    $component = [
        'type' => 'library',
        'bom-ref' => $ref,
        'group' => $group,
        'name' => $name,
        'version' => ltrim($package['version'], 'v'),
        'purl' => $ref,
    ];
    if ($licences = licences($package['license'] ?? null)) {
        $component['licenses'] = $licences;
    }
    if (! empty($package['dist']['shasum'])) {
        $component['hashes'] = [['alg' => 'SHA-1', 'content' => $package['dist']['shasum']]];
    }
    $references = [];
    if (! empty($package['source']['url'])) {
        $references[] = ['type' => 'vcs', 'url' => $package['source']['url']];
    }
    if (! empty($package['homepage'])) {
        $references[] = ['type' => 'website', 'url' => $package['homepage']];
    }
    if ($references) {
        $component['externalReferences'] = $references;
    }
    $components[$ref] = $component;
    $needs = [];
    foreach (array_keys($package['require'] ?? []) as $required) {
        if (isset($phpNames[strtolower($required)])) {
            $needs[] = $phpNames[strtolower($required)];
        }
    }
    $dependencies[$ref] = $needs;
}

// --- JavaScript bundled into the front end ---------------------------------------------------
$lockPackages = $packageLock['packages'];
$npmRefs = [];
$resolve = function (string $name, string $from) use ($lockPackages): ?string {
    // Node resolution: the nearest node_modules up from the requiring package.
    $base = $from;
    while (true) {
        $candidate = ($base === '' ? '' : $base.'/').'node_modules/'.$name;
        if (isset($lockPackages[$candidate])) {
            return $candidate;
        }
        if ($base === '') {
            return null;
        }
        $cut = strrpos($base, '/node_modules/');
        $base = $cut === false ? '' : substr($base, 0, $cut);
    }
};
$queue = array_map(fn (string $n) => 'node_modules/'.$n, BUNDLED);
$seen = [];
while ($queue) {
    $path = array_shift($queue);
    if (isset($seen[$path]) || ! isset($lockPackages[$path])) {
        continue;
    }
    $seen[$path] = true;
    $entry = $lockPackages[$path];
    $name = substr($path, strrpos($path, 'node_modules/') + strlen('node_modules/'));
    $ref = purlNpm($name, $entry['version']);
    $npmRefs[$path] = $ref;
    $component = ['type' => 'library', 'bom-ref' => $ref];
    if (str_starts_with($name, '@')) {
        [$group, $short] = explode('/', $name, 2);
        $component += ['group' => $group, 'name' => $short];
    } else {
        $component['name'] = $name;
    }
    $component += ['version' => $entry['version'], 'purl' => $ref];
    if ($licences = licences($entry['license'] ?? null)) {
        $component['licenses'] = $licences;
    }
    if (! empty($entry['integrity']) && preg_match('/^sha512-(.+)$/', $entry['integrity'], $h)) {
        $component['hashes'] = [['alg' => 'SHA-512', 'content' => bin2hex(base64_decode($h[1]))]];
    }
    if (! empty($entry['resolved'])) {
        $component['externalReferences'] = [['type' => 'distribution', 'url' => $entry['resolved']]];
    }
    $components[$ref] = $component;
    $children = [];
    // Peer dependencies only when required: optional ones (e.g. React for @laravel/passkeys) are
    // bundled only if the app imports them, and the engine does not.
    $peers = array_filter($entry['peerDependencies'] ?? [], fn ($v, $peer) => ! ($entry['peerDependenciesMeta'][$peer]['optional'] ?? false), ARRAY_FILTER_USE_BOTH);
    foreach (array_keys(($entry['dependencies'] ?? []) + $peers) as $child) {
        if ($childPath = $resolve($child, $path)) {
            $children[] = $childPath;
            $queue[] = $childPath;
        }
    }
    $dependencies[$ref] = $children; // paths for now, refs below
}
foreach ($dependencies as $ref => $needs) {
    if (str_starts_with($ref, 'pkg:npm/')) {
        $dependencies[$ref] = array_values(array_filter(array_map(fn ($p) => $npmRefs[$p] ?? null, $needs)));
    }
}

// --- The engine itself -----------------------------------------------------------------------
$engineRef = 'pkg:github/cetome/redact@'.$release;
$direct = [];
foreach (array_keys($composerJson['require'] ?? []) as $required) {
    if (isset($phpNames[strtolower($required)])) {
        $direct[] = $phpNames[strtolower($required)];
    }
}
foreach (BUNDLED as $name) {
    if (isset($npmRefs['node_modules/'.$name])) {
        $direct[] = $npmRefs['node_modules/'.$name];
    }
}
$engine = [
    'type' => 'application',
    'bom-ref' => $engineRef,
    'supplier' => ['name' => 'cetome', 'url' => ['https://cetome.com']],
    'name' => 'REDact engine',
    'version' => $release,
    'description' => 'RED Assistant for Compliance Toolkit: RED cyber (Delegated Regulation (EU) 2022/30) and EN 18031, the open-source engine.',
    'licenses' => [['license' => ['id' => 'AGPL-3.0-only']]],
    'purl' => $engineRef,
    'externalReferences' => [
        ['type' => 'vcs', 'url' => 'https://github.com/cetome/redact'],
        ['type' => 'website', 'url' => 'https://redact.cetome.com'],
    ],
];

ksort($components);
$deps = [['ref' => $engineRef, 'dependsOn' => array_values(array_unique($direct))]];
ksort($dependencies);
foreach ($dependencies as $ref => $needs) {
    $deps[] = ['ref' => $ref, 'dependsOn' => array_values(array_unique($needs))];
}

$uuid = function (string $seed): string {
    $h = hash('sha256', $seed);

    return sprintf('urn:uuid:%s-%s-5%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), dechex(8 | (hexdec($h[16]) & 3)), substr($h, 17, 3), substr($h, 20, 12));
};
$tool = ['components' => [['type' => 'application', 'name' => 'scripts/sbom.php', 'group' => 'cetome', 'version' => $release]]];

$sbom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.6',
    'serialNumber' => $uuid('sbom|'.$release.'|'.json_encode(array_keys($components))),
    'version' => 1,
    'metadata' => [
        'timestamp' => $now,
        'lifecycles' => [['phase' => 'build']],
        'tools' => $tool,
        'supplier' => ['name' => 'cetome', 'url' => ['https://cetome.com']],
        'component' => $engine,
        'licenses' => [['license' => ['id' => 'CC0-1.0']]],
    ],
    'components' => array_values($components),
    'dependencies' => $deps,
];
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
file_put_contents($out.'/redact-engine.cdx.json', json_encode($sbom, $flags)."\n");
echo 'SBOM: '.count($components).' components ('.count($phpNames).' PHP, '.count($npmRefs)." JavaScript)\n";

if ($offline) {
    exit(0);
}

// --- VEX: OSV look-up by purl -----------------------------------------------------------------
$post = function (string $url, array $body): array {
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\nUser-Agent: REDact-sbom (+https://github.com/cetome/redact)\r\n",
        'content' => json_encode($body), 'timeout' => 60, 'ignore_errors' => true,
    ]]);
    $raw = file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException('OSV unreachable: '.$url);
    }

    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
};
$get = function (string $url): array {
    $raw = file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60, 'header' => "User-Agent: REDact-sbom (+https://github.com/cetome/redact)\r\n"]]));
    if ($raw === false) {
        throw new RuntimeException('OSV unreachable: '.$url);
    }

    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
};

$purls = array_keys($components);
$found = []; // vuln id => list of purls
foreach (array_chunk($purls, 500) as $chunk) {
    $answer = $post(OSV_BATCH, ['queries' => array_map(fn ($p) => ['package' => ['purl' => $p]], $chunk)]);
    foreach ($answer['results'] ?? [] as $i => $result) {
        foreach ($result['vulns'] ?? [] as $vuln) {
            $found[$vuln['id']][] = $chunk[$i];
        }
    }
}
ksort($found);

$analysis = is_file($out.'/vex-analysis.json') ? json_decode((string) file_get_contents($out.'/vex-analysis.json'), true, 512, JSON_THROW_ON_ERROR) : [];
$vulnerabilities = [];
foreach ($found as $id => $affected) {
    $detail = $get(OSV_VULN.rawurlencode($id));
    $aliases = array_values(array_filter($detail['aliases'] ?? [], 'is_string'));
    foreach (array_unique($affected) as $purl) {
        $decision = $analysis[$id.'|'.$purl] ?? null;
        $state = $decision['state'] ?? 'in_triage';
        $entry = [
            'bom-ref' => $id.'|'.$purl,
            'id' => $id,
            'source' => ['name' => 'OSV', 'url' => 'https://osv.dev/vulnerability/'.$id],
            'references' => array_map(fn ($a) => ['id' => $a, 'source' => ['name' => str_starts_with($a, 'CVE-') ? 'NVD' : 'GitHub Advisory Database']], $aliases),
            'description' => (string) ($detail['summary'] ?? ''),
            'published' => $detail['published'] ?? null,
            'updated' => $detail['modified'] ?? null,
            'analysis' => array_filter([
                'state' => $state,
                'justification' => $decision['justification'] ?? null,
                'response' => $decision['response'] ?? null,
                'detail' => $decision['detail'] ?? ($state === 'in_triage' ? 'Found by OSV on '.substr($now, 0, 10).'; analysis pending.' : null),
            ], fn ($v) => $v !== null),
            'affects' => [['ref' => $purl]],
        ];
        $vulnerabilities[] = array_filter($entry, fn ($v) => $v !== null && $v !== []);
    }
}

$vex = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.6',
    'serialNumber' => $uuid('vex|'.$release.'|'.$now),
    'version' => 1,
    'metadata' => [
        'timestamp' => $now,
        'tools' => $tool,
        'supplier' => ['name' => 'cetome', 'url' => ['https://cetome.com']],
        'component' => ['type' => 'application', 'bom-ref' => $engineRef, 'name' => 'REDact engine', 'version' => $release, 'purl' => $engineRef],
        'properties' => [
            ['name' => 'cetome:vex:source', 'value' => 'OSV (https://osv.dev), look-up by purl of every component of sbom/redact-engine.cdx.json'],
            ['name' => 'cetome:vex:result', 'value' => $vulnerabilities === [] ? 'No known vulnerability on '.substr($now, 0, 10) : count($vulnerabilities).' statement(s)'],
        ],
    ],
    'vulnerabilities' => $vulnerabilities,
];
file_put_contents($out.'/redact-engine.vex.cdx.json', json_encode($vex, $flags)."\n");
echo 'VEX: '.count($vulnerabilities).' statement(s), '.count($found)." vulnerability id(s) from OSV\n";
