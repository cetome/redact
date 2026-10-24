# AI: bring your own key and model

REDact's AI assistance (draft from documents, improve a text, scoping suggestion, consistency
check of a walk, suggested tree answers) works with the provider and model **you** choose, on
**your** API key. AI output is always marked and never applied without a person accepting it; the
AI never gives a verdict.

Supported providers (through [Prism](https://prismphp.com)):

| `REDACT_AI_PROVIDER` | Provider | Key variable | Suggested models (free text, any id the provider serves) |
|---|---|---|---|
| `anthropic` | Claude (Anthropic) | `ANTHROPIC_API_KEY` | `claude-sonnet-5-5` (default), `claude-opus-5-5`, `claude-haiku-5-5` |
| `openai` | ChatGPT (OpenAI, Responses API) | `OPENAI_API_KEY` | `gpt-5-mini` (default), `gpt-6.1-sol`, `gpt-5.5` |
| `mistral` | Mistral | `MISTRAL_API_KEY` | `mistral-medium-latest` (default), `mistral-large-latest` |
| `gemini` | Gemini (Google) | `GEMINI_API_KEY` | `gemini-3.5-flash-lite` (default), `gemini-3-flash-preview` |
| `openai_compatible` | Any OpenAI-compatible chat-completions API or gateway (vLLM, LiteLLM, Ollama, OpenRouter, a corporate gateway…) | `REDACT_AI_API_KEY` | none: set the model id the API serves |

The suggested ids are defaults only: providers publish new models often, type any id they serve.

## Option 1: the .env file

```dotenv
REDACT_AI_ENABLED=true
REDACT_AI_PROVIDER=anthropic
REDACT_AI_MODEL=                      # empty: the suggested default of the provider
ANTHROPIC_API_KEY=sk-ant-...
```

An OpenAI-compatible API:

```dotenv
REDACT_AI_PROVIDER=openai_compatible
REDACT_AI_BASE_URL=https://llm.example.com/v1   # the API base, before /chat/completions
REDACT_AI_MODEL=my-model
REDACT_AI_API_KEY=...
```

`REDACT_AI_BASE_URL` also works with the other providers, to go through a gateway or proxy.
`REDACT_AI_FALLBACK_MODELS` (comma-separated) lists models tried in turn when a call fails.

## Option 2: the console (no file to edit)

Make yourself super-admin (`php artisan redact:make-staff you@example.com --super`), sign in with a
passkey, then open **Console → AI settings** (`/admin/ai`):

- choose the provider (Claude, ChatGPT, Gemini or an OpenAI-compatible API), the model id (with
  suggestions), the base URL when needed, and paste the API key;
- the key is stored **encrypted** with the application key (`APP_KEY`, Laravel's encrypter) and is
  never shown again: the page only shows its last 4 characters. Leave the field empty to keep it;
- **Test connection** sends one short request with the settings in use and shows the model's
  answer time or the provider's error (the key is never part of the message);
- settings saved here override the .env values; **Back to the .env values** removes them.

Staff without the super-admin role see the page read-only. Every change and test is in the
console's activity log, never the key. If `APP_KEY` changes, the stored key can no longer be read:
paste it again.

## Option 3: each organisation brings its own key or model

Every organisation has a **Settings** tab (next to Team and History) with an **AI model** card, as in
cetome's CRAted. Its administrators choose what answers the organisation's AI buttons; members read
what answers them and change nothing:

- **the default of this install** (options 1 or 2 above, or none);
- **Google Gemini**, **Anthropic Claude**, **OpenAI ChatGPT** or **Mistral** with the
  organisation's own API key (default models `gemini-3.5-flash-lite`, `claude-sonnet-5-5`,
  `gpt-5-mini`, `mistral-medium-latest`, or any model id typed);
- **its own endpoint** speaking the OpenAI chat protocol (Ollama, vLLM, LM Studio, Azure OpenAI, a
  gateway): base URL, model name, an optional key and a "reads pictures" switch (REDact sends the
  text of documents; pictures go only to models that read them).

The key is stored encrypted, never shown again (last 4 characters only), and serves only the
provider it was given for: switching provider needs that provider's key. **Test connection** sends
one short request. A call answered by the organisation's own model is logged in `ai_usage` as
`own:<choice>` and never consumes plan units. Every change is in the activity log, never the key.

Who may bring their own model: every organisation of a self-hosted install. On cetome's hosted
service, paid and Enterprise organisations, who choose between cetome's AI (500 units a month per
paid product) and their own key or model. The free plan has no AI at all, not even with an own key,
and when a subscription ends with no paid or granted product left, the choice is kept but the AI
stops like the other paid features.

Own endpoints are called without following redirects, with connect and answer timeouts. On the
hosted service (not self-hosted) they must be `https` and public: loopback, private, link-local,
carrier-grade NAT and reserved addresses are refused when saved and again before each call, and
the call is pinned to the address checked. A self-hosted install also accepts `http` and local
addresses (`http://localhost:11434/v1` for Ollama).

## Who may use the AI

On a self-hosted install the AI is **not limited by plans**: every member of an organisation that
has the AI switched on may use it, within the per-person quotas (`REDACT_AI_UNITS_PER_DAY`,
`REDACT_AI_UNITS_PER_10_MIN`) and any AI override staff set on an organisation in the console.
An install is self-hosted when billing (Stripe) is not configured, or when `REDACT_SELF_HOSTED=true`.
Guests of the sandbox never get the AI. Each call is logged with its units in `ai_usage`
(Console → Usage). Whatever answers (the install's default or an organisation's own model), the
model receives the same instructions, material and output schema, so results are judged by the
same rules.

## The instructions the model receives

The wording of the AI instructions comes from the content pack (`ai/prompts/*.md`, see
[CONTENT-PACK.md](CONTENT-PACK.md#13-aiprompts)). The open demo pack and any pack without them use
the engine's **generic** wording: it works, but it knows nothing of a particular assessment
method. cetome's own instructions are part of its private content pack and are not in this
repository. Whatever the pack says, the engine always adds its guardrails (no verdict, only the
material given, documents treated as untrusted, JSON only).

## Privacy

Product descriptions, inventory data and the text of uploaded documents are sent to the provider
you configure when a person asks for AI help. Choose a provider and a contract (data retention,
region) that suit your organisation, or a self-hosted model behind `openai_compatible`.
