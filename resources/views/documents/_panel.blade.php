{{--
    Product documents of a version: list, download, upload, delete, and "Draft from documentation".
    @include('documents._panel', ['version' => $version])
--}}
@php
    extract(\App\Http\Controllers\DocumentController::panelData($version));
@endphp
<div class="a-stack gap-3!">
    @if ($isGuest)
        @include('guest._documents-notice')
    @endif
    @if ($documents->isEmpty())
        <p class="a-help flex items-center gap-1.5"><x-lucide-file-x class="size-3.5 shrink-0" aria-hidden="true" />{{ __('ai.documents.none') }}</p>
    @else
        <ul class="divide-y divide-line rounded-lg border border-line">
            @foreach ($documents as $document)
                <li class="flex items-center justify-between gap-3 px-3 py-2">
                    <x-lucide-file-text class="size-4 shrink-0 text-ink-subtle" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('versions.documents.download', [$version, $document]) }}" class="a-row-link break-all text-sm"
                           title="{{ __('ai.documents.download_tooltip') }}">{{ $document->original_name }}</a>
                        <p class="text-micro text-ink-subtle">
                            @if ($type = mb_strtoupper(pathinfo($document->original_name, PATHINFO_EXTENSION)))<span class="font-bold">{{ $type }}</span> · @endif{{ \Illuminate\Support\Number::fileSize($document->size, 1) }} · {{ $document->created_at->isoFormat('D MMMM YYYY') }}
                            @if ($uploaders[$document->uploaded_by] ?? null)
                                · {{ $uploaders[$document->uploaded_by] }}
                            @endif
                        </p>
                    </div>
                    @if ($canEdit)
                        <form method="POST" action="{{ route('versions.documents.destroy', [$version, $document]) }}"
                              x-data="confirmSubmit" data-confirm="{{ __('ai.documents.confirm_delete', ['name' => $document->original_name]) }}" @submit="check">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="a-btn a-btn-ghost a-btn-sm a-btn-icon" title="{{ __('ai.documents.delete_tooltip') }}">
                                <x-lucide-trash-2 aria-hidden="true" /><span class="a-sr-only">{{ __('common.delete') }}</span>
                            </button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canEdit && $documents->count() < $maxDocuments)
        {{-- The one upload (ImportController@store): cetome REDact workbooks go to the exact import
             preview, every other file becomes a document of the version. --}}
        <form method="POST" action="{{ route('versions.import.store', $version) }}" enctype="multipart/form-data" class="a-field">
            @csrf
            <label class="a-label" for="document-upload-{{ $version->id }}">{{ __('ai.documents.add') }}</label>
            <span class="flex flex-wrap items-center gap-2">
                <input id="document-upload-{{ $version->id }}" type="file" name="files[]" multiple required
                       accept=".pdf,.docx,.xlsx,.xlsm,.csv,.txt,.md,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain,text/markdown"
                       class="block min-w-0 flex-1 text-sm text-ink-muted file:mr-3 file:h-7 file:cursor-pointer file:rounded-md file:border file:border-line-strong file:bg-surface file:px-3 file:text-[10px] file:font-extrabold file:uppercase file:tracking-[0.12em] file:text-ink">
                {{-- Where it goes in the technical file (RED Annex V), optional (Declaration step). --}}
                @include('declaration._category-select', ['name' => 'category', 'id' => 'document-category-'.$version->id, 'value' => old('category')])
                <button type="submit" class="a-btn a-btn-outline a-btn-sm"><x-lucide-upload aria-hidden="true" />{{ __('ai.documents.upload') }}</button>
            </span>
            <p class="a-help">{{ __('ai.documents.limits', ['mb' => $maxMb, 'max' => $maxDocuments]) }}</p>
            <p class="a-help flex items-start gap-1"><x-lucide-file-spreadsheet class="mt-0.5 size-3 shrink-0" aria-hidden="true" />{{ __('import.panel_help', ['files' => \App\Http\Controllers\ImportController::MAX_FILES]) }}</p>
            @foreach (['files', 'files.*', 'document', 'category'] as $uploadField)
                @foreach ($errors->get($uploadField) as $uploadErrors)
                    @foreach ((array) $uploadErrors as $message)
                        <p class="a-error">{{ $message }}</p>
                    @endforeach
                @endforeach
            @endforeach
        </form>
    @endif

    @php
        // Why the AI is unavailable is said once per page (x-ai.assist uses the same flag).
        $explainAi = $aiReason !== null && ! request()->attributes->get('ai.explained');
        if ($canEdit && $documents->isNotEmpty() && $explainAi) {
            request()->attributes->set('ai.explained', true);
        }
    @endphp
    @if ($canEdit && $documents->isNotEmpty() && ($aiReason === null || $explainAi))
        <div class="border-t border-line pt-3">
            @if ($aiReason === null)
                <form method="POST" action="{{ route('ai.draft', $version) }}">
                    @csrf
                    <button type="submit" class="a-btn a-btn-primary a-btn-sm" @disabled($draftRunning)
                            title="{{ __('ai.draft.tooltip') }} {{ \App\Ai\Feature::costHint($draftUnits) }}">
                        <x-lucide-sparkles aria-hidden="true" /> {{ __('ai.draft.button') }}
                    </button>
                </form>
                <p class="a-help mt-1.5">
                    {{ $draftRunning ? __('ai.draft.running') : __('ai.draft.where') }}
                    <a href="{{ route('versions.ai', $version) }}" class="a-link-btn">{{ __('ai.review.open') }}</a>
                </p>
            @else
                <p class="a-help flex items-start gap-1"><x-lucide-sparkles class="size-3 mt-0.5 shrink-0" aria-hidden="true" /> <span>{{ $aiReason }} <x-ai.plan-link :scope="$version" /></span></p>
            @endif
        </div>
    @endif
</div>
