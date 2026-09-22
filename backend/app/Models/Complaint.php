<?php

namespace App\Models;

use App\Enums\ComplaintTargetType;
use App\Enums\ReportStatus;
use App\Models\Concerns\SerializesCamelCase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reporter_id', 'target_type', 'target_id', 'subject', 'description', 'status',
    'admin_notes', 'resolved_at',
])]
class Complaint extends Model
{
    use HasUlids, SerializesCamelCase;

    protected function casts(): array
    {
        return [
            'target_type' => ComplaintTargetType::class,
            'status' => ReportStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
