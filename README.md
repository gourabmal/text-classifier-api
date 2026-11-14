# Text Classifier API

A small Laravel REST service that uses **Google Gemini** to classify a piece of text as
**Complaint**, **Query**, **Feedback** or **Other**. It returns the category and a confidence score.

- Stack: PHP 8.2+ (tested on 8.3), Laravel 13, Gemini `generateContent` REST API
- One endpoint: `POST /api/classify`
- No database required

---

## Quick start

```bash
git clone <your-repo-url> text-classifier-api
cd text-classifier-api
composer install
cp .env.example .env          # Windows PowerShell: Copy-Item .env.example .env
php artisan key:generate
```

Open `.env` and set your Gemini key (free from https://aistudio.google.com/apikey):

```dotenv
GEMINI_API_KEY=your-key-here
GEMINI_MODEL=gemini-2.5-flash   # any Gemini model that supports structured output
GEMINI_TIMEOUT=15               # seconds
```

Run it:

```bash
php artisan serve               # http://127.0.0.1:8000
```

Run the tests (no API key or network needed, because Gemini is faked):

```bash
php artisan test
```

---

## API

### `POST /api/classify`

**Request**

```bash
curl -X POST http://127.0.0.1:8000/api/classify \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"text": "I was charged twice for my order and support has not replied in a week."}'
```

| Field  | Type   | Rules                         |
|--------|--------|-------------------------------|
| `text` | string | required, 1–5000 characters  |

**Response `200 OK`**

```json
{
  "category": "Complaint",
  "confidence": 0.95,
  "confidence_source": "model",
  "model": "gemini-2.5-flash",
  "latency_ms": 842
}
```

| Field               | Meaning                                                                 |
|---------------------|-------------------------------------------------------------------------|
| `category`          | One of `Complaint`, `Query`, `Feedback`, `Other`                       |
| `confidence`        | 0.0 – 1.0, rounded to 2 decimals                                        |
| `confidence_source` | `model` = score from the AI; `fallback` = our own mapping (see below)  |
| `model`             | Model version that answered                                            |
| `latency_ms`        | Time spent waiting for the AI call                                     |

More examples:

| Input                                                   | Category |
|---------------------------------------------------------|----------|
| "Do you deliver to Pune, and what are the charges?"     | Query    |
| "The new checkout is really smooth, great job!"         | Feedback |
| "hello there"                                           | Other    |

### Errors

Every error uses the same shape:

```json
{ "error": { "code": "validation_failed", "message": "The text field is required.", "details": { "text": ["The text field is required."] } } }
```

| Status | `code`                | When                                                         |
|--------|-----------------------|--------------------------------------------------------------|
| 422    | `validation_failed`   | `text` missing, empty, not a string, or longer than 5000 chars |
| 405    | `method_not_allowed`  | Anything other than POST                                     |
| 429    | `too_many_requests`   | More than 30 requests/minute per client                      |
| 500    | `ai_not_configured`   | `GEMINI_API_KEY` not set                                     |
| 502    | `ai_upstream_error`   | Gemini returned an HTTP error (bad key, quota, outage)       |
| 502    | `ai_invalid_response` | Gemini's output could not be parsed, or the input was blocked |
| 504    | `ai_timeout`          | Gemini did not respond within `GEMINI_TIMEOUT`               |

A Postman collection is in [`postman/TextClassifier.postman_collection.json`](postman/TextClassifier.postman_collection.json).
Import it and set the `baseUrl` variable if you are not on `http://127.0.0.1:8000`.

---

## Project structure

```
app/
├── Http/
│   ├── Controllers/ClassificationController.php   # thin: validate → service → JSON
│   └── Requests/ClassifyTextRequest.php           # input validation rules
├── Services/
│   ├── ClassificationService.php                  # business logic: normalise category & confidence, log
│   └── Ai/
│       ├── GeminiClassifier.php                   # HTTP call to Gemini + response parsing
│       └── ClassificationPrompt.php               # system prompt, user message, JSON schema
├── Contracts/TextClassifier.php                   # interface implemented by any AI provider
├── Data/AiClassification.php                      # raw AI output (untrusted)
├── Data/ClassificationResult.php                  # final API result
├── Enums/Category.php                             # allowed categories + fallback confidence
└── Exceptions/AiServiceException.php              # AI failures → JSON 5xx
bootstrap/app.php                                  # API routing + consistent JSON error rendering
config/services.php                                # gemini.key / model / timeout (from .env)
routes/api.php                                     # POST /api/classify
tests/Feature/ClassifyTextTest.php                 # endpoint tests with Gemini faked
```

**Request flow:** `routes/api.php` → `ClassificationController` → `ClassifyTextRequest` (validation)
→ `ClassificationService` → `TextClassifier` interface → `GeminiClassifier` → Gemini API.

The controller only knows about the service, and the service only knows about the `TextClassifier` interface.
To use a different provider (OpenAI, Claude, a local model), write a new class that implements the
interface and change one binding in `AppServiceProvider`.

---

## How AI is used

### 1. Calling the model
`GeminiClassifier` calls `POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`.
The API key goes in the `x-goog-api-key` header, so it never appears in URLs or logs.

### 2. Prompt design (`ClassificationPrompt.php`)
- **System instruction** defines each category in a single line and includes rules for edge cases. For example,
  "a question asked because something is broken is a *Complaint*", and "if intents are mixed, pick the dominant one".
- **Few-shot examples**: one short example per category to anchor the boundaries.
- **Prompt-injection guard**: the user's text is wrapped in `<message>` tags, and the model is told to treat it as data,
  never as instructions.
- **`temperature: 0`** so the same input gives the same answer every time.

### 3. Structured output instead of free text
The request sets `responseMimeType: application/json` and a `responseSchema` in which `category` is an
**enum of the four allowed values** and `confidence` is a number. Gemini therefore returns JSON such as
`{"category":"Query","confidence":0.88}`, so the service doesn't need regex or string matching to read the answer.

### 4. Confidence: model score + our own fallback mapping
The model is asked for its probability (0–1) that the label is correct. Because LLM output can't be fully trusted,
`ClassificationService` checks it:

| Situation                                        | What we do                                        |
|--------------------------------------------------|---------------------------------------------------|
| Score is 0–1                                     | Use it, rounded to 2 dp (`confidence_source: model`) |
| Score looks like a percentage (e.g. `87`)        | Convert to `0.87`                                 |
| Score is out of range                            | Clamp to 0–1                                      |
| Score missing / not a number                     | Fallback mapping: Complaint/Query/Feedback → **0.70**, Other → **0.50** (`confidence_source: fallback`) |
| Category not one of the four                     | Map to **Other** with fallback confidence 0.50    |

### 5. Observability
Each classification is logged to `storage/logs/laravel.log` with category, confidence, model, latency and
text length. The text itself is **not** logged, to avoid storing customer data.

### AI tools used during development
An AI coding assistant was used to scaffold the project structure, draft the tests and write this README.
The prompt, schema and fallback rules were reviewed and adjusted by hand, and the tests cover every branch.

---

## Design notes & trade-offs

- **No SDK**: Gemini is called with Laravel's HTTP client. That keeps dependencies minimal and lets tests fake it with `Http::fake()`.
- **No database**: sessions and cache use files, and queues run synchronously, so setup is just `composer install`.
- **Rate limiting**: `throttle:30,1` protects your Gemini quota.
- **Possible next steps**: caching identical inputs, batch classification, and API-key auth for clients.
