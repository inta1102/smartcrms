<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\LegalCase;
use App\Models\LegalAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use App\Models\NplCase;

class LegalActionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set config matrix khusus untuk test (biar konsisten)
        config()->set('legal.policy_matrix', [
            'KTI' => ['submitted','in_progress','waiting','completed','failed','cancelled'],
            'KBO' => ['in_progress','waiting','completed','failed','cancelled'],
            'KBL' => ['in_progress','waiting','completed','failed','cancelled'],
            'KBF' => ['in_progress','waiting','completed','failed','cancelled'],
            'KSO' => ['in_progress','waiting','completed','failed'],
            'KSA' => ['in_progress','waiting'],
            'TI'  => ['submitted','in_progress','waiting'],
            'BO'  => ['submitted','in_progress'],
            'ADM' => ['submitted'],
            'STAFF' => ['submitted'],
            '*'   => [],
        ]);

        // Matrix per action_type (yang kamu minta)
        config()->set('legal.policy_matrix_by_action_type', [
            'criminal_report' => [
                'KTI' => ['submitted','in_progress','waiting','completed','failed','cancelled'],
                'KBO' => ['submitted','in_progress','waiting','completed','failed','cancelled'],
                '*'   => [],
            ],
            'ht_execution' => [
                'KTI' => ['submitted','in_progress','waiting','completed','failed','cancelled'],
                'KBO' => ['in_progress','waiting','completed','failed','cancelled'],
                'KBF' => ['in_progress','waiting','completed','failed','cancelled'],
                'KBL' => ['in_progress','waiting','completed','failed','cancelled'],
                'KSO' => ['in_progress','waiting'], // KSO tidak bisa close
                '*'   => [],
            ],
        ]);
    }

    private function makeAction(string $type = 'somasi'): LegalAction
    {
        $nplCase = NplCase::factory()->create();

        $legalCase = LegalCase::create([
            'npl_case_id'    => $nplCase->id,
            'legal_case_no'  => 'LC-' . now()->format('YmdHis') . rand(10,99),
            'status'         => 'legal_init',
        ]);

        return LegalAction::create([
            'legal_case_id' => $legalCase->id,
            'action_type'   => $type,
            'sequence_no'   => 1,
            'status'        => 'draft',
        ]);
    }

    public function test_policy_matrix_by_action_type_criminal_report_only_kti_kbo_can_change_status(): void
    {
        $action = $this->makeAction('criminal_report');

        $kti = User::factory()->create(['level' => 'KTI']);
        $kso = User::factory()->create(['level' => 'KSO']);

        $allowedKti = Gate::forUser($kti)->allows('updateStatus', [$action, 'submitted']);
        $allowedKso = Gate::forUser($kso)->allows('updateStatus', [$action, 'submitted']);

        $this->assertTrue($allowedKti);
        $this->assertFalse($allowedKso);
    }

    public function test_policy_matrix_by_action_type_ht_execution_kso_cannot_close(): void
    {
        $action = $this->makeAction('ht_execution');

        $kso = User::factory()->create(['level' => 'KSO']);
        $kbo = User::factory()->create(['level' => 'KBO']);

        // KSO boleh in_progress
        $this->assertTrue(Gate::forUser($kso)->allows('updateStatus', [$action, 'in_progress']));

        // KSO tidak boleh completed
        $this->assertFalse(Gate::forUser($kso)->allows('updateStatus', [$action, 'completed']));

        // KBO boleh completed
        $this->assertTrue(Gate::forUser($kbo)->allows('updateStatus', [$action, 'completed']));
    }
}
