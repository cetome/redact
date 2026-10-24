<?php

namespace App\Http\Controllers\Admin;

use App\Models\ContactMessage;
use App\Support\PathPaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Contact form messages (/contact), read-only: the list, newest first, and one message. Replies go
 * from the cetome mailbox (the mail's Reply-To is the visitor). Deleted after
 * config('redact.retention.contact_messages_days') by redact:prune-tracking.
 * Filters in the session (POST …/filter), pages /admin/contact/page/{n}.
 */
class ContactMessageController extends AdminController
{
    private const FILTERS = 'admin.contact';

    public function index(Request $request, int $page = 1): View
    {
        $filters = $this->rememberedFilters($request, self::FILTERS, $this->rules());
        $q = trim((string) ($filters['q'] ?? ''));

        $messages = ContactMessage::query()
            ->with('user:id,email')
            ->when($filters['topic'] ?? null, fn ($query, $topic) => $query->where('topic', $topic))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', '%'.$q.'%')
                ->orWhere('email', 'like', '%'.$q.'%')
                ->orWhere('company', 'like', '%'.$q.'%')))
            ->latest()->orderByDesc('id');

        return view('admin.contact.index', [
            'messages' => PathPaginator::of($messages, 50, $page, route('admin.contact.index')),
            'topic' => $filters['topic'] ?? null,
            'q' => $q,
            'retention' => (int) config('redact.retention.contact_messages_days', 365),
        ]);
    }

    public function filter(Request $request): RedirectResponse
    {
        return $this->rememberFilters($request, self::FILTERS, $this->rules(), route('admin.contact.index'));
    }

    public function show(ContactMessage $message): View
    {
        $message->load('user');

        return view('admin.contact.show', ['message' => $message]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'topic' => ['nullable', 'string', Rule::in(ContactMessage::TOPICS)],
            'q' => ['nullable', 'string', 'max:120'],
        ];
    }
}
