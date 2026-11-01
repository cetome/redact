{{--
    "Public page" tool of a version (PublicPageController): publication state, address and QR code,
    what the page shows (a draft), the preview dialog, from which the draft is published.
--}}
<x-layouts.app :title="__('public_page.title').' · '.$version->displayName()">
    <x-slot:subnav><x-version-nav :version="$version" active="public" /></x-slot:subnav>
    @php
        $product = $version->product;
        $code = $product->public_code;
        $publicUrl = $code ? route('verify.show', $code) : null;
        $published = $version->published_at !== null;
        $approved = $version->status === \App\Enums\VersionStatus::Approved;
        $disabled = ! $canChoose;
        $missing = $settings->missing();
        $date = fn ($d) => $d?->locale(app()->getLocale())->isoFormat('D MMMM YYYY');
    @endphp

    @if (! $canChoose)
        <x-read-only-notice :access="$access" :version="$version" />
        @if ($access->canView() && ! $access->canManage())
            <div class="a-alert a-alert-neutral"><x-lucide-lock aria-hidden="true" /><span>{{ __('public_page.read_only') }}</span></div>
        @endif
    @elseif (! $approved)
        <div class="a-alert"><x-lucide-info aria-hidden="true" /><span>{{ __('public_page.needs_approval') }}</span></div>
    @endif

    {{-- Level colours of this version (risk matrix settings), for the risk rows below. --}}
    <style nonce="{{ app('csp-nonce') }}">
        @foreach ($levels as $rank => $level)
            @if (preg_match('/^#[0-9A-Fa-f]{6}$/', $level->color))
                @php
                    [$r, $g, $b] = array_map(fn ($h) => (($c = hexdec($h) / 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4), str_split(substr($level->color, 1), 2));
                    $ink = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) > 0.18 ? '#0f172a' : '#fff';
                @endphp
                .risk-level-{{ (int) $rank }} { background-color: {{ $level->color }}; color: {{ $ink }}; }
            @endif
        @endforeach
    </style>

    <x-ui.page-header :title="__('public_page.title')" :subtitle="__('public_page.lead')">
        <x-slot:eyebrow><x-ui.version-label :version="$version" /></x-slot:eyebrow>
        <x-slot:actions>
            @if ($published && $publicUrl)
                <a href="{{ $publicUrl }}" class="a-btn a-btn-outline" target="_blank" rel="noopener"><x-lucide-external-link aria-hidden="true" />{{ __('public_page.open_page') }}</a>
            @endif
            {{-- Saves the choices first, so the preview (and what Publish sends) is what the form shows. --}}
            @if ($canChoose)
                <button type="submit" form="public-form" name="then" value="preview" class="a-btn a-btn-primary"><x-lucide-eye aria-hidden="true" />{{ __('public_page.preview') }}</button>
            @else
                <a href="{{ route('versions.public.preview', $version) }}" class="a-btn a-btn-primary" data-dialog-open="public-preview"><x-lucide-eye aria-hidden="true" />{{ __('public_page.preview') }}</a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="a-stack">
        {{-- Publication: state, the fixed address and its QR code. --}}
        <section class="a-panel" aria-labelledby="pub-state">
            <div class="a-panel-head">
                <h2 class="a-h3" id="pub-state">
                    @if ($published)
                        <span class="a-badge a-badge-success">{{ __('public_page.status.published') }}</span>
                        <span class="a-muted text-sm font-normal">{{ __('public_page.status.since', ['date' => $date($version->published_at)]) }}</span>
                        @if ($unpublished)<span class="a-badge a-badge-warning">{{ __('public_page.status.changes') }}</span>@endif
                    @else
                        <span class="a-badge a-badge-neutral">{{ __('public_page.status.not_published') }}</span>
                    @endif
                </h2>
                @if ($published && $canChoose)
                    <form method="POST" action="{{ route('versions.public.unpublish', $version) }}" class="ml-auto" title="{{ __('public_page.unpublish_confirm') }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="a-btn a-btn-ghost a-btn-sm"><x-lucide-eye-off aria-hidden="true" />{{ __('public_page.unpublish') }}</button>
                    </form>
                @endif
            </div>
            <div class="a-panel-body">
                <div class="pp-qr-box">
                    @if ($publicUrl)
                        <span class="pp-qr-big">{!! \App\PublicPage\QrCode::inline($publicUrl) !!}</span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="a-micro">{{ __('public_page.address') }}</p>
                        @if ($publicUrl)
                            <p class="font-bold break-all">{{ preg_replace('#^https?://#', '', $publicUrl) }}</p>
                        @else
                            <p class="a-help">{{ __('public_page.address_pending') }}</p>
                        @endif
                    </div>
                    @if ($publicUrl)
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('products.qr', [$product, 'svg']) }}" class="a-btn a-btn-outline" download><x-lucide-qr-code aria-hidden="true" />{{ __('public_page.qr_svg') }}</a>
                            @if (extension_loaded('gd'))
                                <a href="{{ route('products.qr', [$product, 'png']) }}" class="a-btn a-btn-outline" download><x-lucide-image aria-hidden="true" />{{ __('public_page.qr_png') }}</a>
                            @endif
                            @if ($published)
                                <a href="{{ route('versions.public.labels', [$version, 'packaging', app()->getLocale()]) }}" class="a-btn a-btn-outline"><x-lucide-printer aria-hidden="true" />{{ __('public_page.labels.button') }}</a>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </section>

        <form method="POST" action="{{ route('versions.public.update', $version) }}" id="public-form" class="a-stack" @if ($canChoose) data-autosave @endif>
            @csrf @method('PUT')

            {{-- What the page shows: product and manufacturer always, the rest switched. --}}
            <section class="a-panel" aria-labelledby="pub-sections">
                <div class="a-panel-head"><h2 class="a-h3" id="pub-sections">{{ __('public_page.shows') }}</h2></div>
                <div class="a-panel-body">
                    <div class="pp-switches">
                        @foreach (['product', 'manufacturer'] as $key)
                            <div class="pp-locked">
                                <span class="min-w-0"><b>{{ __('public_page.sections.'.$key) }}</b><span class="pp-switch-help">{{ __('public_page.section_help.'.$key) }}</span></span>
                                <span class="a-badge a-badge-neutral">{{ __('public_page.always') }}</span>
                            </div>
                        @endforeach
                        @foreach (array_keys(\App\PublicPage\PublicSettings::SECTIONS) as $key)
                            <label class="a-switch">
                                <span class="a-switch-text"><b>{{ __('public_page.sections.'.$key) }}</b><span class="pp-switch-help">{{ __('public_page.section_help.'.$key) }}</span></span>
                                <input type="hidden" name="sections[{{ $key }}]" value="0">
                                <input type="checkbox" name="sections[{{ $key }}]" value="1" @checked(old('sections.'.$key, $settings->shows($key))) @disabled($disabled)>
                                <span class="a-switch-pill" data-on="{{ __('public_page.shown') }}" data-off="{{ __('public_page.hidden') }}" aria-hidden="true"></span>
                            </label>
                            @if ($key === 'support')
                                <div class="a-field a-switch-follow">
                                    <label class="a-label" for="support_until">{{ __('public_page.support_label') }}</label>
                                    <input type="date" id="support_until" name="support_until" class="a-input max-w-60" value="{{ old('support_until', $settings->supportUntil) }}"
                                           @disabled($disabled) @error('support_until') aria-invalid="true" @enderror>
                                    @error('support_until')<p class="a-error">{{ $message }}</p>@enderror
                                </div>
                            @elseif ($key === 'contact')
                                <div class="a-field a-switch-follow">
                                    <label class="a-label" for="contact">{{ __('public_page.contact_label') }}</label>
                                    <input type="text" id="contact" name="contact" class="a-input" maxlength="{{ \App\PublicPage\PublicSettings::CONTACT_MAX }}"
                                           value="{{ old('contact', $settings->contact) }}" placeholder="security@… · https://…" autocomplete="off"
                                           @disabled($disabled) @error('contact') aria-invalid="true" @enderror>
                                    <p class="a-help">{{ __('public_page.contact_help') }}</p>
                                    @error('contact')<p class="a-error">{{ $message }}</p>@enderror
                                </div>
                            @endif
                        @endforeach
                        {{-- Not a section: whether search engines list the page (the latest version only). --}}
                        <label class="a-switch">
                            <span class="a-switch-text"><b>{{ __('public_page.indexed') }}</b><span class="pp-switch-help">{{ __('public_page.indexed_help') }}</span></span>
                            <input type="hidden" name="indexed" value="0">
                            <input type="checkbox" name="indexed" value="1" @checked(old('indexed', $settings->indexed)) @disabled($disabled)>
                            <span class="a-switch-pill" data-on="{{ __('public_page.listed') }}" data-off="{{ __('public_page.not_listed') }}" aria-hidden="true"></span>
                        </label>
                    </div>
                </div>
            </section>

            {{-- Documents offered for download. --}}
            <section class="a-panel" id="documents" aria-labelledby="pub-documents">
                <div class="a-panel-head"><h2 class="a-h3" id="pub-documents">{{ __('public_page.documents.title') }}</h2></div>
                <div class="a-panel-body">
                    @if ($documents->isEmpty())
                        <p class="a-help">{{ __('public_page.documents.none') }}</p>
                    @else
                        <div class="pp-switches">
                            @foreach ($documents as $document)
                                <label class="a-check">
                                    <input type="checkbox" name="documents[]" value="{{ $document->id }}" @checked(in_array($document->id, old('documents', $settings->documents), true)) @disabled($disabled)>
                                    <span class="min-w-0"><b class="break-all">{{ $document->original_name }}</b>
                                        <span class="a-muted text-sm">· {{ \App\Support\FileSize::format((int) $document->size) }}@if ($document->category && \Illuminate\Support\Facades\Lang::has('public_page.documents.kinds.'.$document->category)) · {{ __('public_page.documents.kinds.'.$document->category) }}@endif</span></span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            {{-- Risks listed one by one, in public words. --}}
            <section class="a-panel" id="risks" aria-labelledby="pub-risks">
                <div class="a-panel-head"><h2 class="a-h3" id="pub-risks">{{ __('public_page.risks.title') }}</h2></div>
                <div class="a-panel-body">
                    <p class="a-help mb-3">{{ __('public_page.risks.lead') }}</p>
                    @if ($canChoose && $levels->isNotEmpty())
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <label class="text-sm font-semibold" for="risk-from">{{ __('public_page.risks.from') }}</label>
                            <select id="risk-from" name="from" form="risks-from-form" class="a-select a-input-sm w-auto">
                                @foreach ($levels as $rank => $level)
                                    <option value="{{ $rank }}" @selected($rank === \App\PublicPage\PublicSettings::DEFAULT_RISK_FROM)>{{ $level->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" form="risks-from-form" class="a-btn a-btn-outline a-btn-sm">{{ __('public_page.risks.apply') }}</button>
                        </div>
                    @endif
                    @if ($risks->isEmpty())
                        <p class="a-help">{{ __('public_page.risks.none') }}</p>
                    @else
                        <div class="a-table-wrap">
                            <table class="a-table a-table-compact">
                                <thead><tr>
                                    <th scope="col" class="is-shrink"><span class="a-sr-only">{{ __('public_page.shown') }}</span></th>
                                    <th scope="col" class="is-shrink">{{ __('public_page.risks.level') }}</th>
                                    <th scope="col">{{ __('public_page.risks.internal') }}</th>
                                    <th scope="col">{{ __('public_page.risks.public_title') }}</th>
                                    <th scope="col">{{ __('public_page.risks.advice') }}</th>
                                </tr></thead>
                                <tbody>
                                    @foreach ($risks as $risk)
                                        <tr>
                                            <td class="is-shrink"><input type="checkbox" name="risks[]" value="{{ $risk->id }}" class="a-check-input" aria-label="{{ $risk->threat }}"
                                                @checked(in_array($risk->id, old('risks', $settings->risks), true)) @disabled($disabled)></td>
                                            <td class="is-shrink">
                                                @if ($risk->residual_rank && $levels->has($risk->residual_rank))
                                                    <span class="a-level risk-level-{{ (int) $risk->residual_rank }}">{{ $levels[$risk->residual_rank]->name }}</span>
                                                @else
                                                    <span class="a-level a-level-empty">{{ __('risks.not_rated') }}</span>
                                                @endif
                                            </td>
                                            <td class="min-w-40">{{ $risk->threat }}</td>
                                            <td class="min-w-56"><input type="text" name="risk_texts[{{ $risk->id }}][title]" class="a-input a-input-sm" maxlength="{{ \App\PublicPage\PublicSettings::RISK_TEXT_MAX }}"
                                                value="{{ old('risk_texts.'.$risk->id.'.title', $settings->riskTexts[$risk->id]['title'] ?? '') }}" placeholder="{{ __('public_page.risks.public_title_placeholder') }}"
                                                aria-label="{{ __('public_page.risks.public_title') }}: {{ $risk->threat }}" @disabled($disabled)></td>
                                            <td class="min-w-56"><input type="text" name="risk_texts[{{ $risk->id }}][advice]" class="a-input a-input-sm" maxlength="{{ \App\PublicPage\PublicSettings::RISK_TEXT_MAX }}"
                                                value="{{ old('risk_texts.'.$risk->id.'.advice', $settings->riskTexts[$risk->id]['advice'] ?? '') }}"
                                                aria-label="{{ __('public_page.risks.advice') }}: {{ $risk->threat }}" @disabled($disabled)></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </section>

            @if ($canChoose)
                <div class="a-savebar">
                    <span class="a-autosave a-only-js" data-autosave-status data-saving="{{ __('public_page.saving') }}" data-saved="{{ __('public_page.autosaved') }}" data-failed="{{ __('public_page.save_failed') }}">{{ __('public_page.autosave') }}</span>
                    <span class="a-spacer"></span>
                    <button type="submit" class="a-btn a-btn-outline"><x-lucide-save aria-hidden="true" />{{ __('public_page.save') }}</button>
                    <button type="submit" name="then" value="preview" class="a-btn a-btn-primary"><x-lucide-eye aria-hidden="true" />{{ __('public_page.save_preview') }}</button>
                </div>
            @endif
        </form>

        @if ($canChoose)
            <form method="POST" action="{{ route('versions.public.risks', $version) }}" id="risks-from-form" hidden>@csrf</form>

            <section class="a-panel" aria-labelledby="pub-upload">
                <div class="a-panel-head"><h2 class="a-h3" id="pub-upload">{{ __('public_page.documents.add') }}</h2></div>
                <form method="POST" action="{{ route('versions.public.documents', $version) }}" enctype="multipart/form-data" class="a-panel-body a-form-grid">
                    @csrf
                    <div class="a-field">
                        <label class="a-label" for="pub-file">{{ __('public_page.documents.add') }}</label>
                        <input type="file" id="pub-file" name="document" class="a-input a-file" required accept=".{{ implode(',.', array_keys(\App\Documents\DocumentStore::KINDS)) }}">
                        @error('document')<p class="a-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="a-field">
                        <label class="a-label" for="pub-kind">{{ __('public_page.documents.kind') }}</label>
                        <select id="pub-kind" name="category" class="a-select">
                            @foreach (\App\Http\Controllers\PublicPageController::UPLOAD_CATEGORIES as $kind)
                                <option value="{{ $kind }}">{{ __('public_page.documents.kinds.'.$kind) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="a-span-all"><button type="submit" class="a-btn a-btn-outline"><x-lucide-upload aria-hidden="true" />{{ __('public_page.documents.add') }}</button></div>
                </form>
            </section>
        @endif
    </div>

    {{-- The preview: the page exactly as visitors see it, published from here. --}}
    <x-ui.drawer id="public-preview" modal wide :open="$preview" :close-url="route('versions.public', $version)"
        :title="$published && ! $unpublished ? __('public_page.preview_title_live') : __('public_page.preview_title')">
        <p class="a-help pp-preview-lead">{{ $publicUrl ? __('public_page.preview_lead', ['url' => preg_replace('#^https?://#', '', $publicUrl)]) : __('public_page.preview_lead_new') }}</p>
        <div class="pp-preview">
            @include('public-product._page', ['page' => $page, 'product' => $product, 'published' => collect(), 'latest' => true, 'preview' => true])
        </div>
        <x-slot:footer>
            @if ($missing !== [])
                <p class="a-error">{{ __('public_page.missing', ['fields' => collect($missing)->map(fn ($k) => __('public_page.sections.'.$k))->implode(', ')]) }}</p>
            @endif
            <span class="a-spacer"></span>
            <a href="{{ route('versions.public', $version) }}" class="a-btn a-btn-ghost" data-dialog-close>{{ __('common.close') }}</a>
            @if ($canPublish && $missing === [] && (! $published || $unpublished))
                <form method="POST" action="{{ route('versions.public.publish', $version) }}">
                    @csrf
                    <button type="submit" class="a-btn a-btn-primary"><x-lucide-globe aria-hidden="true" />{{ $published ? __('public_page.publish_changes') : __('public_page.publish') }}</button>
                </form>
            @endif
        </x-slot:footer>
    </x-ui.drawer>
</x-layouts.app>
