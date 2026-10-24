<?php

namespace Database\Seeders;

use App\Assessment\Assessment;
use App\Assessment\AssessmentCases;
use App\Assessment\VersionFactory;
use App\Content\ContentPack;
use App\Content\Text;
use App\Enums\Verdict;
use App\Enums\VersionStatus;
use App\Models\Evaluation;
use App\Models\Organisation;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use RuntimeException;

/**
 * LOCAL USE ONLY: realistic data for demo@cetome.com so the screens are not empty.
 *
 *   php artisan db:seed --class=DemoSeeder
 *
 * Everything pack-specific (lists, fields, options, trees, threats) is read from the content pack in
 * use at run time: nothing from the pack is written in this file. Re-running replaces the demo products.
 */
class DemoSeeder extends Seeder
{
    /** Object names per list key, for a smart lock. Lists the pack does not have are skipped. */
    private const NAMES = [
        'assets' => ['Door unlock credentials', 'Wi-Fi password', 'Firmware image', 'User PIN codes', 'Access history', 'Cloud API token', 'Lock configuration', 'Owner e-mail address'],
        'interfaces' => ['Bluetooth LE', 'Wi-Fi', 'Keypad', 'Mobile app', 'Cloud API', 'Debug UART'],
        'entities' => ['Owner', 'Guest user', 'Installer', 'Cloud service'],
        'software' => ['Main firmware', 'Bootloader', 'BLE stack', 'Secure element'],
        'vulnerabilities' => ['Weak pairing PIN', 'Outdated TLS library'],
        'event_logs' => ['Unlock events', 'Failed PIN attempts'],
        'crypto_schemes' => ['BLE session encryption', 'Firmware signature'],
    ];

    public function run(): void
    {
        if (! App::environment('local', 'testing')) {
            throw new RuntimeException('DemoSeeder is for local use only.');
        }

        $user = User::where('email', 'demo@cetome.com')->firstOrFail();
        $organisation = $user->organisations()->first() ?? tap(Organisation::create(['name' => 'Emotec', 'created_by' => $user->id]),
            fn (Organisation $o) => $o->members()->attach($user->id, ['role' => 'admin']));

        Product::where('owner_id', $user->id)->whereIn('name', ['Smart lock', 'Baby monitor', 'Wi-Fi smart plug'])->get()
            ->each(fn (Product $p) => $p->versions()->each(fn (ProductVersion $v) => $v->delete()) && $p->delete());

        mt_srand(18031);
        $this->product($user, $organisation, 'Smart lock', 'SL-200', 'A battery-powered smart door lock opened with a keypad, a mobile app over Bluetooth LE, or remotely through the cloud over Wi-Fi.', [
            ['1.0', VersionStatus::Approved, 1.0],
            ['2.0', VersionStatus::Draft, 0.55],
        ]);
        $this->product($user, $organisation, 'Wi-Fi smart plug', 'SP-10', 'A smart plug switched from a mobile app and voice assistants.', [
            ['1.2', VersionStatus::InReview, 0.95],
        ]);
        $this->product($user, $organisation, 'Baby monitor', 'BM-3', 'A camera and microphone that stream to the parents\' phones.', [
            ['0.9', VersionStatus::Draft, 0.15],
        ]);
    }

    /** @param list<array{0: string, 1: VersionStatus, 2: float}> $versions */
    protected function product(User $user, Organisation $organisation, string $name, string $reference, string $description, array $versions): void
    {
        $product = Product::create([
            'organisation_id' => $organisation->id,
            'owner_id' => $user->id,
            'name' => $name,
            'reference' => $reference,
            'description' => $description,
        ]);

        $previous = null;
        foreach ($versions as [$label, $status, $progress]) {
            $version = app(VersionFactory::class)->create($product, $label, $user);
            $version->forceFill(['previous_version_id' => $previous?->id])->save();
            $this->fill($version, $user, $progress);
            $version->forceFill(['status' => $status, 'intended_use' => 'Home use by adults. Installed by the owner or a professional installer.'])->save();
            $previous = $version;
        }
    }

