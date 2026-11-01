<?php

namespace App\Http\Controllers;

use App\Documents\DocumentStore;
use App\Enums\VersionStatus;
use App\Models\Product;
use App\Models\ProductVersion;
use App\PublicPage\Labels;
use App\PublicPage\PublicCode;
use App\PublicPage\PublicPageData;
use App\PublicPage\PublicSettings;
use App\PublicPage\QrCode;
use App\Support\ProductAccess;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "Public page" tool of a version: what the public product page shows, its preview, and
 * publishing. Choices are saved as a draft; only "Publish" in the preview makes them public
 * (PublicSettings: draft and live copies). The product owner and the organisation's
 * administrators choose and publish; publishing needs the version approved.
 */
class PublicPageController extends Controller
{
    /** Categories a document added here gets (TechnicalFile files it under its Annex V point). */
    public const UPLOAD_CATEGORIES = ['user_information', 'declaration_signed', 'other'];

    public function edit(Request $request, ProductVersion $version): View
    {
        abort_unless(Gate::allows('view', $version), 404);
        abort_if($request->user()->is_guest, 404);
        $access = $this->access($version->product);
        $settings = PublicSettings::for($version);

        return view('public-page.edit', [
            'version' => $version,
            'access' => $access,
            'settings' => $settings,
            'page' => PublicPageData::for($version, $settings),
            'canChoose' => $this->canChoose($access, $version),
            'canPublish' => $this->canPublish($access, $version),
            'risks' => $version->riskEntries()->orderByRaw('residual_rank is null')->orderBy('residual_rank')->orderBy('position')
                ->get(['id', 'threat', 'residual_rank', 'position']),
            'levels' => $version->riskLevels()->orderBy('rank')->get()->keyBy('rank'),
            'documents' => $version->documents()->orderBy('created_at')->get(['id', 'original_name', 'mime', 'size', 'category', 'created_at']),
            'unpublished' => PublicSettings::hasUnpublishedChanges($version),
            'preview' => $request->route('panel') === 'preview',
        ]);
    }

    public function update(Request $request, ProductVersion $version): RedirectResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        abort_unless($this->canChoose($this->access($version->product), $version), 404);

        $documentIds = $version->documents()->pluck('id')->all();
        $riskIds = $version->riskEntries()->pluck('id')->all();
        $data = $request->validate([
            'sections' => ['nullable', 'array'],
            'sections.*' => ['nullable', 'boolean'],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['string', Rule::in($documentIds)],
            'contact' => ['nullable', 'string', 'max:'.PublicSettings::CONTACT_MAX, $this->contactRule()],
            'support_until' => ['nullable', 'date_format:Y-m-d'],
            'risks' => ['nullable', 'array'],
            'risks.*' => ['string', Rule::in($riskIds)],
            'risk_texts' => ['nullable', 'array'],
            'risk_texts.*.title' => ['nullable', 'string', 'max:'.PublicSettings::RISK_TEXT_MAX],
            'risk_texts.*.advice' => ['nullable', 'string', 'max:'.PublicSettings::RISK_TEXT_MAX],
            'indexed' => ['nullable', 'boolean'],
        ]);

        $sections = [];
        foreach (array_keys(PublicSettings::SECTIONS) as $key) {
            $sections[$key] = (bool) ($data['sections'][$key] ?? false);
        }
        $texts = [];
        foreach ((array) ($data['risk_texts'] ?? []) as $id => $text) {
            if (in_array($id, $riskIds, true)) {
                $texts[$id] = ['title' => trim((string) ($text['title'] ?? '')), 'advice' => trim((string) ($text['advice'] ?? ''))];
            }
        }
        $this->saveDraft($version, [
            'sections' => $sections,
            'documents' => array_values(array_unique((array) ($data['documents'] ?? []))),
            'contact' => filled($data['contact'] ?? null) ? trim((string) $data['contact']) : null,
            'support_until' => $data['support_until'] ?? null,
            'risks' => array_values(array_unique((array) ($data['risks'] ?? []))),
            'risk_texts' => $texts,
            'indexed' => (bool) ($data['indexed'] ?? false),
        ]);

        // "Preview" saves first, then opens the preview, from which the page is published.
        if ($request->input('then') === 'preview') {
            return redirect()->route('versions.public.preview', $version);
        }

