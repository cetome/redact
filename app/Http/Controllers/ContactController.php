<?php

namespace App\Http\Controllers;

use App\Auth\ClientKey;
use App\Auth\ContactAltcha;
use App\Http\Middleware\LogRequest;
use App\Jobs\Contact\SendContactMessage;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The contact form (/contact), after cetome.com's (/api/contact/): the same fields plus a topic, the
 * same defences in the same order.
 *   1. Origin: Laravel's CSRF token (a page on another site cannot post).
 *   2. Sends per IP (every attempt counts), refused with a message.
 *   3. Honeypot ("website"): filled by naive bots, answered as a success, nothing kept or sent.
 *   4. Validation; single-line values lose their control characters first (no header injection).
 *   5. ALTCHA proof of work, solved invisibly on page load, its own keys (ContactAltcha), spent once.
 *   6. Messages per e-mail address.
 * The message is stored (staff console, Contact) and mailed from a queued job (SendContactMessage):
 * the visitor never waits on the mail server.
 */
class ContactController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        // Older links (/contact?topic=privacy) still arrive: on to the address of that topic.
        $legacy = $request->query('topic');
        if ($request->route('topic') === null && is_string($legacy) && in_array($legacy, ContactMessage::TOPICS, true)) {
            return redirect()->route('contact.topic', $legacy, 301);
        }
        $user = $this->account($request);
        $topic = (string) $request->route('topic', '');

        return view('public.contact', [
            'topics' => ContactMessage::TOPICS,
            'topic' => in_array($topic, ContactMessage::TOPICS, true) ? $topic : '',
            'name' => $user->name ?? '',
            'email' => $user->email ?? '',
            'sent' => (bool) $request->session()->get('contact_sent', false),
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@type' => 'ContactPage',
                'name' => __('contact.meta.title'),
                'url' => route('contact'),
                'description' => __('contact.meta.description'),
                'inLanguage' => app()->getLocale(),
                'publisher' => ['@type' => 'Organization', 'name' => 'cetome', 'url' => 'https://cetome.com/'],
            ],
        ]);
    }

    public function send(Request $request, ContactAltcha $altcha): RedirectResponse
    {
        $limits = (array) config('redact.contact');

        $ipKey = 'contact-ip:'.ClientKey::ip($request);
        if (RateLimiter::tooManyAttempts($ipKey, max(1, (int) ($limits['per_ip'] ?? 10)))) {
            return $this->refuse('form', __('contact.errors.throttled'));
        }
        RateLimiter::hit($ipKey, 60 * max(1, (int) ($limits['per_ip_minutes'] ?? 15)));

        // Real browsers never fill it: looks like success, nothing is kept or sent.
        if (filled($request->input('website'))) {
            return $this->sent();
        }

        $request->merge([
            'name' => self::line($request->input('name'), 100),
            'email' => self::line($request->input('email'), 150),
            'company' => self::line($request->input('company'), 150),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:#(https?://|www\.)#i'],
            'email' => ['required', 'string', 'max:150', 'email:rfc'],
            'company' => ['nullable', 'string', 'max:150'],
            'topic' => ['required', 'string', Rule::in(ContactMessage::TOPICS)],
            'message' => ['required', 'string', 'max:5000'],
            'altcha' => ['nullable', 'string'],
        ], [
            'name.not_regex' => __('contact.errors.name_link'),
            'topic.required' => __('contact.errors.topic'),
            'topic.in' => __('contact.errors.topic'),
        ]);

        if (! $altcha->verify($data['altcha'] ?? null)) {
            return $this->refuse('altcha', __(blank($data['altcha'] ?? null) ? 'contact.errors.altcha_missing' : 'contact.errors.altcha_failed'));
        }

        // cetome.com's heuristic: a message that only repeats the name is a bot.
        $message = trim((string) $data['message']);
        if ($message === $data['name']) {
            return $this->sent();
        }

        $emailKey = 'contact-email:'.ClientKey::email($data['email']);
        if (RateLimiter::tooManyAttempts($emailKey, max(1, (int) ($limits['per_email'] ?? 3)))) {
            return $this->refuse('form', __('contact.errors.throttled'));
        }
        RateLimiter::hit($emailKey, 60 * max(1, (int) ($limits['per_email_minutes'] ?? 60)));

        $user = $this->account($request);
        $stored = ContactMessage::create([
            'user_id' => $user?->getKey(),
            'name' => $data['name'],
            'email' => $data['email'],
            'company' => $data['company'] !== '' ? $data['company'] : null,
            'topic' => $data['topic'],
            'message' => $message,
            'locale' => substr(app()->getLocale(), 0, 8),
            'ip_hash' => LogRequest::hashIp($request->ip()),
            'user_agent' => self::line($request->userAgent(), 255) ?: null,
        ]);

        SendContactMessage::dispatch($stored->id);

        return $this->sent();
    }

    /** A fresh proof-of-work challenge for the contact form's invisible ALTCHA. */
    public function challenge(ContactAltcha $altcha): JsonResponse
    {
        return response()->json($altcha->challenge())->header('Cache-Control', 'no-store');
    }

    /**
     * One line: control characters (CR, LF, tab, NUL...) become spaces, runs of spaces one, trimmed
     * and cut to $max characters. What reaches a mail header can therefore never start a new one.
     */
    public static function line(mixed $value, int $max): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = (string) preg_replace('/[\x00-\x1F\x7F\x{2028}\x{2029}]+/u', ' ', $value);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $max);
    }

    /** A signed-in account (not a sandbox guest, whose address is a reserved one). */
    private function account(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User && ! $user->is_guest ? $user : null;
    }

    private function sent(): RedirectResponse
    {
        return redirect()->route('contact')->with('contact_sent', true);
    }

    private function refuse(string $field, string $message): RedirectResponse
    {
        return back()->withInput()->withErrors([$field => $message]);
    }
}
