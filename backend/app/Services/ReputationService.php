<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Enums\ReportTargetType;
use App\Models\BookingRequest;
use App\Models\Message;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Database\Eloquent\Builder;

/**
 * Works out which user a report is really about and how often that user has been reported.
 */
class ReputationService
{
    /**
     * A report targets a user, a message, a booking request or a listing; resolve it to the
     * user who should answer for it.
     */
    public function reportedUserId(Report $report): ?string
    {
        return match ($report->target_type) {
            ReportTargetType::User => $report->target_id,
            ReportTargetType::Message => Message::whereKey($report->target_id)->value('user_id'),
            // A booking request is reported by the party that did not create it, so the student is blamed first.
            ReportTargetType::BookingRequest => BookingRequest::whereKey($report->target_id)->value('student_id'),
            ReportTargetType::Boarding => Post::whereKey($report->target_id)->value('owner_id'),
        };
    }

    /**
     * Report counts about a user: directly, or via messages they wrote.
     *
     * @return array{openCount: int, resolvedCount: int, dismissedCount: int}
     */
    public function summaryFor(?string $userId): array
    {
        $summary = ['openCount' => 0, 'resolvedCount' => 0, 'dismissedCount' => 0];

        if (! $userId) {
            return $summary;
        }

        $statuses = Report::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->where('target_type', ReportTargetType::User)->where('target_id', $userId))
                ->orWhere(fn (Builder $q) => $q->where('target_type', ReportTargetType::Message)
                    ->whereIn('target_id', Message::where('user_id', $userId)->select('id'))))
            ->pluck('status');

        foreach ($statuses as $status) {
            match ($status) {
                ReportStatus::Open, ReportStatus::UnderReview => $summary['openCount']++,
                ReportStatus::Resolved => $summary['resolvedCount']++,
                ReportStatus::Dismissed => $summary['dismissedCount']++,
            };
        }

        return $summary;
    }
}
