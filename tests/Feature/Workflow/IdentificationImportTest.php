<?php

namespace Tests\Feature\Workflow;

use App\Enums\ProductRole;
use App\Models\ProductIdentifier;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\BuildsProducts;
use Tests\TestCase;

/**
 * "Import from Excel" of the Product identification: two layouts, duplicates skipped, CSV, the
 * card's fields left out, refusals, guests.
 */
class IdentificationImportTest extends TestCase
{
    use BuildsProducts, RefreshDatabase;

    private User $owner;

    private ProductVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->owner();
        $this->version = $this->product($this->owner);
        $this->version->product->models()->create(['kind' => ProductIdentifier::MODEL, 'value' => 'SL-100', 'position' => 0]);
        $this->version->identifiers()->create(['kind' => 'brand', 'value' => 'Acme', 'position' => 0]);
    }

    private function xlsx(array $rows, string $name = 'ids.xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'ids-').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function import(UploadedFile $file, ?User $as = null)
    {
        return $this->signIn(($as ?? $this->owner)->refresh())
            ->post(route('versions.identification.import', $this->version), ['file' => $file]);
    }

    /** @return list<string> "kind:value" of the models then the identifiers */
    private function lines(): array
    {
        $product = $this->version->product->fresh();

        return [
            ...$product->models()->get()->map(fn ($m) => $m->kind.':'.$m->value.($m->notes ? ' ('.$m->notes.')' : ''))->all(),
            ...$this->version->identifiers()->get()->map(fn ($i) => $i->kind.':'.$i->value.($i->notes ? ' ('.$i->notes.')' : ''))->all(),
        ];
    }

    public function test_kind_value_note_columns_are_added_and_duplicates_skipped(): void
    {
        $response = $this->import($this->xlsx([
            ['Kind', 'Value', 'Note'],
            ['Model', 'SL-200', 'EU variant'],
            ['model', 'sl-100', ''],            // already there (case ignored)
            ['Marque', 'ACME', ''],             // French label, already there
            ['Trade name', 'Smart Lock Pro', ''],
            ['Colour', 'Blue', 'shade'],        // unknown kind: other, with the kind as note
            ['HVIN', 'HW-2', ''],               // the card's Product version: left out
            ['', '', ''],
        ]));
        $response->assertRedirect(route('versions.edit', $this->version).'#identification');
        $status = (string) session('status');
        $this->assertStringContainsString(trans_choice('identification.import.added', 3, ['count' => 3]), $status);
        $this->assertStringContainsString(trans_choice('identification.import.existing', 2, ['count' => 2]), $status);
        $this->assertStringContainsString('HVIN', $status);

        $this->assertSame([
            'model:SL-100', 'model:SL-200 (EU variant)',
            'brand:Acme', 'trade_name:Smart Lock Pro', 'other:Blue (Colour · shade)',
        ], $this->lines());
    }

    public function test_one_column_per_kind_and_csv(): void
    {
        $this->import($this->xlsx([
            ['Model', 'Trade name', 'Hardware version', 'Colour'],
            ['SL-300', 'Lock One', 'B2', 'Red'],
            ['SL-301', '', '', ''],
        ]))->assertRedirect();
        $this->assertSame([
            'model:SL-100', 'model:SL-300', 'model:SL-301',
            'brand:Acme', 'trade_name:Lock One', 'other:Red (Colour)',
        ], $this->lines());

        $csv = tempnam(sys_get_temp_dir(), 'ids-').'.csv';
        file_put_contents($csv, "\xEF\xBB\xBFNature;Nom ou identifiant;Remarque\nModèle;SL-400;\nType;Door lock;\nModèle;SL-300;\n");
        $this->import(new UploadedFile($csv, 'ids.csv', null, null, true))->assertRedirect();
        $this->assertContains('model:SL-400', $this->lines());
        $this->assertContains('type:Door lock', $this->lines());
        $this->assertCount(1, array_filter($this->lines(), fn ($l) => $l === 'model:SL-300'));
    }

    public function test_the_template_has_the_kind_dropdown(): void
    {
        $response = $this->signIn($this->owner->refresh())->get(route('versions.identification.template', $this->version))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl-').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $path);
        @unlink($response->baseResponse->getFile()->getPathname());
        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame(__('identification.kind'), $sheet->getCell('A1')->getValue());
        $this->assertStringContainsString(__('identification.kinds.trade_name'), (string) $sheet->getCell('A2')->getDataValidation()->getFormula1());
        $this->assertStringNotContainsString('HVIN', (string) $sheet->getCell('A2')->getDataValidation()->getFormula1());

        // The template itself imports nothing and is the panel's link
        $this->signIn($this->owner->refresh())->get(route('versions.edit', $this->version))->assertOk()
            ->assertSee(route('versions.identification.template', $this->version), false)
            ->assertSee(route('versions.identification.import', $this->version), false);
    }

    public function test_what_is_not_a_spreadsheet_and_who_may_not_import_are_refused(): void
    {
        $this->import(UploadedFile::fake()->createWithContent('ids.pdf', '%PDF-1.4'))->assertSessionHasErrors('file');
        $fake = tempnam(sys_get_temp_dir(), 'ids-');
        file_put_contents($fake, 'not a zip');
        $this->import(new UploadedFile($fake, 'ids.xlsx', null, null, true))->assertSessionHasErrors('identification_file');
        $this->assertSame(['model:SL-100', 'brand:Acme'], $this->lines());

        $reviewer = $this->member($this->version->product, ProductRole::Reviewer);
        $this->import($this->xlsx([['Model'], ['X-1']]), $reviewer)->assertNotFound();

        $this->owner->forceFill(['is_guest' => true, 'guest_expires_at' => now()->addDay()])->save();
        $this->import($this->xlsx([['Model'], ['X-2']]))->assertRedirect(route('guest.claim'));
        $this->signIn($this->owner->refresh())->get(route('versions.identification.template', $this->version))->assertRedirect(route('guest.claim'));
        $this->assertSame(['model:SL-100', 'brand:Acme'], $this->lines());
    }
}
