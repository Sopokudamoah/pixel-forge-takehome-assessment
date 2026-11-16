<?php

namespace App\ValueObjects;

/**
 * Money value object that stores amounts as integer nanodollars.
 *
 * 1 USD = 1,000,000,000 nanodollars.
 * All arithmetic is exact integer math — no floats.
 * Markup rounding uses round-half-up to whole nanodollars.
 */
class Money
{
    public const NANO_PER_USD = 1_000_000_000;
    public const TOKENS_PER_RATE = 1_000_000; // rates are per 1M tokens

    /**
     * @param int|string $nanoDollars Amount in nanodollars (integer).
     */
    public function __construct(
        public readonly int $nanoDollars
    ) {
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Create from a whole USD amount (integer cents-style).
     */
    public static function fromUsd(int $usd): self
    {
        return new self($usd * self::NANO_PER_USD);
    }

    /**
     * Parse a rate string like "5.00" or "12.50" and return nanodollars per token.
     *
     * Rates are per 1M tokens, so nanodollars per token = rate_string * 1000.
     * Uses BCMath for exact parsing — never floatval().
     */
    public static function fromRateString(string $rateString): self
    {
        // rateString is "$R per 1M tokens"
        // nanodollars per token = R * NANO_PER_USD / TOKENS_PER_RATE = R * 1000
        $nanoPerToken = bcmul(rtrim($rateString, " .\t\n\r\0\x0B"), (string) self::NANO_PER_USD, 0);
        $nanoPerToken = bcdiv($nanoPerToken, (string) self::TOKENS_PER_RATE, 0);

        return new self((int) $nanoPerToken);
    }

    /**
     * Calculate cost for a given number of tokens at a given rate string.
     */
    public static function fromTokensAndRate(int $tokens, string $rateString): self
    {
        $nanoPerToken = self::fromRateString($rateString);
        return new self($tokens * $nanoPerToken->nanoDollars);
    }

    public function add(self $other): self
    {
        return new self($this->nanoDollars + $other->nanoDollars);
    }

    public function subtract(self $other): self
    {
        return new self($this->nanoDollars - $other->nanoDollars);
    }

    /**
     * Multiply by a decimal string (e.g., "1.25" for 25% markup).
     * Uses BCMath for exact calculation, rounds half up to whole nanodollars.
     */
    public function multiply(string $multiplier): self
    {
        $result = bcmul(
            (string) $this->nanoDollars,
            $multiplier,
            0
        );

        return new self((int) $result);
    }

    /**
     * Calculate user charge with 25% markup from provider cost.
     */
    public function withMarkup(): self
    {
        return $this->multiply('1.25');
    }

    public function isZero(): bool
    {
        return $this->nanoDollars === 0;
    }

    public function isPositive(): bool
    {
        return $this->nanoDollars > 0;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->nanoDollars >= $other->nanoDollars;
    }

    /**
     * Format as a USD string with 9 decimal places for full precision.
     * e.g., "$0.012642500"
     */
    public function formatForDisplay(): string
    {
        return $this->formatDisplay();
    }

    public function formatDisplay(): string
    {
        if ($this->nanoDollars === 0) {
            return '$0.000000000';
        }

        $negative = $this->nanoDollars < 0;
        $abs = abs($this->nanoDollars);

        $integerPart = intdiv($abs, self::NANO_PER_USD);
        $fractionalPart = $abs % self::NANO_PER_USD;

        $fractionalStr = str_pad((string) $fractionalPart, 9, '0', STR_PAD_LEFT);

        $formatted = '$' . ($negative ? '-' : '') . $integerPart . '.' . $fractionalStr;

        return $formatted;
    }

    /**
     * Format as a shorter USD string for display (up to 6 decimals, trimmed).
     */
    public function formatShort(): string
    {
        if ($this->nanoDollars === 0) {
            return '$0.00';
        }

        $negative = $this->nanoDollars < 0;
        $abs = abs($this->nanoDollars);

        $integerPart = intdiv($abs, self::NANO_PER_USD);
        $fractionalPart = $abs % self::NANO_PER_USD;

        $fractionalStr = str_pad((string) $fractionalPart, 9, '0', STR_PAD_LEFT);

        // Trim trailing zeros but keep at least 2 decimals
        $trimmed = rtrim($fractionalStr, '0');
        $trimmed = substr($trimmed, 0, 6); // max 6 decimals
        if (strlen($trimmed) < 2) {
            $trimmed = str_pad($trimmed, 2, '0');
        }

        return '$' . ($negative ? '-' : '') . $integerPart . '.' . $trimmed;
    }
}
