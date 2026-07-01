<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ValidationException;
use App\Services\ReviewPromptService;
use DateTimeImmutable;
use Tests\TestCase;

class ReviewPromptServiceTest extends TestCase
{
    private ReviewPromptService $service;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReviewPromptService();
        $this->today = new DateTimeImmutable('2026-07-01');
    }

    /**
     * A client old enough, enabled, not snoozed and not yet completed.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function client(array $overrides = []): array
    {
        return array_merge([
            'review_prompt_enabled' => 1,
            'review_prompt_snoozed_until' => null,
            'review_prompt_completed_at' => null,
            'created_at' => '2026-01-01 09:00:00',
        ], $overrides);
    }

    public function testShowsForEstablishedEnabledClientWithUrl(): void
    {
        $this->assertTrue(
            $this->service->shouldShow($this->client(), 'https://g.page/r/abc', $this->today)
        );
    }

    public function testHiddenWhenGoogleUrlMissing(): void
    {
        $this->assertFalse($this->service->shouldShow($this->client(), null, $this->today));
        $this->assertFalse($this->service->shouldShow($this->client(), '   ', $this->today));
    }

    public function testHiddenWhenDisabledByAdmin(): void
    {
        $this->assertFalse(
            $this->service->shouldShow($this->client(['review_prompt_enabled' => 0]), 'https://g.page/r/abc', $this->today)
        );
    }

    public function testHiddenWhenAlreadyCompleted(): void
    {
        $this->assertFalse(
            $this->service->shouldShow(
                $this->client(['review_prompt_completed_at' => '2026-06-01 12:00:00']),
                'https://g.page/r/abc',
                $this->today
            )
        );
    }

    public function testHiddenWhileSnoozeIsInTheFuture(): void
    {
        $this->assertFalse(
            $this->service->shouldShow(
                $this->client(['review_prompt_snoozed_until' => '2026-07-10']),
                'https://g.page/r/abc',
                $this->today
            )
        );
    }

    public function testShownOnceSnoozeHasElapsed(): void
    {
        // Snooze date equals today → the block is due again.
        $this->assertTrue(
            $this->service->shouldShow(
                $this->client(['review_prompt_snoozed_until' => '2026-07-01']),
                'https://g.page/r/abc',
                $this->today
            )
        );
    }

    public function testHiddenForBrandNewAccount(): void
    {
        $this->assertFalse(
            $this->service->shouldShow(
                $this->client(['created_at' => '2026-06-25 09:00:00']),
                'https://g.page/r/abc',
                $this->today
            )
        );
    }

    public function testComputeSnoozeUntilAddsFourteenDays(): void
    {
        $this->assertSame('2026-07-15', $this->service->computeSnoozeUntil($this->today));
    }

    public function testValidateRatingAcceptsOneToFive(): void
    {
        foreach ([1, 2, 3, 4, 5] as $rating) {
            $this->assertSame($rating, $this->service->validateRating((string) $rating));
        }
    }

    public function testValidateRatingRejectsOutOfRange(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->validateRating(6);
    }

    public function testValidateRatingRejectsNonNumeric(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->validateRating('skvělé');
    }

    public function testTargetForRatingRoutesHighToGoogleLowToComplaint(): void
    {
        $this->assertSame('google', $this->service->targetForRating(5));
        $this->assertSame('google', $this->service->targetForRating(4));
        $this->assertSame('complaint', $this->service->targetForRating(3));
        $this->assertSame('complaint', $this->service->targetForRating(1));
    }
}
