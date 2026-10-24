<?php

namespace Tests\Feature\Ai;

use App\Documents\DocumentStore;
use App\Documents\TextExtractor;
use App\Enums\ProductRole;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class AiDocumentsTest extends AiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function upload(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->from(route('versions.documents', $this->version))
            ->post(route('versions.documents.store', $this->version), ['document' => $file]);
    }

    public function test_a_text_document_is_stored_privately_with_its_text(): void
    {
        $this->upload($this->owner, UploadedFile::fake()->createWithContent('notes.md', "# Lock\nThe lock pairs over Bluetooth."))
            ->assertRedirect()->assertSessionHas('status');

        $document = Document::sole();
        $this->assertSame('notes.md', $document->original_name);
        $this->assertSame('text/markdown', $document->mime);
        $this->assertSame(hash('sha256', "# Lock\nThe lock pairs over Bluetooth."), $document->sha256);
        $this->assertSame('documents/'.$this->version->id.'/'.$document->id, $document->path);
        $this->assertStringContainsString('pairs over Bluetooth', $document->extracted_text);
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_a_file_whose_bytes_do_not_match_its_extension_is_refused(): void
    {
        $this->upload($this->owner, UploadedFile::fake()->createWithContent('datasheet.pdf', 'just text pretending to be a PDF'))
            ->assertSessionHasErrors('document');
        $this->upload($this->owner, UploadedFile::fake()->createWithContent('manual.docx', "PK\x03\x04 not really a zip"))
            ->assertSessionHasErrors('document');
        $this->upload($this->owner, UploadedFile::fake()->createWithContent('notes.txt', "%PDF-1.7\n binary"))
            ->assertSessionHasErrors('document');
        $this->upload($this->owner, UploadedFile::fake()->createWithContent('run.exe', 'MZ'))
            ->assertSessionHasErrors('document');

        $this->assertSame(0, Document::count());
    }

    public function test_a_word_document_is_read(): void
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addTitle('Architecture', 1);
        $section->addText('The hub talks to the cloud over TLS.');
        $path = tempnam(sys_get_temp_dir(), 'docx');
        IOFactory::createWriter($word, 'Word2007')->save($path);

        $this->assertTrue(app(DocumentStore::class)->contentMatches($path, 'docx'));
        $text = app(TextExtractor::class)->extract($path, 'docx');
        $this->assertStringContainsString('The hub talks to the cloud over TLS.', $text);
        $this->assertStringContainsString('Architecture', $text);
        @unlink($path);
    }

    public function test_download_is_an_authorised_attachment(): void
    {
        $this->upload($this->owner, UploadedFile::fake()->createWithContent('notes.txt', 'Plain notes.'));
        $document = Document::sole();

        $response = $this->actingAs($this->owner)->get(route('versions.documents.download', [$this->version, $document]));
        $response->assertOk();
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // A reviewer of the product may read it, a stranger may not even learn it exists.
        $this->actingAs($this->member(ProductRole::Reviewer))->get(route('versions.documents.download', [$this->version, $document]))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('versions.documents.download', [$this->version, $document]))->assertNotFound();
    }

    public function test_only_editors_upload_and_delete(): void
    {
        $reviewer = $this->member(ProductRole::Reviewer);
        $this->upload($reviewer, UploadedFile::fake()->createWithContent('notes.txt', 'x'))->assertNotFound();

        $this->upload($this->owner, UploadedFile::fake()->createWithContent('notes.txt', 'Plain notes.'));
        $document = Document::sole();

        $this->actingAs($reviewer)->delete(route('versions.documents.destroy', [$this->version, $document]))->assertNotFound();
        $this->actingAs($this->owner)->delete(route('versions.documents.destroy', [$this->version, $document]))->assertRedirect();
        $this->assertSame(0, Document::count());
        Storage::disk('local')->assertMissing($document->path);
    }

    public function test_the_count_limit_is_enforced(): void
    {
        config(['redact.ai.max_documents_per_version' => 1]);
        $this->upload($this->owner, UploadedFile::fake()->createWithContent('a.txt', 'One.'));
        $this->upload($this->owner, UploadedFile::fake()->createWithContent('b.txt', 'Two.'))->assertSessionHasErrors('document');
        $this->assertSame(1, Document::count());
    }

    public function test_the_panel_lists_documents_and_offers_the_draft_with_its_units(): void
    {
        $this->upload($this->owner, UploadedFile::fake()->createWithContent('notes.txt', 'Plain notes.'));

        $this->actingAs($this->owner);
        $html = (string) $this->blade('@include("documents._panel", ["version" => $version])', ['version' => $this->version]);
        $this->assertStringContainsString('notes.txt', $html);
        $this->assertStringContainsString(__('ai.draft.button'), $html);
        $this->assertStringContainsString('Uses 11 AI units.', $html);
    }
}