    private function fill(ProductVersion $version, User $user, float $progress): void
    {
        $pack = app(ContentPack::class);

        // Inventory: a share of each list, attributes from the pack's own options.
        $items = [];
        foreach ($pack->lists() as $key => $list) {
            $names = self::NAMES[$key] ?? [];
            $count = max($progress < 0.3 ? 1 : 0, (int) ceil(count($names) * min(1, $progress + 0.3)));
            foreach (array_slice($names, 0, $count) as $i => $itemName) {
                $items[$key][] = $version->inventoryItems()->create([
                    'list' => $key,
                    'code' => ($list['code_prefix'] ?? strtoupper(substr($key, 0, 2)).'-').str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                    'name' => $itemName,
                    'description' => null,
                    'attributes' => $this->attributes($list['fields'] ?? []),
                    'position' => $i + 1,
                    'updated_by' => $user->id,
                ]);
            }
        }
        foreach ($pack->relations() as $key => $relation) {
            foreach ($items[$relation['from']] ?? [] as $from) {
                foreach ($items[$relation['to']] ?? [] as $to) {
                    if (mt_rand(0, 2) === 0) {
                        $version->inventoryLinks()->create(['relation' => $key, 'from_item_id' => $from->id, 'to_item_id' => $to->id]);
                    }
                }
            }
        }

        // Scoping: answer the questions (most of them yes).
        if ($progress >= 0.3) {
            foreach ($pack->scoping()['sections'] ?? [] as $section) {
                foreach ($section['groups'] ?? [] as $group) {
                    foreach ($group['questions'] ?? [] as $question) {
                        $version->scopingAnswers()->create([
                            'question' => $question['key'],
                            'value' => $this->answer($question),
                            'updated_by' => $user->id,
                        ]);
                    }
                }
            }
        }

        // Risks: the pack's library with its suggested ratings, then a custom one.
        $threats = $pack->risk()['threats'] ?? [];
        $levels = $version->riskLevels()->pluck('rank')->all();
        foreach (array_slice($threats, 0, (int) ceil(count($threats) * $progress)) as $i => $threat) {
            $version->riskEntries()->create([
                'position' => $i + 1,
                'threat' => Text::of($threat['threat']),
                'description' => Text::of($threat['description'] ?? ''),
                'risk' => Text::of($threat['risk'] ?? ''),
                'stride' => $threat['stride'] ?? [],
                'articles' => array_values(array_intersect($threat['articles'] ?? [], array_keys($pack->articles()))),
                'requirements' => $threat['requirements'] ?? [],
                'impact' => $threat['impact'] ?? mt_rand(1, 5),
                'likelihood' => $threat['likelihood'] ?? mt_rand(1, 5),
                'residual_rank' => $threat['residual_rank'] ?? ($levels ? $levels[array_rand($levels)] : null),
                'remediation' => Text::of($threat['remediation'] ?? ''),
                'updated_by' => $user->id,
            ]);
        }

        // Decision trees: walk a share of the trees, always taking the first branch.
        $assessment = Assessment::for($version->refresh());
        foreach (array_keys($pack->standards()) as $standard) {
            foreach ($assessment->applicableRequirements($standard) as $code => $requirement) {
                $tree = $pack->tree($standard, $code);
                if (! $tree) {
                    continue;
                }
                foreach ($assessment->targets($standard, $code) as $item) {
                    if (mt_rand(0, 99) >= (int) ($progress * 100)) {
                        continue;
                    }
                    $answers = $this->walk($tree->toArray());
                    $walk = $tree->walk($answers);
                    Evaluation::create([
                        'product_version_id' => $version->id,
                        'standard' => $standard,
                        'requirement' => $code,
                        'inventory_item_id' => $item?->id,
                        'decision_node' => $walk['last_decision'],
                        'path' => $walk['path'],
                        'verdict' => $walk['verdict'],
                        'justification' => mt_rand(0, 4) ? 'Checked against the design documentation and the test report.' : null,
                        'updated_by' => $user->id,
                    ]);
                }
            }
        }

        // Assessment units: a share of them provided, with their evidence.
        foreach (AssessmentCases::for(Assessment::for($version->refresh()))->units() as $unit) {
            if (mt_rand(0, 99) < (int) ($progress * 100)) {
                $version->caseResults()->create([
                    'standard' => $unit['standard'], 'requirement' => $unit['requirement'], 'case_key' => $unit['case'],
                    'verdict' => Verdict::Pass, 'evidence' => 'Examined on the bench against the design documentation: as documented.',
                    'recorded_by' => $user->id, 'independent' => false,
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function attributes(array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $options = array_column($field['options'] ?? [], 'value');
            $values[$field['key']] = match ($field['type']) {
                'select' => $options ? $options[mt_rand(0, count($options) - 1)] : null,
                'multiselect' => $options ? [$options[mt_rand(0, count($options) - 1)]] : [],
                'boolean' => mt_rand(0, 1) === 1,
                'yesno' => ['yes', 'no', 'na'][mt_rand(0, 2)],
                'text' => mt_rand(0, 1) ? 'v'.mt_rand(1, 4).'.'.mt_rand(0, 9) : null,
                'textarea' => null,
                default => null,
            };
        }

        return array_filter($values, fn ($v) => $v !== null);
    }

    /** @param array<string, mixed> $question */
    private function answer(array $question): mixed
    {
        $options = array_column($question['options'] ?? [], 'value');

        return match ($question['type']) {
            'boolean' => mt_rand(0, 3) > 0,
            'select' => $options[0] ?? null,
            'multiselect' => array_slice($options, 0, 1),
            default => 'Yes',
        };
    }

    /**
     * Answers from the start to a result, taking a random branch at each decision.
     *
     * @param  array<string, mixed>  $tree
     * @return list<string>
     */
    private function walk(array $tree): array
    {
        $nodes = collect($tree['nodes'] ?? [])->keyBy('id');
        $current = $tree['start'] ?? null;
        $answers = [];
        for ($guard = 0; $current && $guard < 30; $guard++) {
            $node = $nodes[$current] ?? null;
            if (! $node || $node['kind'] !== 'decision' || empty($node['branches'])) {
                break;
            }
            $branch = $node['branches'][mt_rand(0, count($node['branches']) - 1)];
            $answers[] = $branch['label'];
            $current = $branch['to'];
        }

        return $answers;
    }
}
