<?php

namespace Tests\Feature;

use App\Content\ContentPack;
use App\Content\Tree;
use App\Enums\ProductRole;
use App\Mail\ProductInvitation;
use App\Models\User;
use App\Support\FileSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/** Every interface text exists in each language, and what the app writes reads in the viewer's language. */
class TranslationsTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    /** @return array<string, mixed> */
    private static function flat(array $lines, string $prefix = ''): array
    {
        $out = [];
        foreach ($lines as $key => $value) {
            $key = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $out += is_array($value) ? self::flat($value, $key) : [$key => $value];
        }

        return $out;
    }

    public function test_every_language_file_has_the_same_keys_as_english(): void
    {
        foreach (array_keys((array) config('redact.locales')) as $locale) {
            foreach (glob(lang_path('en/*.php')) as $file) {
                $name = basename($file);
                $this->assertFileExists(lang_path($locale.'/'.$name), "$locale/$name");
                $missing = array_diff_key(self::flat(require $file), self::flat(require lang_path($locale.'/'.$name)));
                $this->assertSame([], array_keys($missing), "$locale/$name");
            }
        }
    }

    public function test_framework_messages_are_french(): void
    {
        app()->setLocale('fr');
        $this->assertSame('Le champ nom est obligatoire.', __('validation.required', ['attribute' => __('validation.attributes.name')]));
        $this->assertSame('Suivant &raquo;', __('pagination.next'));
        $this->assertSame('France (SIRET : 849 836 101 00014)', __('France (SIRET: 849 836 101 00014)'));
    }

    public function test_sizes_and_tree_answers_follow_the_language(): void
    {
        $this->assertSame('12.1 KB', FileSize::format(12390));
        $this->assertSame('Yes', Tree::answerLabel('YES'));
        app()->setLocale('fr');
        $this->assertSame('12,1 Ko', FileSize::format(12390));
        $this->assertSame('Oui', Tree::answerLabel('YES'));
        $this->assertSame('Non', Tree::answerLabel('No'));
    }

    public function test_a_tree_question_reads_in_the_viewers_language_when_the_pack_has_it(): void
    {
        $tree = new Tree(['standard' => 'demo-1', 'requirement' => 'ACC-1', 'title' => ['en' => 'Access', 'fr' => 'Accès'], 'start' => 'DN-1', 'nodes' => [
            ['id' => 'DN-1', 'kind' => 'decision', 'text' => ['en' => 'Is it reachable?', 'fr' => 'Est-il accessible ?'], 'branches' => [['label' => 'Yes', 'to' => 'R-1'], ['label' => 'No', 'to' => 'R-1']]],
            ['id' => 'R-1', 'kind' => 'result', 'result' => 'PASS'],
        ]]);
        $this->assertSame('Is it reachable?', $tree->node('DN-1')['text']);
        app()->setLocale('fr');
        $this->assertSame('Est-il accessible ?', $tree->node('DN-1')['text']);
        $this->assertSame('Est-il accessible ?', $tree->nodes()['DN-1']['text']);
        $this->assertSame('Accès', $tree->title());
        $this->assertSame('Yes', $tree->node('DN-1')['branches'][0]['label'], 'The stored answer keeps the tree label.');
        app()->setLocale('en');
    }

    public function test_a_risk_level_reads_in_the_viewers_language_until_the_team_renames_it(): void
    {
        config(['redact.content_path' => base_path('packs/demo')]);
        $this->app->forgetInstance(ContentPack::class);
        $version = $this->product($this->owner());

        // Created in English: stored "High", read "Élevé" in French.
        $level = $version->riskLevels()->where('rank', 2)->firstOrFail();
        $this->assertSame('High', $level->getRawOriginal('name'));
        app()->setLocale('fr');
        $this->assertSame('Élevé', $level->fresh()->name);
        $this->assertSame('Élevé', $version->riskLevels()->pluck('name', 'rank')[2]);

        // A name the team wrote stays as written.
        $level->update(['name' => 'Serious']);
        $this->assertSame('Serious', $level->fresh()->name);
    }

    public function test_an_invitation_is_sent_in_the_language_of_the_invited_account(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $version = $this->product($owner);
        User::factory()->create(['email' => 'lea@emotec.example', 'locale' => 'fr']);

        $this->signIn($owner)->post(route('team.invite', $version->product), ['email' => 'lea@emotec.example', 'role' => ProductRole::Contributor->value]);

        Mail::assertQueued(ProductInvitation::class, fn (ProductInvitation $mail) => $mail->locale === 'fr');
    }
}
