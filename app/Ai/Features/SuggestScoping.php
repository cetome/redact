<?php

namespace App\Ai\Features;

use App\Ai\AiClient;
use App\Ai\AiException;
use App\Ai\AiResult;
use App\Ai\Feature;
use App\Ai\Prompts\SuggestScopingPrompt;
use App\Ai\Provenance;
use App\Ai\Suggestions;
use App\Assessment\ScopingForm;
use App\Content\ContentPack;
use App\Documents\DocumentText;
use App\Enums\Origin;
use App\Models\AiSuggestion;
use App\Models\FieldOrigin;
use App\Models\Organisation;
use App\Models\ProductVersion;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * "Suggest with AI" on the scoping (New product and the scoping step): which scoping
 * questions look true for the product, each with a one-sentence reason and a confidence.
 * The AI only proposes: the browser shows the proposal on the cards and a person applies
 * it, changes it, or ignores it.
 *
 * The proposal travels with the form as an encrypted, signed token (the product may not
 * exist yet). On save, a valid token means "applied": every suggested answer is marked
 * "AI Generated" in field_origins (type scoping_answer, key = version id, field = question
 * key, hash of "true"/"false"), so an answer a person changed shows "Human Edited", and an
 * answer never applied stays the person's ("Human").
 */
class SuggestScoping
{
    /** Origin marks of scoping answers. */
    public const TARGET = 'scoping_answer';

    /** A token older than this is ignored (the page was left open too long). */
    public const MAX_AGE = 86400;

    public function __construct(
        private readonly AiClient $client,
        private readonly ScopingForm $form,
        private readonly ContentPack $pack,
    ) {}

    /** @return array<string, array<string, mixed>> the boolean questions of the scoping step */
    public function questions(): array
    {
        return array_filter($this->form->questions(ScopingForm::SCOPING), fn (array $q) => ($q['type'] ?? 'boolean') === 'boolean');
    }

    /**
     * New product: from what the person typed, for a product of this organisation.
     *
     * @return array<string, mixed> what the browser needs (see present())
     */
    public function forNew(User $user, Organisation $organisation, string $name, string $description): array
    {
        $text = trim($name."\n".$description);
        if (trim($description) === '') {
            throw AiException::invalid(__('scoping.ai.empty_new'));
        }

        [$answers, $result] = $this->ask($user, $organisation, $text, '');

        return $this->present($answers, $this->token($user, null, $organisation->getKey(), null, $answers, $result));
    }

    /**
     * Scoping step: from the product description, the intended use and the documents.
     *
     * @return array<string, mixed>
     */
    public function forVersion(User $user, ProductVersion $version): array
    {
        $product = $version->product;
        $documents = DocumentText::forPrompt($version, 60_000);
        if (trim((string) $product->description) === '' && trim((string) $version->intended_use) === '' && $documents === '') {
            throw AiException::invalid(__('scoping.ai.empty_version'));
        }
        $text = trim($product->name."\n".$product->description."\n".$version->intended_use);

        [$answers, $result] = $this->ask($user, $version, $text, $documents);

        Suggestions::supersede($version, Feature::SuggestScoping);
        $suggestion = Suggestions::create($version, Feature::SuggestScoping, 'scoping', $version->getKey(), 'answers',
            Origin::AiGenerated->value, ['answers' => $answers], $result, $user);

        return $this->present($answers, $this->token($user, $version->getKey(), null, $suggestion->getKey(), $answers, $result));
    }

    /**
     * The AI suggestions of an applied token, recorded on a saved version: marks each
     * suggested answer "AI Generated" and accepts the suggestion. A missing, foreign,
     * tampered or stale token records nothing (the answers stay the person's).
     *
     * @param  string|null  $organisationId  for a new product: the organisation the token was issued for
     * @return int number of answers marked
     */
    public function record(ProductVersion $version, ?string $token, User $user, ?string $organisationId = null): int
    {
        $data = $this->redeem($token, $user, $organisationId === null ? $version->getKey() : null, $organisationId);
        if ($data === null || $data['answers'] === []) {
            return 0;
        }

        return DB::transaction(function () use ($version, $data, $user) {
            $suggestion = null;
            if ($data['sid'] !== null) {
                $suggestion = AiSuggestion::query()->whereKey($data['sid'])
                    ->where('product_version_id', $version->getKey())
                    ->where('feature', Feature::SuggestScoping->value)->first();
            }
            if ($suggestion === null) {
                // New product: the suggestion was made before the version existed.
                $suggestion = AiSuggestion::create([
                    'product_version_id' => $version->getKey(),
                    'feature' => Feature::SuggestScoping->value,
                    'target_type' => 'scoping',
                    'target_key' => $version->getKey(),
                    'field' => 'answers',
                    'origin' => Origin::AiGenerated->value,
                    'payload' => ['answers' => $data['answers']],
                    'status' => 'pending',
                    'provider' => mb_substr($data['provider'], 0, 32),
                    'model' => mb_substr($data['model'], 0, 80),
                    'requested_by' => $user->getKey(),
                ]);
            }

            $provenance = app(Provenance::class);
            foreach ($data['answers'] as $key => $answer) {
                $provenance->mark(self::TARGET, $version->getKey(), $key, Origin::AiGenerated,
                    self::value($answer['applies']), $suggestion, $user);
            }

            if ($suggestion->isPending()) {
                $suggestion->status = 'accepted';
                $suggestion->forceFill(['decided_by' => $user->getKey(), 'decided_at' => now()])->save();
            }

            activity('ai')->causedBy($user)->performedOn($suggestion)->withProperties([
                'feature' => Feature::SuggestScoping->value, 'target_type' => 'scoping', 'target_key' => $version->getKey(),
                'answers' => count($data['answers']),
            ])->log('suggestion accepted');

            return count($data['answers']);
        });
    }

