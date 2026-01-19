<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

use App\Models\User;
use App\Models\NplCase;
use App\Models\LegalCase;
use App\Models\LegalAction;

use App\Services\Legal\LegalActionStatusService;

class LegalActionStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('legal.somasi.deadline_days', 7);
        config()->set('legal.somasi.remind_days_before', 1);
        config()->set('legal.somasi.remind_hour', 9);
        config()->set('legal.somasi.remind_minute', 0);
    }

    private function makeAction(string $type = 'somasi'): LegalAction
    {
        $nplCase = NplCase::factory()->create();

        $legalCase = LegalCase::create([
            'npl_case_id'   => $nplCase->id,
            'legal_case_no' => 'LC-' . now()->format('YmdHis') . rand(10, 99),
            'status'        => 'legal_init',
        ]);

        return LegalAction::create([
            'legal_case_id' => $legalCase->id,
            'action_type'   => $type,
            'sequence_no'   => 1,
            'status'        => 'draft',
        ]);
    }

    public function test_transition_creates_status_log_and_sets_start_end(): void
    {
        $svc    = app(LegalActionStatusService::class);
        $action = $this->makeAction('ht_execution');
        $user   = User::factory()->create(['level' => 'kti']);

        $t1 = Carbon::parse('2025-01-01 10:00:00');

        // draft -> submitted
        $updated = $svc->transition($action, 'submitted', $user->id, 'submit awal', $t1);
        $this->assertEquals('submitted', $updated->status);

        $this->assertDatabaseHas('legal_action_status_logs', [
            'legal_action_id' => $action->id,
            'from_status'     => 'draft',
            'to_status'       => 'submitted',
            'changed_by'      => $user->id,
            'remarks'         => 'submit awal',
        ]);

        // submitted -> in_progress => start_at harus terisi (t2)
        $t2 = Carbon::parse('2025-01-01 11:00:00');

        $updated2 = $svc->transition($updated, 'in_progress', $user->id, 'mulai proses', $t2);
        $this->assertEquals('in_progress', $updated2->status);
        $this->assertNotNull($updated2->start_at);
        $this->assertEquals($t2->toDateTimeString(), Carbon::parse($updated2->start_at)->toDateTimeString());

        $this->assertDatabaseHas('legal_action_status_logs', [
            'legal_action_id' => $action->id,
            'from_status'     => 'submitted',
            'to_status'       => 'in_progress',
            'changed_by'      => $user->id,
            'remarks'         => 'mulai proses',
        ]);

        // in_progress -> completed => end_at harus terisi (t3)
        $t3 = Carbon::parse('2025-01-02 09:30:00');

        $updated3 = $svc->transition($updated2, 'completed', $user->id, 'selesai', $t3);
        $this->assertEquals('completed', $updated3->status);
        $this->assertNotNull($updated3->end_at);
        $this->assertEquals($t3->toDateTimeString(), Carbon::parse($updated3->end_at)->toDateTimeString());

        $this->assertDatabaseHas('legal_action_status_logs', [
            'legal_action_id' => $action->id,
            'from_status'     => 'in_progress',
            'to_status'       => 'completed',
            'changed_by'      => $user->id,
            'remarks'         => 'selesai',
        ]);
    }

    public function test_illegal_transition_throws_domain_exception(): void
    {
        $this->expectException(\DomainException::class);

        $svc    = app(LegalActionStatusService::class);
        $action = $this->makeAction('ht_execution');
        $user   = User::factory()->create(['level' => 'kti']);

        // draft -> completed (loncat) harus ditolak
        $svc->transition($action, 'completed', $user->id, 'loncat', Carbon::parse('2025-01-01 10:00:00'));
    }

    public function test_somasi_submitted_auto_creates_deadline_event(): void
    {
        $svc    = app(LegalActionStatusService::class);
        $action = $this->makeAction('somasi');
        $user   = User::factory()->create(['level' => 'kti']);

        $changedAt = Carbon::parse('2025-01-01 10:00:00');

        // draft -> submitted (somasi flow)
        $updated = $svc->transition($action, 'submitted', $user->id, 'somasi dikirim', $changedAt);
        $this->assertEquals('submitted', $updated->status);

        // Event harus dibuat: somasi_deadline (scheduled)
        $this->assertDatabaseHas('legal_events', [
            'legal_action_id' => $action->id,
            'event_type'      => 'somasi_deadline',
            'status'          => 'scheduled',
        ]);

        // event_at harus = changedAt + deadline_days (service kamu pakai base $changedAt)
        $deadlineDays    = (int) config('legal.somasi.deadline_days', 7);
        $expectedEventAt = $changedAt->copy()->addDays($deadlineDays);

        $this->assertDatabaseHas('legal_events', [
            'legal_action_id' => $action->id,
            'event_type'      => 'somasi_deadline',
            'event_at'        => $expectedEventAt->toDateTimeString(),
        ]);

        // (opsional) remind_at kalau service set berdasarkan config
        // kalau service kamu mengisi remind_at, boleh diaktifkan:
        /*
        $remindDaysBefore = (int) config('legal.somasi.remind_days_before', 1);
        $remindHour       = (int) config('legal.somasi.remind_hour', 9);
        $remindMinute     = (int) config('legal.somasi.remind_minute', 0);

        $expectedRemindAt = $expectedEventAt->copy()->subDays($remindDaysBefore)
            ->setTime($remindHour, $remindMinute);

        $this->assertDatabaseHas('legal_events', [
            'legal_action_id' => $action->id,
            'event_type'      => 'somasi_deadline',
            'remind_at'       => $expectedRemindAt->toDateTimeString(),
        ]);
        */
    }

    public function test_somasi_close_marks_event_done_or_cancelled(): void
    {
        $svc    = app(LegalActionStatusService::class);
        $action = $this->makeAction('somasi');
        $user   = User::factory()->create(['level' => 'kti']);

        $t1 = Carbon::parse('2025-01-01 10:00:00');

        // create deadline event
        $updated = $svc->transition($action, 'submitted', $user->id, 'kirim', $t1);

        $this->assertDatabaseHas('legal_events', [
            'legal_action_id' => $action->id,
            'event_type'      => 'somasi_deadline',
            'status'          => 'scheduled',
        ]);

        // somasi flow: submitted -> waiting (start_at boleh terisi di service kamu)
        $t2 = Carbon::parse('2025-01-02 08:00:00');
        $updated2 = $svc->transition($updated, 'waiting', $user->id, 'menunggu', $t2);
        $this->assertEquals('waiting', $updated2->status);

        // karena service kamu set start_at saat waiting jika start_at kosong
        $this->assertNotNull($updated2->start_at);

        // waiting -> completed (close) => end_at set
        $t3 = Carbon::parse('2025-01-03 16:30:00');
        $updated3 = $svc->transition($updated2, 'completed', $user->id, 'respon OK', $t3);
        $this->assertEquals('completed', $updated3->status);

        // end_at harus sesuai t3
        $this->assertNotNull($updated3->end_at);
        $this->assertEquals($t3->toDateTimeString(), Carbon::parse($updated3->end_at)->toDateTimeString());

        // event scheduled harus jadi done (atau cancelled sesuai rule kamu; test kamu minta done)
        $this->assertDatabaseHas('legal_events', [
            'legal_action_id' => $action->id,
            'event_type'      => 'somasi_deadline',
            'status'          => 'done',
        ]);
    }

    public function test_somasi_flow_blocks_submitted_to_in_progress(): void
    {
        $this->expectException(\DomainException::class);

        $svc    = app(LegalActionStatusService::class);
        $action = $this->makeAction('somasi');
        $user   = User::factory()->create(['level' => 'kti']);

        $t1 = Carbon::parse('2025-01-01 10:00:00');

        // draft -> submitted ok
        $updated = $svc->transition($action, 'submitted', $user->id, 'kirim', $t1);
        $this->assertEquals('submitted', $updated->status);

        // somasi flow tidak mengizinkan submitted -> in_progress (harus waiting)
        $svc->transition($updated, 'in_progress', $user->id, 'harusnya gagal', Carbon::parse('2025-01-01 11:00:00'));
    }

    public function test_same_status_transition_returns_fresh_without_creating_log(): void
    {
        $svc    = app(LegalActionStatusService::class);
        $action = $this->makeAction('ht_execution');
        $user   = User::factory()->create(['level' => 'kti']);

        // set ke submitted dulu
        $svc->transition($action, 'submitted', $user->id, 'submit', Carbon::parse('2025-01-01 10:00:00'));

        // hitung log sebelum
        $before = \DB::table('legal_action_status_logs')->where('legal_action_id', $action->id)->count();

        // transition ke status yang sama => tidak bikin log baru
        $same = $svc->transition($action->fresh(), 'submitted', $user->id, 'noop', Carbon::parse('2025-01-01 10:05:00'));

        $after = \DB::table('legal_action_status_logs')->where('legal_action_id', $action->id)->count();

        $this->assertEquals($before, $after);
        $this->assertEquals('submitted', $same->status);
    }
}
