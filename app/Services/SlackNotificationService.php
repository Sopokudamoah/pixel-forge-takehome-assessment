<?php

namespace App\Services;

use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SlackNotificationService
{
    /**
     * Send notification when an image generation is initiated/queued.
     */
    public function sendGenerationStarted(Generation $generation): bool
    {
        $user = $generation->user;
        $userName = $user ? "{$user->name} ({$user->email})" : 'User #' . $generation->user_id;
        $refImagesCount = is_array($generation->reference_images) ? count($generation->reference_images) : 0;

        $promptExcerpt = mb_strlen($generation->prompt) > 300 
            ? mb_substr($generation->prompt, 0, 297) . '...' 
            : $generation->prompt;

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => '🎨 New Image Generation Queued',
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*User:*\n{$userName}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Settings:*\nSize: `{$generation->size}` | Quality: `{$generation->quality}`" . ($refImagesCount > 0 ? " | Ref Images: `{$refImagesCount}`" : ''),
                    ],
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Prompt:*\n>{$promptExcerpt}",
                ],
            ],
            [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "Generation ID: `{$generation->id}` | Status: *Queued*",
                    ],
                ],
            ],
        ];

        return $this->send([
            'text' => "🎨 New image generation queued by {$userName}: \"{$promptExcerpt}\"",
            'blocks' => $blocks,
        ]);
    }

    /**
     * Send notification when an image generation succeeds.
     */
    public function sendGenerationCompleted(Generation $generation): bool
    {
        $user = $generation->user;
        $userName = $user ? "{$user->name} ({$user->email})" : 'User #' . $generation->user_id;

        $providerCost = $generation->providerCost()->formatDisplay();
        $userCharge = $generation->userCharge()->formatDisplay();

        $promptExcerpt = mb_strlen($generation->prompt) > 300 
            ? mb_substr($generation->prompt, 0, 297) . '...' 
            : $generation->prompt;

        $section = [
            'type' => 'section',
            'fields' => [
                [
                    'type' => 'mrkdwn',
                    'text' => "*User:*\n{$userName}",
                ],
                [
                    'type' => 'mrkdwn',
                    'text' => "*Financials:*\nProvider Cost: `{$providerCost}`\nUser Charge (+25%): `{$userCharge}`",
                ],
            ],
        ];

        if (!empty($generation->image_url)) {
            $section['accessory'] = [
                'type' => 'image',
                'image_url' => $generation->image_url,
                'alt_text' => 'Generated Image',
            ];
        }

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => '✨ Image Generation Succeeded',
                    'emoji' => true,
                ],
            ],
            $section,
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Prompt:*\n>{$promptExcerpt}",
                ],
            ],
        ];

        if (!empty($generation->image_url)) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => '🖼️ View Full Image',
                            'emoji' => true,
                        ],
                        'url' => $generation->image_url,
                    ],
                ],
            ];
        }

        $blocks[] = [
            'type' => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => "Generation ID: `{$generation->id}` | Status: *Succeeded*",
                ],
            ],
        ];

        return $this->send([
            'text' => "✨ Image generation succeeded for {$userName}: \"{$promptExcerpt}\" (Charged {$userCharge})",
            'blocks' => $blocks,
        ]);
    }

    /**
     * Send notification when an image generation fails.
     */
    public function sendGenerationFailed(Generation $generation): bool
    {
        $user = $generation->user;
        $userName = $user ? "{$user->name} ({$user->email})" : 'User #' . $generation->user_id;

        $promptExcerpt = mb_strlen($generation->prompt) > 300 
            ? mb_substr($generation->prompt, 0, 297) . '...' 
            : $generation->prompt;

        $errorType = $generation->error_type ?? 'Unknown';
        $errorMessage = $generation->error_message ?? 'Generation could not be completed.';

        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => '❌ Image Generation Failed',
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*User:*\n{$userName}",
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Error:*\nType: `{$errorType}`\nBilled: `$0.00`",
                    ],
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Error Message:*\n`{$errorMessage}`\n\n*Prompt:*\n>{$promptExcerpt}",
                ],
            ],
            [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "Generation ID: `{$generation->id}` | Status: *Failed*",
                    ],
                ],
            ],
        ];

        return $this->send([
            'text' => "❌ Image generation failed for {$userName}: \"{$promptExcerpt}\" ({$errorMessage})",
            'blocks' => $blocks,
        ]);
    }

    /**
     * Send raw payload to Slack webhook URL if configured.
     */
    public function send(array $payload): bool
    {
        $webhookUrl = config('services.slack.webhook_url');

        if (empty($webhookUrl)) {
            Log::debug('Slack notification skipped: SLACK_WEBHOOK_URL is not configured.');
            return false;
        }

        try {
            $response = Http::timeout(5)->post($webhookUrl, $payload);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Slack notification delivery failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return false;
        } catch (\Throwable $e) {
            Log::warning('Slack notification exception: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return false;
        }
    }
}
