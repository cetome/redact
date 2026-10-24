{{-- Technical file tab: the technical documentation of RED Annex V, point by point, with what is
     present, missing, a draft or not applicable, links to fix it, and the documents to file. --}}
@php
    $icons = ['present' => 'circle-check', 'missing' => 'circle-alert', 'draft' => 'file-warning', 'na' => 'circle-minus'];
    $badges = ['present' => 'a-badge-success', 'missing' => 'a-badge-danger', 'draft' => 'a-badge-warning', 'na' => 'a-badge-neutral'];
    $documents = $file->documents()->reject(fn ($d) => $d->id === $version->certificate_document_id)->values();
@endphp

<div class="a-stats a-tf-stats">
    <x-ui.stat :label="__('declaration.file.status.present')" :value="$counts['present']" tone="ok" />
    <x-ui.stat :label="__('declaration.file.status.missing')" :value="$counts['missing']" :tone="$counts['missing'] > 0 ? 'bad' : null" />
    <x-ui.stat :label="__('declaration.file.status.draft')" :value="$counts['draft']" />
    <x-ui.stat :label="__('declaration.file.status.na')" :value="$counts['na']" />
</div>

<section class="a-panel a-tf" aria-labelledby="tf-title">
    <div class="a-panel-head">
        <div class="min-w-0">
            <h2 class="a-h3 flex items-center gap-2" id="tf-title"><x-lucide-folder-tree class="size-4 text-brand-blue" aria-hidden="true" />{{ __('declaration.file.title') }}</h2>
            <p class="a-help mt-0.5">{{ __('declaration.file.lead') }}</p>
        </div>
    </div>
    <ol class="a-tf-list">
        @foreach ($file->checklist() as $row)
            <li class="a-tf-heading" id="tf-{{ $row['heading'] }}">
                <div class="a-tf-head">
                    <span class="a-tf-point">{{ __('declaration.file.letters.'.$row['heading']) }}</span>
                    <span class="min-w-0">
                        <span class="a-tf-title">{{ __('declaration.file.titles.'.$row['heading']) }}</span>
                        <span class="a-tf-annex">{{ __('declaration.file.points.'.$row['heading']) }}</span>
                    </span>
                    <span class="a-badge {{ $badges[$row['status']] }} shrink-0"><x-dynamic-component :component="'lucide-'.$icons[$row['status']]" aria-hidden="true" />{{ __('declaration.file.status.'.$row['status']) }}</span>
                </div>
                <ul class="a-tf-items">
                    @foreach ($row['items'] as $item)
                        <li @class(['a-tf-item', 'is-'.$item['status']])>
                            <x-dynamic-component :component="'lucide-'.$icons[$item['status']]" class="a-tf-icon" aria-hidden="true" />
                            <span class="min-w-0">
                                <span class="a-tf-label">{{ $item['label'] }}</span>
                                <span class="a-tf-meta">{{ $item['source'] }}@if ($item['file']) · <span class="a-tf-file">{{ basename($item['file']) }}</span>@endif @if ($item['note'] !== '') · {{ $item['note'] }}@endif</span>
                            </span>
                            <span class="a-sr-only">{{ __('declaration.file.status.'.$item['status']) }}</span>
                            @if ($item['fix'])
                                <a href="{{ $item['fix']['href'] }}" class="a-btn a-btn-ghost a-btn-sm a-tf-fix">{{ $item['fix']['label'] }}<x-lucide-arrow-right aria-hidden="true" /></a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </li>
        @endforeach
    </ol>
</section>

{{-- Documents of the version, filed under an Annex V heading. --}}
<section class="a-panel" id="documents" aria-labelledby="tf-docs-title">
    <div class="a-panel-head">
        <div class="min-w-0">
            <h2 class="a-h3 flex items-center gap-2" id="tf-docs-title"><x-lucide-folder-open class="size-4 text-brand-blue" aria-hidden="true" />{{ __('declaration.file.documents') }}</h2>
            <p class="a-help mt-0.5">{{ __('declaration.file.documents_lead') }}</p>
        </div>
    </div>
    @if ($documents->isEmpty())
        <p class="a-panel-body a-help">{{ __('declaration.file.no_documents') }}</p>
    @else
        <div class="a-table-wrap a-tf-docs">
            <table class="a-table a-table-compact">
                <thead>
                    <tr>
                        <th scope="col">{{ __('declaration.file.document') }}</th>
                        <th scope="col">{{ __('declaration.file.category') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($documents as $document)
                        <tr>
                            <td class="min-w-0">
                                <a href="{{ route('versions.documents.download', [$version, $document]) }}" class="a-row-link break-all">{{ $document->original_name }}</a>
                                <span class="a-row-sub">{{ \Illuminate\Support\Number::fileSize($document->size, 1) }} · {{ $document->created_at->isoFormat('D MMMM YYYY') }}</span>
                            </td>
                            <td class="is-nowrap">
                                @if ($canFile)
                                    <form method="POST" action="{{ route('versions.technical-file.category', [$version, $document]) }}" class="a-tf-file-form">
                                        @csrf
                                        @method('PATCH')
                                        @include('declaration._category-select', ['name' => 'category', 'id' => 'doc-cat-'.$document->id, 'value' => $document->category])
                                        <button type="submit" class="a-btn a-btn-outline a-btn-sm">{{ __('declaration.file.file_it') }}</button>
                                    </form>
                                @else
                                    {{ $document->category ? __('declaration.file.categories.'.$document->category) : __('declaration.file.category_none') }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <div class="a-panel-foot a-tf-upload">
        @if ($access->canEdit($version) && ! $isGuest)
            <form method="POST" action="{{ route('versions.documents.store', $version) }}" enctype="multipart/form-data" class="a-tf-upload-form">
                @csrf
                <label class="a-label" for="tf-upload">{{ __('ai.documents.add') }}</label>
                <input id="tf-upload" type="file" name="document" required class="a-file min-w-0 flex-1">
                @include('declaration._category-select', ['name' => 'category', 'id' => 'tf-upload-category', 'value' => old('category')])
                <button type="submit" class="a-btn a-btn-outline a-btn-sm"><x-lucide-upload aria-hidden="true" />{{ __('ai.documents.upload') }}</button>
                @error('document')<p class="a-error a-span-all">{{ $message }}</p>@enderror
            </form>
            <p class="a-help">{{ __('ai.documents.limits', ['mb' => (int) (\App\Documents\DocumentStore::maxKilobytes() / 1024), 'max' => \App\Documents\DocumentStore::maxCount()]) }}
                <a href="{{ route('versions.documents', $version) }}" class="a-link-btn">{{ __('declaration.file.all_documents') }}</a></p>
        @else
            <p class="a-help">{{ $isGuest ? __('declaration.file.upload_guest') : __('declaration.file.upload_draft') }}</p>
        @endif
    </div>
</section>
