<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Models\Chat;
use App\Models\Inquiry;
use App\Models\Message;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportTest extends TestCase
{
    #[Test]
    public function a_user_can_report_a_user_and_duplicates_are_collapsed(): void
    {
        $target = User::factory()->owner()->create();
        $me = $this->signIn();
        $post = Post::factory()->create();

        $id = $this->postJson('/api/report', [
            'targetId' => $target->id, 'targetType' => 'user', 'reason' => '  Asked for a deposit  ',
            'description' => 'Wanted money first', 'postId' => $post->id,
        ])
            ->assertCreated()
            ->assertJsonPath('report.reason', 'Asked for a deposit')
            ->assertJsonPath('report.status', 'open')
            ->assertJsonPath('report.reporter.id', $me->id)
            ->assertJsonPath('report.post.id', $post->id)
            ->json('report.id');

        $this->postJson('/api/report', ['targetId' => $target->id, 'targetType' => 'user', 'reason' => 'again'])
            ->assertOk()->assertJsonPath('message', 'You already reported this item')->assertJsonPath('report.id', $id);
        $this->assertSame(1, Report::count());
    }

    #[Test]
    public function reports_default_to_message_targets_and_validate_input(): void
    {
        $this->signIn();

        $this->postJson('/api/report', ['targetId' => 'abc', 'reason' => 'spam'])
            ->assertCreated()->assertJsonPath('report.targetType', 'message');
        $this->postJson('/api/report', ['reason' => 'x'])->assertStatus(422)->assertJson(['message' => 'Target and reason are required']);
        $this->postJson('/api/report', ['targetId' => 'x', 'reason' => 'y', 'targetType' => 'galaxy'])
            ->assertStatus(422)->assertJson(['message' => 'Invalid report target type']);
    }

    #[Test]
    public function users_only_list_their_own_reports_while_admins_see_everything_with_context(): void
    {
        $offender = User::factory()->create(['username' => 'shady_sam']);
        $mine = Report::factory()->create(['target_id' => $offender->id]);
        Report::factory()->create(['target_id' => $offender->id, 'status' => ReportStatus::Resolved]);

        $this->signIn($mine->reporter);
        $this->getJson('/api/report')->assertJsonCount(1)->assertJsonPath('0.id', $mine->id);

        $this->signIn(User::factory()->admin()->create());
        $this->getJson('/api/report')
            ->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.targetUserId', $offender->id)
            ->assertJsonPath('0.targetUser.username', 'shady_sam')
            ->assertJsonPath('0.targetUser.isActive', true)
            ->assertJsonPath('0.reputation', ['openCount' => 1, 'resolvedCount' => 1, 'dismissedCount' => 0]);
    }

    #[Test]
    public function reports_on_messages_bookings_and_listings_resolve_to_the_responsible_user(): void
    {
        $chat = Chat::factory()->create();
        $sender = User::factory()->create();
        $message = Message::factory()->create(['chat_id' => $chat->id, 'user_id' => $sender->id]);
        $post = Post::factory()->create();

        $viaMessage = Report::factory()->create(['target_type' => 'message', 'target_id' => $message->id]);
        $viaPost = Report::factory()->create(['target_type' => 'boarding', 'target_id' => $post->id]);
        Report::factory()->create(['target_type' => 'user', 'target_id' => $sender->id]);

        $this->signIn(User::factory()->admin()->create());
        $reports = collect($this->getJson('/api/report')->assertOk()->json())->keyBy('id');

        $this->assertSame($sender->id, $reports[$viaMessage->id]['targetUserId']);
        $this->assertSame($post->owner_id, $reports[$viaPost->id]['targetUserId']);
        // The sender is reported directly once and via one of their messages once.
        $this->assertSame(2, $reports[$viaMessage->id]['reputation']['openCount']);
    }

    #[Test]
    public function moderation_endpoints_are_admin_only(): void
    {
        $report = Report::factory()->create();
        $this->signIn();

        $this->getJson('/api/report/admin/overview')->assertForbidden()->assertJson(['message' => 'Admin access required']);
        $this->postJson('/api/report/warn', ['reportId' => $report->id])->assertForbidden();
        $this->postJson('/api/report/user-state', ['reportId' => $report->id, 'isActive' => false])->assertForbidden();
        $this->patchJson("/api/report/{$report->id}", ['status' => 'resolved'])->assertForbidden();
    }

    #[Test]
    public function the_admin_overview_summarises_reports_inquiries_and_flagged_messages(): void
    {
        Report::factory()->create();
        Report::factory()->create(['status' => ReportStatus::UnderReview]);
        Report::factory()->create(['status' => ReportStatus::Resolved]);

        $inquiry = Inquiry::factory()->create(['status' => 'accepted']);
        $chat = Chat::openBetween($inquiry->student_id, $inquiry->owner_id);
        $inquiry->update(['chat_id' => $chat->id]);
        Inquiry::factory()->create();

        $sender = User::factory()->create(['username' => 'scammer_x']);
        Message::factory()->create(['chat_id' => $chat->id, 'user_id' => $sender->id, 'scam_flag' => 'danger', 'text' => 'send money']);
        Message::factory()->create(['chat_id' => $chat->id, 'user_id' => $sender->id, 'scam_flag' => 'warning']);
        Message::factory()->create(['chat_id' => $chat->id, 'user_id' => $sender->id, 'scam_flag' => 'safe']);

        $this->signIn(User::factory()->admin()->create());
        $this->getJson('/api/report/admin/overview')
            ->assertOk()
            ->assertJsonPath('summary', [
                'totalReports' => 3, 'openReports' => 1, 'underReviewReports' => 1, 'resolvedReports' => 1,
                'totalInquiries' => 2, 'pendingInquiries' => 1, 'acceptedInquiries' => 1,
                'totalFlaggedMessages' => 2, 'dangerMessages' => 1,
            ])
            ->assertJsonCount(2, 'recentInquiries')
            ->assertJsonCount(2, 'suspiciousMessages')
            ->assertJsonPath('suspiciousMessages.0.sender.username', 'scammer_x')
            ->assertJsonPath('suspiciousMessages.0.chat.id', $chat->id)
            ->assertJsonPath('suspiciousMessages.0.chat.inquiry.id', $inquiry->id);
    }

    #[Test]
    public function an_admin_can_warn_the_reported_user(): void
    {
        $report = Report::factory()->create(['reason' => 'Fake photos']);
        $this->signIn(User::factory()->admin()->create());

        $this->postJson('/api/report/warn', ['reportId' => $report->id])
            ->assertOk()
            ->assertJsonPath('report.status', 'underReview')
            ->assertJsonPath('targetUserId', $report->target_id);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $report->target_id, 'type' => 'safetyAlert', 'title' => 'Account warning issued',
            'message' => 'An admin reviewed a report and issued a warning related to: Fake photos.',
        ]);

        $this->postJson('/api/report/warn', [])->assertStatus(422)->assertJson(['message' => 'Report id is required']);
        $this->postJson('/api/report/warn', ['reportId' => 'missing'])->assertNotFound()->assertJson(['message' => 'Report not found']);
    }

    #[Test]
    public function suspending_a_user_locks_them_out_immediately_and_restoring_lets_them_back(): void
    {
        $offender = User::factory()->create(['username' => 'to_suspend']);
        $report = Report::factory()->create(['target_id' => $offender->id]);

        $this->signIn($offender);
        $this->getJson('/api/test/should-be-logged-in')->assertOk();

        $this->signIn(User::factory()->admin()->create());
        $this->postJson('/api/report/user-state', ['reportId' => $report->id, 'isActive' => false])
            ->assertOk()
            ->assertJsonPath('message', 'User account suspended')
            ->assertJsonPath('targetUser.isActive', false)
            ->assertJsonPath('report.status', 'resolved');

        $this->assertNotNull($report->fresh()->resolved_at);
        $this->assertSame(0, $offender->tokens()->count(), 'existing sessions must be revoked');
        $this->postJson('/api/auth/login', ['username' => 'to_suspend', 'password' => 'password123'])->assertForbidden();
        $this->assertDatabaseHas('user_notifications', ['user_id' => $offender->id, 'title' => 'Account suspended']);

        $this->postJson('/api/report/user-state', ['reportId' => $report->id, 'isActive' => true, 'adminNotes' => 'Appeal accepted'])
            ->assertOk()->assertJsonPath('message', 'User account restored')->assertJsonPath('report.adminNotes', 'Appeal accepted');
        $this->postJson('/api/auth/login', ['username' => 'to_suspend', 'password' => 'password123'])->assertOk();

        $this->postJson('/api/report/user-state', ['reportId' => $report->id, 'isActive' => 'nope'])->assertStatus(422);
    }

    #[Test]
    public function an_admin_can_resolve_or_dismiss_a_report(): void
    {
        $report = Report::factory()->create();
        $this->signIn(User::factory()->admin()->create());

        $this->patchJson("/api/report/{$report->id}", ['status' => 'dismissed', 'adminNotes' => ' Not a violation '])
            ->assertOk()->assertJsonPath('report.status', 'dismissed')->assertJsonPath('report.adminNotes', 'Not a violation');
        $this->assertNotNull($report->fresh()->resolved_at);

        $this->patchJson("/api/report/{$report->id}", [])->assertOk()->assertJsonPath('report.status', 'resolved')
            ->assertJsonPath('report.adminNotes', 'Not a violation');
        $this->patchJson("/api/report/{$report->id}", ['status' => 'open'])->assertStatus(422)->assertJson(['message' => 'Invalid report status']);
    }

    #[Test]
    public function the_dev_seed_helper_creates_a_sample_report(): void
    {
        Post::factory()->create();
        $this->signIn();

        $this->postJson('/api/report/dev-seed')->assertCreated()->assertJsonPath('report.targetType', 'user');
    }
}
