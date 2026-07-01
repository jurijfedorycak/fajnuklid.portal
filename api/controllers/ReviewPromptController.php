<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\AppSettingRepository;
use App\Repositories\ClientRepository;
use App\Services\ReviewPromptService;
use App\Exceptions\ApiException;
use App\Exceptions\NotFoundException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Write actions for the dashboard "Zanechat recenzi" block. The block's data is served
 * by DashboardController (one controller per page); these endpoints only record the
 * client's interaction — a "later" snooze or a star rating.
 */
class ReviewPromptController extends Controller
{
    private ClientRepository $clientRepo;
    private AppSettingRepository $appSettingRepo;
    private ReviewPromptService $service;

    public function __construct()
    {
        $this->clientRepo = new ClientRepository();
        $this->appSettingRepo = new AppSettingRepository();
        $this->service = new ReviewPromptService();
    }

    public function snooze(Request $request): void
    {
        $context = $this->requireEligibleContext($request);

        $this->clientRepo->update((int) $context['client']['id'], [
            'review_prompt_snoozed_until' => $this->service->computeSnoozeUntil($context['today']),
        ]);

        Response::success(['message' => 'Připomeneme se později']);
    }

    public function complete(Request $request): void
    {
        // Validate the rating before the eligibility check so a bad payload always
        // returns the 422 field error regardless of prompt state.
        $rating = $this->service->validateRating($request->input('rating'));

        $context = $this->requireEligibleContext($request);
        $target = $this->service->targetForRating($rating);

        $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Prague'));
        $this->clientRepo->update((int) $context['client']['id'], [
            'review_prompt_rating' => $rating,
            'review_prompt_completed_at' => $now->format('Y-m-d H:i:s'),
        ]);

        // The eligibility check guarantees a configured URL, so a 'google' target
        // always carries a non-null link.
        $googleUrl = $target === 'google' ? $context['googleUrl'] : null;

        Response::success([
            'target' => $target,
            'googleUrl' => $googleUrl,
        ]);
    }

    /**
     * Resolve the client and re-check that the prompt is actually eligible to be
     * acted on, so a direct POST can't overwrite state after an admin turned the
     * prompt off (or when it's snoozed / already completed / has no Google link).
     *
     * @return array{client: array<string,mixed>, googleUrl: ?string, today: DateTimeImmutable}
     */
    private function requireEligibleContext(Request $request): array
    {
        $userId = (int) $request->getUser()['id'];
        $client = $this->clientRepo->findByUserId($userId);

        if ($client === null) {
            throw new NotFoundException('Klient nebyl nalezen');
        }

        $googleUrl = $this->appSettingRepo->get(AppSettingRepository::KEY_GOOGLE_REVIEW_URL);
        $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Prague'));

        if (!$this->service->shouldShow($client, $googleUrl, $today)) {
            throw new ApiException('Výzva k recenzi není aktivní', 409);
        }

        return ['client' => $client, 'googleUrl' => $googleUrl, 'today' => $today];
    }
}
