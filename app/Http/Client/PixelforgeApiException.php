<?php

namespace App\Http\Client;

use Exception;

class PixelforgeApiException extends Exception
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorType,
        string $message = '',
        public readonly ?string $retryAfter = null,
        int $code = 0
    ) {
        parent::__construct($message ?: $errorType, $code);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorType, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded']);
    }

    public function isTerminal(): bool
    {
        return in_array($this->errorType, ['content_policy_violation', 'invalid_api_key', 'validation_failed', 'daily_limit_exceeded']);
    }

    public function userFriendlyMessage(): string
    {
        return match ($this->errorType) {
            'content_policy_violation' => 'Your prompt was rejected by the content policy. Please modify your prompt and try again.',
            'invalid_api_key' => 'API key is invalid. Please contact support.',
            'validation_failed' => 'Invalid parameters. Please check your input.',
            'daily_limit_exceeded' => 'Daily generation limit reached. Please try again tomorrow.',
            'rate_limit_exceeded' => 'Too many requests. Please wait a moment and try again.',
            'concurrency_limit_exceeded' => 'Too many concurrent generations. Please wait for others to complete.',
            'overloaded' => 'Service is temporarily overloaded. Please try again shortly.',
            default => 'An unexpected error occurred. Please try again.',
        };
    }
}
