<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Enums\ReportTargetType;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reporter_id' => User::factory(),
            'target_type' => ReportTargetType::User,
            'target_id' => fn () => User::factory()->create()->id,
            'reason' => 'Asked for money before viewing',
            'status' => ReportStatus::Open,
        ];
    }
}
