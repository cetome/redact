<?php

namespace App\Documents;

use App\Models\Document;
use App\Models\ProductVersion;
use App\Models\User;
use App\Workbooks\XlsxPackage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Product documents for the AI to read (PDF, Word, Excel, CSV, text, Markdown): checked by content (magic bytes, not
 * the name the browser sent), stored on the private disk under
 * documents/{version}/{id}, fingerprinted (sha256) and turned into text once.
 */
class DocumentStore
{
    public const DISK = 'local';

    /** Accepted kinds: extension => stored MIME type. */
    public const KINDS = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
    ];

    public function __construct(private readonly TextExtractor $extractor) {}

    public static function maxKilobytes(): int
    {
        return max(1, (int) config('redact.ai.max_document_mb', 25)) * 1024;
    }

    public static function maxCount(): int
    {
        return max(1, (int) config('redact.ai.max_documents_per_version', 20));
    }

    /** $category: the Annex V heading it is filed under (App\Declaration\TechnicalFile::CATEGORIES), optional. */
    public function store(ProductVersion $version, UploadedFile $file, User $by, ?string $category = null): Document
    {
        if ($version->documents()->count() >= self::maxCount()) {
            throw ValidationException::withMessages(['document' => __('ai.documents.too_many', ['max' => self::maxCount()])]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();
        if (! isset(self::KINDS[$extension]) || ! $path || ! $this->contentMatches($path, $extension)) {
            throw ValidationException::withMessages(['document' => __('ai.documents.wrong_type')]);
        }

        $id = strtolower((string) Str::ulid());
        $stored = Storage::disk(self::DISK)->putFileAs('documents/'.$version->getKey(), $file, $id);
        if ($stored === false) {
            throw ValidationException::withMessages(['document' => __('ai.documents.not_saved')]);
        }

        $document = new Document([
            'product_version_id' => $version->getKey(),
            'uploaded_by' => $by->getKey(),
            'original_name' => self::cleanName($file->getClientOriginalName(), $extension),
            'mime' => self::KINDS[$extension],
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $path),
            'path' => $stored,
            'category' => $category,
            'extracted_text' => $this->extractor->extract($path, $extension),
        ]);
        $document->id = $id;
        $document->save();

        return $document;
    }

    public function delete(Document $document): void
    {
        Storage::disk(self::DISK)->delete($document->path);
        $document->delete();
    }

    /** Is the file really what its extension says? */
    public function contentMatches(string $path, string $extension): bool
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return false;
        }
        $head = (string) fread($handle, 8);
        fclose($handle);

        return match ($extension) {
            'pdf' => str_starts_with($head, '%PDF-'),
            'docx' => str_starts_with($head, "PK\x03\x04") && $this->isWordPackage($path),
            // Checked as a zip first (entries, sizes, ratio): PhpSpreadsheet reads it afterwards.
            'xlsx' => XlsxPackage::looksLikeXlsx($path),
            'txt', 'md', 'csv' => $this->isText($path),
            default => false,
        };
    }

    private function isWordPackage(string $path): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $ok = $zip->locateName('word/document.xml') !== false && $zip->locateName('[Content_Types].xml') !== false;
        $zip->close();

        return $ok;
    }

    private function isText(string $path): bool
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? '';

        // Binary files (NUL bytes, other formats' signatures) are refused; text must be UTF-8.
        return ! str_contains($content, "\0")
            && ! str_starts_with($content, '%PDF-')
            && ! str_starts_with($content, "PK\x03\x04")
            && mb_check_encoding($content, 'UTF-8');
    }

    public static function cleanName(string $name, string $extension): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F"<>:|?*\/\\\\]/u', '', $name) ?? '';
        $name = trim($name, " .\t");
        if ($name === '') {
            $name = 'document';
        }
        if (! str_ends_with(strtolower($name), '.'.$extension)) {
            $name .= '.'.$extension;
        }

        return mb_substr($name, -200);
    }
}
