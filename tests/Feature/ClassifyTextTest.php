<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClassifyTextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-key',
            'services.gemini.model' => 'gemini-test',
        ]);

        Http::preventStrayRequests();
    }

    /**
     * Fake a Gemini generateContent response whose text part is $payload.
     */
    private function geminiReturns(array|string $payload, int $status = 200): void
    {
        $text = is_array($payload) ? json_encode($payload) : $payload;

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => $text]]],
                    'finishReason' => 'STOP',
                ]],
                'modelVersion' => 'gemini-test',
            ], $status),
        ]);
    }

    public function test_it_classifies_text_using_the_ai_model(): void
    {
        $this->geminiReturns(['category' => 'Complaint', 'confidence' => 0.93]);

        $response = $this->postJson('/api/classify', ['text' => 'My order arrived broken and nobody answers my emails.'])
            ->assertOk()
            ->assertJsonStructure(['category', 'confidence', 'confidence_source', 'model', 'latency_ms'])
            ->assertJson([
                'category' => 'Complaint',
                'confidence' => 0.93,
                'confidence_source' => 'model',
                'model' => 'gemini-test',
            ]);

        $this->assertIsInt($response->json('latency_ms'));

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/models/gemini-test:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && str_contains($request['contents'][0]['parts'][0]['text'], 'My order arrived broken')
                && $request['generationConfig']['responseMimeType'] === 'application/json'
                && $request['generationConfig']['responseSchema']['properties']['category']['enum'] === ['Complaint', 'Query', 'Feedback', 'Other'];
        });
    }

    public function test_it_uses_fallback_confidence_when_the_model_omits_it(): void
    {
        $this->geminiReturns(['category' => 'Query']);

        $this->postJson('/api/classify', ['text' => 'Do you ship to Canada?'])
            ->assertOk()
            ->assertJson(['category' => 'Query', 'confidence' => 0.7, 'confidence_source' => 'fallback']);
    }

    public function test_unknown_category_is_mapped_to_other(): void
    {
        $this->geminiReturns(['category' => 'Spam', 'confidence' => 0.99]);

        $this->postJson('/api/classify', ['text' => 'Buy cheap watches now!!!'])
            ->assertOk()
            ->assertJson(['category' => 'Other', 'confidence' => 0.5, 'confidence_source' => 'fallback']);
    }

    public function test_category_matching_is_case_insensitive(): void
    {
        $this->geminiReturns(['category' => 'feedback', 'confidence' => 0.8]);

        $this->postJson('/api/classify', ['text' => 'Love the new design!'])
            ->assertOk()
            ->assertJson(['category' => 'Feedback', 'confidence' => 0.8]);
    }

    #[DataProvider('confidenceCases')]
    public function test_confidence_is_normalised_into_zero_to_one(mixed $raw, float $expected): void
    {
        $this->geminiReturns(['category' => 'Feedback', 'confidence' => $raw]);

        $response = $this->postJson('/api/classify', ['text' => 'Nice app.'])->assertOk();

        $this->assertEqualsWithDelta($expected, $response->json('confidence'), 0.0001);
    }

    public static function confidenceCases(): array
    {
        return [
            'percent value' => [87, 0.87],
            'negative' => [-3, 0.0],
            'above 100' => [250, 1.0],
            'numeric string' => ['0.66', 0.66],
            'rounded to 2dp' => [0.91234, 0.91],
        ];
    }

    public function test_text_is_required(): void
    {
        $this->postJson('/api/classify', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrors('text', 'error.details');

        $this->postJson('/api/classify', ['text' => '   '])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_text_must_be_a_string(): void
    {
        $this->postJson('/api/classify', ['text' => ['not', 'a', 'string']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_text_has_a_maximum_length(): void
    {
        $this->postJson('/api/classify', ['text' => str_repeat('a', 5001)])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_provider_errors_return_502(): void
    {
        $this->geminiReturns(['error' => 'boom'], 500);

        $this->postJson('/api/classify', ['text' => 'Hello'])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'ai_upstream_error');
    }

    public function test_unparseable_model_output_returns_502(): void
    {
        $this->geminiReturns('I think this is a complaint.');

        $this->postJson('/api/classify', ['text' => 'Hello'])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'ai_invalid_response');
    }

    public function test_blocked_input_returns_502(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'promptFeedback' => ['blockReason' => 'SAFETY'],
        ])]);

        $this->postJson('/api/classify', ['text' => 'Hello'])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'ai_invalid_response');
    }

    public function test_timeouts_return_504(): void
    {
        Http::fake(fn () => throw new ConnectionException('Operation timed out'));

        $this->postJson('/api/classify', ['text' => 'Hello'])
            ->assertStatus(504)
            ->assertJsonPath('error.code', 'ai_timeout');
    }

    public function test_missing_api_key_returns_500(): void
    {
        config(['services.gemini.key' => '']);

        $this->postJson('/api/classify', ['text' => 'Hello'])
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'ai_not_configured');

        Http::assertNothingSent();
    }

    public function test_only_post_is_allowed(): void
    {
        $this->getJson('/api/classify')
            ->assertStatus(405)
            ->assertJsonPath('error.code', 'method_not_allowed');
    }
}
