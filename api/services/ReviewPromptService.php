<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use DateTimeImmutable;

/**
 * Decision logic for the dashboard "Zanechat recenzi" block. Kept free of DB/HTTP
 * concerns so it is fully unit-testable: the caller passes in the client row, the
 * configured Google link and "today", and gets back a yes/no plus the snooze date.
 */
class ReviewPromptService
{
    // A "later" click hides the block for this many days, then it re-appears.
    public const SNOOZE_DAYS = 14;

    // Don't ask brand-new clients — wait until the account has some history so the
    // request feels earned rather than pushy.
    public const MIN_ACCOUNT_AGE_DAYS = 14;

    // Ratings at or above this go to the public Google profile; below it we route the
    // client to a private complaint so unhappy feedback never lands on Google.
    public const GOOGLE_MIN_RATING = 4;

    /**
     * @param array<string,mixed> $client A clients-table row.
     */
    public function shouldShow(array $client, ?string $googleUrl, DateTimeImmutable $today): bool
    {
        // Nothing to point the stars at → keep the block hidden until the owner
        // configures the Google link in admin.
        if ($googleUrl === null || trim($googleUrl) === '') {
            return false;
        }

        if (empty($client['review_prompt_enabled'])) {
            return false;
        }

        // Already engaged once — don't nag again (admin turns the switch off later).
        if (!empty($client['review_prompt_completed_at'])) {
            return false;
        }

        $snoozedUntil = $client['review_prompt_snoozed_until'] ?? null;
        if (is_string($snoozedUntil) && $snoozedUntil !== '') {
            $snooze = DateTimeImmutable::createFromFormat('Y-m-d', substr($snoozedUntil, 0, 10));
            if ($snooze !== false && $today < $snooze->setTime(0, 0)) {
                return false;
            }
        }

        $createdAt = $client['created_at'] ?? null;
        if (is_string($createdAt) && $createdAt !== '') {
            $created = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($createdAt, 0, 19));
            if ($created !== false) {
                $ageDays = (int) $created->setTime(0, 0)->diff($today)->format('%r%a');
                if ($ageDays < self::MIN_ACCOUNT_AGE_DAYS) {
                    return false;
                }
            }
        }

        return true;
    }

    public function computeSnoozeUntil(DateTimeImmutable $today): string
    {
        return $today->modify('+' . self::SNOOZE_DAYS . ' days')->format('Y-m-d');
    }

    /**
     * @return int Validated rating in the 1-5 range.
     */
    public function validateRating(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            throw new ValidationException('Neplatné hodnocení', ['rating' => ['Hodnocení musí být číslo 1 až 5']]);
        }

        $rating = (int) $raw;
        if ($rating < 1 || $rating > 5) {
            throw new ValidationException('Neplatné hodnocení', ['rating' => ['Hodnocení musí být v rozsahu 1 až 5']]);
        }

        return $rating;
    }

    public function targetForRating(int $rating): string
    {
        return $rating >= self::GOOGLE_MIN_RATING ? 'google' : 'complaint';
    }
}
