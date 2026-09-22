<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for Google's Gemini API, used for the AI listing description and the
 * "is this a real room photo?" check.
 */
class GeminiService
{
    public function generateDescription(string $features): string
    {
        return $this->generate([[
            'text' => 'Write a short, engaging, and professional real estate description for a student boarding place '
                ."with the following features: {$features}. Keep it under 2 paragraphs and highlight its appeal to university students.",
        ]]);
    }

    /**
     * @return array{isLegitimate: bool, tags: list<string>}
     */
    public function analyzeRoomImage(UploadedFile $image): array
    {
        $text = $this->generate([
            [
                'text' => 'Analyze this image. Is it a legitimate interior of a room, house, or boarding place? '
                    .'What furniture or amenities are visible? '
                    .'Respond STRICTLY in JSON format with no markdown formatting or extra text. '
                    .'Example: { "isLegitimate": true, "tags": ["Bed", "Desk"] }',
            ],
            [
                'inline_data' => [
                    'mime_type' => $image->getMimeType(),
                    'data' => base64_encode($image->get()),
                ],
            ],
        ]);

        // Models sometimes wrap JSON in a ```json fence despite being told not to.
        $json = trim(str_replace(['```json', '```'], '', $text));
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! array_key_exists('isLegitimate', $decoded)) {
            throw new RuntimeException('Gemini returned an unexpected response.');
        }

        return [
            'isLegitimate' => (bool) $decoded['isLegitimate'],
            'tags' => array_values(array_filter((array) ($decoded['tags'] ?? []), 'is_string')),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     */
    private function generate(array $parts): string
    {
        $key = config('services.gemini.key');

        if (blank($key)) {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        $model = config('services.gemini.model');

        $response = Http::baseUrl(config('services.gemini.base_url'))
            ->withHeaders(['x-goog-api-key' => $key])
            ->timeout(30)
            ->post("/models/{$model}:generateContent", ['contents' => [['parts' => $parts]]])
            ->throw();

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Gemini returned an empty response.');
        }

        return $text;
    }
}
