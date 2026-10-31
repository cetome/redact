{{-- The Notified Body's certificate (Module B + C or H): recorded only once issued, after the review. --}}
@php
    $certificate = $version->certificate_document_id ? $version->certificateDocument : null;
@endphp
<section class="a-panel" id="certificate" aria-labelledby="certificate-title">
    <div class="a-panel-head">
        <div class="min-w-0">
            <h2 class="a-h3 flex items-center gap-2" id="certificate-title"><x-lucide-award class="size-4 text-brand-blue" aria-hidden="true" />{{ __('declaration.certificate.title') }}</h2>
            <p class="a-help mt-0.5">{{ $version->conformity_route === \App\Enums\ConformityRoute::ModuleH ? __('declaration.certificate.lead_h') : __('declaration.certificate.lead_b') }}</p>
        </div>
        @if ($data->hasCertificate())
            <span class="a-badge a-badge-success shrink-0"><x-lucide-check aria-hidden="true" />{{ __('declaration.certificate.recorded') }}</span>
        @endif
    </div>
    @if (! $approved)
        <div class="a-panel-body">
            <p class="a-decl-wait"><x-lucide-clock aria-hidden="true" /><span>{{ __('declaration.certificate.later') }}</span></p>
            <p class="a-help">{{ __('declaration.certificate.body_from', ['name' => $version->notified_body ?: '—', 'number' => $version->notified_body_number ?: '—']) }}</p>
        </div>
    @elseif ($canCertificate)
        <form method="POST" action="{{ route('versions.certificate.update', $version) }}" enctype="multipart/form-data" class="a-panel-body a-stack gap-3!">
            @csrf
            @method('PUT')
            <div class="a-form-grid">
                <div class="a-field">
                    <label class="a-label" for="cert-reference">{{ __('declaration.certificate.reference') }}</label>
                    <input id="cert-reference" name="certificate_reference" class="a-input a-input-sm" maxlength="120" required value="{{ old('certificate_reference', $version->certificate_reference) }}" @error('certificate_reference') aria-invalid="true" @enderror>
                    @error('certificate_reference')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                <div class="a-field">
                    <label class="a-label" for="cert-issued">{{ __('declaration.certificate.issued_on') }}</label>
                    <input id="cert-issued" name="certificate_issued_on" type="date" class="a-input a-input-sm" required value="{{ old('certificate_issued_on', $version->certificate_issued_on?->toDateString()) }}" @error('certificate_issued_on') aria-invalid="true" @enderror>
                    @error('certificate_issued_on')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                <div class="a-field">
                    <label class="a-label" for="cert-expires">{{ __('declaration.certificate.expires_on') }} <span class="a-label-hint">{{ __('common.optional') }}</span></label>
                    <input id="cert-expires" name="certificate_expires_on" type="date" class="a-input a-input-sm" value="{{ old('certificate_expires_on', $version->certificate_expires_on?->toDateString()) }}" @error('certificate_expires_on') aria-invalid="true" @enderror>
                    @error('certificate_expires_on')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                <div class="a-field">
                    <label class="a-label" for="cert-body-number">{{ __('identification.notified_body_number') }}</label>
                    <input id="cert-body-number" name="certificate_body_number" class="a-input a-input-sm" inputmode="numeric" pattern="\d{4}" maxlength="4" value="{{ old('certificate_body_number', $version->certificate_body_number ?: $version->notified_body_number) }}" @error('certificate_body_number') aria-invalid="true" @enderror>
                    @error('certificate_body_number')<p class="a-error">{{ $message }}</p>@enderror
                </div>
                <div class="a-field a-span-all">
                    <label class="a-label" for="cert-body">{{ __('declaration.certificate.body') }}</label>
                    <input id="cert-body" name="certificate_body" class="a-input a-input-sm" maxlength="160" value="{{ old('certificate_body', $version->certificate_body ?: $version->notified_body) }}">
                    <p class="a-help">{{ __('declaration.certificate.body_help') }}</p>
                </div>
                <div class="a-field a-span-all">
                    <label class="a-label" for="cert-file">{{ __('declaration.certificate.file') }} <span class="a-label-hint">{{ __('common.optional') }}</span></label>
                    @if ($certificate)
                        <p class="a-decl-file"><x-lucide-file-badge aria-hidden="true" />
                            <a href="{{ route('versions.documents.download', [$version, $certificate]) }}" class="a-row-link">{{ $certificate->original_name }}</a>
                            <span class="a-muted">{{ \App\Support\FileSize::format($certificate->size, 1) }}</span>
                            <label class="a-check ml-auto"><input type="checkbox" name="remove_file" value="1"> {{ __('declaration.certificate.remove_file') }}</label>
                        </p>
                    @endif
                    <input id="cert-file" type="file" name="certificate_file" accept=".pdf,application/pdf" class="a-file">
                    @error('certificate_file')<p class="a-error">{{ $message }}</p>@enderror
                    @error('document')<p class="a-error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="a-btn a-btn-primary a-btn-sm"><x-lucide-save aria-hidden="true" />{{ __('declaration.certificate.save') }}</button>
                <p class="a-help">{{ __('declaration.certificate.logged') }}</p>
            </div>
        </form>
    @else
        <div class="a-panel-body">
            @if ($data->hasCertificate())
                <dl class="a-dl">
                    <dt>{{ __('declaration.certificate.reference') }}</dt><dd>{{ $version->certificate_reference }}</dd>
                    <dt>{{ __('declaration.certificate.issued_on') }}</dt><dd>{{ \App\Declaration\DeclarationData::day($version->certificate_issued_on) ?: '—' }}</dd>
                    @if ($version->certificate_expires_on)
                        <dt>{{ __('declaration.certificate.expires_on') }}</dt><dd>{{ \App\Declaration\DeclarationData::day($version->certificate_expires_on) }}</dd>
                    @endif
                    <dt>{{ __('declaration.certificate.body') }}</dt><dd>{{ trim(($version->certificate_body ?: $version->notified_body).' '.($version->certificate_body_number ?: $version->notified_body_number)) ?: '—' }}</dd>
                    @if ($certificate)
                        <dt>{{ __('declaration.certificate.file') }}</dt><dd><a href="{{ route('versions.documents.download', [$version, $certificate]) }}" class="a-row-link">{{ $certificate->original_name }}</a></dd>
                    @endif
                </dl>
            @else
                <p class="a-help">{{ __('declaration.certificate.owner_only') }}</p>
            @endif
        </div>
    @endif
</section>