        return redirect()->route('versions.public', $version)->with('status', __('public_page.saved'));
    }

    /** Tick the risks at or above one level (the other choices of the draft are kept). */
    public function tickRisks(Request $request, ProductVersion $version): RedirectResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        abort_unless($this->canChoose($this->access($version->product), $version), 404);
        $data = $request->validate(['from' => ['required', 'integer', Rule::in($version->riskLevels()->pluck('rank')->all())]]);

        $draft = PublicSettings::for($version)->toArray();
        $draft['risks'] = PublicSettings::risksFrom($version->riskEntries()->get(['id', 'residual_rank']), (int) $data['from']);
        $this->saveDraft($version, $draft);

        return redirect()->to(route('versions.public', $version).'#risks')->with('status', __('public_page.risks.ticked'));
    }

    /** A document for the page (signed declaration, user manual), added to the version and ticked. */
    public function upload(Request $request, ProductVersion $version, DocumentStore $store): RedirectResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        abort_unless($this->canChoose($this->access($version->product), $version), 404);
        $request->validate([
            'document' => ['required', 'file', 'max:'.DocumentStore::maxKilobytes(), 'extensions:'.implode(',', array_keys(DocumentStore::KINDS))],
            'category' => ['required', Rule::in(self::UPLOAD_CATEGORIES)],
        ]);

        $document = $store->store($version, $request->file('document'), $request->user(), (string) $request->input('category'));
        $draft = PublicSettings::for($version)->toArray();
        $draft['documents'][] = $document->id;
        $draft['sections']['documents'] = true;
        $this->saveDraft($version, $draft);

        return redirect()->to(route('versions.public', $version).'#documents')
            ->with('status', __('public_page.documents.added', ['name' => $document->original_name]));
    }

    /** Publish the draft as it is shown in the preview. */
    public function publish(Request $request, ProductVersion $version): RedirectResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        abort_unless($this->canPublish($this->access($version->product), $version), 404);
        $settings = PublicSettings::for($version);
        if ($settings->missing() !== []) {
            return redirect()->route('versions.public', $version)
                ->with('error', __('public_page.missing', ['fields' => collect($settings->missing())->map(fn ($k) => __('public_page.sections.'.$k))->implode(', ')]));
        }

        PublicCode::for($version->product);
        $stored = (array) ($version->public_page ?? []);
        $stored['draft'] = $settings->toArray();
        $stored['live'] = $settings->toArray() + ['at' => now()->toIso8601String()];
        $version->forceFill([
            'public_page' => $stored,
            'published_at' => $version->published_at ?? now(),
            'published_by' => $request->user()->id,
        ])->save();

        return redirect()->route('versions.public', $version)->with('status', __('public_page.published', ['url' => PublicCode::url($version->product->fresh())]));
    }

    public function unpublish(Request $request, ProductVersion $version): RedirectResponse
    {
        abort_unless(Gate::allows('view', $version), 404);
        abort_unless($this->canChoose($this->access($version->product), $version), 404);
        $stored = (array) ($version->public_page ?? []);
        unset($stored['live']);
        $version->forceFill(['public_page' => $stored, 'published_at' => null, 'published_by' => null])->save();

        return redirect()->route('versions.public', $version)->with('status', __('public_page.unpublished'));
    }

    /** The QR code of the product's public page, SVG or PNG. */
    public function qr(Product $product, string $format): Response
    {
        abort_unless(ProductAccess::for(request()->user(), $product)->canView() && $product->public_code, 404);
        $url = (string) PublicCode::url($product);
        $body = $format === 'png' ? QrCode::png($url) : QrCode::svg($url);
        abort_if($body === null, 404);
        $name = Str::slug($product->name) ?: 'product';

        return response($body, 200, [
            'Content-Type' => $format === 'png' ? 'image/png' : 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$name.'-qr-'.$product->public_code.'.'.$format.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** Printable QR labels of a published version: one kind per sheet, in the language picked. */
    public function labels(Request $request, ProductVersion $version, string $kind, string $lang): View
    {
        abort_unless(Gate::allows('view', $version), 404);
        abort_if($request->user()->is_guest, 404);
        abort_unless($version->published_at !== null && $version->product->public_code, 404);

        return view('public-page.labels', [
            'version' => $version,
            'kind' => $kind,
            'lang' => $lang,
            'url' => (string) PublicCode::url($version->product),
            'grid' => Labels::grid($kind),
            'contents' => Labels::contents($version, $lang),
            'maker' => PublicPageData::for($version, PublicSettings::for($version))->manufacturer()['name'],
        ]);
    }

    /** Choose what the page shows: the owner and the organisation's administrators, any status but in review. */
    private function canChoose(ProductAccess $access, ProductVersion $version): bool
    {
        return $access->canManage() && in_array($version->status, [VersionStatus::Draft, VersionStatus::Approved], true);
    }

    /** Publish: the same people, once the version is approved. */
    private function canPublish(ProductAccess $access, ProductVersion $version): bool
    {
        return $access->canManage() && $version->status === VersionStatus::Approved;
    }

    /** @param  array<string, mixed>  $draft */
    private function saveDraft(ProductVersion $version, array $draft): void
    {
        $stored = (array) ($version->public_page ?? []);
        $stored['draft'] = $draft;
        $version->forceFill(['public_page' => $stored])->save();
    }

    /** An e-mail address or an http(s) web address. */
    private function contactRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (filled($value) && PublicSettings::contactHref(trim((string) $value)) === null) {
                $fail(__('public_page.contact_invalid'));
            }
        };
    }
}
