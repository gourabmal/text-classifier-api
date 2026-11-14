<?php

namespace App\Providers;

use App\Contracts\TextClassifier;
use App\Services\Ai\GeminiClassifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Swap the AI provider here without touching the controller or service.
        $this->app->bind(TextClassifier::class, fn () => new GeminiClassifier(
            apiKey: (string) config('services.gemini.key'),
            model: (string) config('services.gemini.model'),
            timeoutSeconds: (int) config('services.gemini.timeout'),
            baseUrl: (string) config('services.gemini.base_url'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
