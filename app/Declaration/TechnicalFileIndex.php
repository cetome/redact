<?php

namespace App\Declaration;

use Illuminate\Support\Carbon;

/**
 * The index of the technical file (Word, at the root of the zip): one table per point of
 * Annex V of Directive 2014/53/EU with each item, its source, its status and its file.
 */
final class TechnicalFileIndex
{
    public function __construct(private readonly TechnicalFile $file) {}

    public function save(string $path): void
    {
        $file = $this->file;
        $data = $file->data;
        $version = $file->version();
        $product = $version->product;

        $doc = new WordDoc(__('declaration.file.names.index'), __('declaration.word.footer', ['product' => $product->name, 'version' => $version->label]));
        $doc->title(__('declaration.file.index.title'));
        $doc->text(trim($product->name.' · '.__('declaration.version_word', ['label' => $version->label])), ['bold' => true, 'size' => 12]);
        $doc->facts([
            __('declaration.file.doc.manufacturer') => $data->manufacturerName(),
            __('declaration.file.index.status') => $version->status->label(),
            __('declaration.file.index.route') => $version->conformity_route?->label() ?? __('declaration.file.index.no_route'),
            __('declaration.file.index.generated') => Carbon::now()->locale(app()->getLocale())->isoFormat('D MMMM YYYY, HH:mm'),
        ]);
        $doc->muted(__('declaration.file.readme.lead'));
        if ($data->isDraft()) {
            $doc->notice((string) $data->watermark(), array_map(fn (array $item) => $item['label'], $data->openItems()));
        }

        $counts = $file->counts();
        $doc->text(__('declaration.file.index.summary', $counts), ['size' => 9]);

        foreach ($file->checklist() as $row) {
            $doc->heading(TechnicalFile::folder($row['heading']));
            $doc->text(__('declaration.file.points.'.$row['heading']), ['size' => 9, 'italic' => true, 'color' => WordDoc::MUTED]);
            $table = $doc->table();
            $doc->headerRow($table, [
                __('declaration.file.index.item') => 34, __('declaration.file.index.source') => 18,
                __('declaration.file.index.state') => 14, __('declaration.file.index.file') => 34,
            ]);
            foreach ($row['items'] as $item) {
                $table->addRow(null, ['cantSplit' => true]);
                $label = $table->addCell($doc->width(34), ['valign' => 'top']);
                $doc->lines($item['label'], '—', ['size' => 9], $label);
                if ($item['note'] !== '') {
                    $doc->lines($item['note'], '', ['size' => 8, 'color' => WordDoc::MUTED], $label);
                }
                $doc->lines($item['source'], '—', ['size' => 9], $table->addCell($doc->width(18), ['valign' => 'top']));
                $doc->statusCell($table, 14, $item['status'], __('declaration.file.status.'.$item['status']));
                $doc->lines($item['file'] !== null ? basename((string) $item['file']) : '', '—', ['size' => 8], $table->addCell($doc->width(34), ['valign' => 'top']));
            }
            $doc->spacer();
        }
        $doc->muted(__('declaration.file.readme.legal'));

        $doc->save($path);
    }
}