    /**
     * The AI value each saved answer was given when applied (question key => true/false),
     * for the answers that carry an AI mark. The page compares it with the card's state.
     *
     * @return array<string, bool>
     */
    public function recorded(ProductVersion $version): array
    {
        return FieldOrigin::query()->where('target_type', self::TARGET)->where('target_key', $version->getKey())
            ->where('origin', Origin::AiGenerated->value)->get(['field', 'value_hash'])
            ->mapWithKeys(fn (FieldOrigin $o) => [$o->field => $o->value_hash === FieldOrigin::hashValue(self::value(true))])
            ->all();
    }

    /** How a boolean answer is stored for its origin mark. */
    public static function value(bool $answer): string
    {
        return $answer ? 'true' : 'false';
    }

    /**
     * Ask the model and keep only known question keys, once each, with a boolean answer.
     *
     * @return array{0: array<string, array{applies: bool, reason: string, confidence: string}>, 1: AiResult}
     */
    private function ask(User $user, ProductVersion|Organisation $scope, string $text, string $documents): array
    {
        $questions = $this->questions();
        if ($questions === []) {
            throw AiException::invalid(__('scoping.ai.no_questions'));
        }

        $result = $this->client->run($user, $scope, SuggestScopingPrompt::make($questions, $text, $documents));

        return [self::clean($result->data, array_map('strval', array_keys($questions))), $result];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, array{applies: bool, reason: string, confidence: string}>
     */
    private static function clean(array $data, array $keys): array
    {
        $answers = [];
        foreach (is_array($data['answers'] ?? null) ? $data['answers'] : [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $key = (string) ($raw['key'] ?? '');
            $applies = $raw['applies'] ?? null;
            if (! in_array($key, $keys, true) || isset($answers[$key]) || ! is_bool($applies)) {
                continue;
            }
            $confidence = (string) ($raw['confidence'] ?? '');
            $answers[$key] = [
                'applies' => $applies,
                'reason' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($raw['reason'] ?? '')) ?? ''), 0, 300),
                'confidence' => in_array($confidence, SuggestScopingPrompt::CONFIDENCE, true) ? $confidence : 'low',
            ];
        }

        // In the questionnaire's order.
        return array_replace(array_intersect_key(array_flip($keys), $answers), $answers);
    }

    /**
     * @param  array<string, array{applies: bool, reason: string, confidence: string}>  $answers
     * @return array<string, mixed>
     */
    private function present(array $answers, string $token): array
    {
        $result = $this->pack->rules()->scoping(array_map(fn (array $a) => $a['applies'], $answers), $this->pack);
        $names = $this->pack->articles();
        $articles = [];
        foreach ($result->articles as $code => $in) {
            if ($in) {
                $articles[] = __('trees.article', ['article' => $code]).(isset($names[$code]) ? ' · '.$names[$code] : '');
            }
        }
        $standards = array_map(fn (string $code) => $this->pack->standardName($code), $result->standards);
        $yes = count(array_filter($answers, fn (array $a) => $a['applies']));

        $suggestions = [];
        foreach ($answers as $key => $answer) {
            $suggestions[$key] = $answer + ['confidence_label' => __('scoping.ai.confidence.'.$answer['confidence'])];
        }

        return [
            'token' => $token,
            'suggestions' => $suggestions,
            'articles' => $articles,
            'standards' => $standards,
            'message' => trans_choice('scoping.ai.result', $yes, ['count' => $yes, 'total' => count($answers)])
                .' '.($standards === [] ? __('scoping.ai.result_none') : __('scoping.ai.result_standards', ['standards' => implode(', ', $standards)])),
        ];
    }

    /**
     * @param  array<string, array{applies: bool, reason: string, confidence: string}>  $answers
     */
    private function token(User $user, ?string $versionId, ?string $organisationId, ?string $suggestionId, array $answers, AiResult $result): string
    {
        return Crypt::encryptString((string) json_encode([
            'u' => $user->getKey(),
            'v' => $versionId,
            'o' => $organisationId,
            'sid' => $suggestionId,
            'a' => $answers,
            'p' => $result->provider,
            'm' => $result->model,
            't' => now()->getTimestamp(),
        ]));
    }

    /**
     * Decrypt and check a token: same person, same version (or organisation for a new
     * product), not stale; answers re-filtered against today's questions.
     *
     * @return array{answers: array<string, array{applies: bool, reason: string, confidence: string}>, sid: ?string, provider: string, model: string}|null
     */
    private function redeem(?string $token, User $user, ?string $versionId, ?string $organisationId): ?array
    {
        if ($token === null || $token === '' || strlen($token) > 20000) {
            return null;
        }
        try {
            $data = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }
        if (! is_array($data)
            || ($data['u'] ?? null) !== $user->getKey()
            || ($data['v'] ?? null) !== $versionId
            || ($data['o'] ?? null) !== $organisationId
            || ! is_int($data['t'] ?? null) || $data['t'] < now()->getTimestamp() - self::MAX_AGE) {
            return null;
        }

        // Re-checked against today's questionnaire, as if the model had just answered.
        $raw = [];
        foreach (is_array($data['a'] ?? null) ? $data['a'] : [] as $key => $answer) {
            if (is_array($answer)) {
                $raw[] = ['key' => (string) $key] + $answer;
            }
        }

        return [
            'answers' => self::clean(['answers' => $raw], array_map('strval', array_keys($this->questions()))),
            'sid' => is_string($data['sid'] ?? null) ? $data['sid'] : null,
            'provider' => (string) ($data['p'] ?? ''),
            'model' => (string) ($data['m'] ?? ''),
        ];
    }
}
