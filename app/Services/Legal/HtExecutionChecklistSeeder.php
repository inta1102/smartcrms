<?php

namespace App\Services\Legal;

use App\Models\LegalAction;
use Illuminate\Support\Facades\DB;

class HtExecutionChecklistSeeder
{
    public function seed(LegalAction $action): void
    {
        if (($action->action_type ?? '') !== LegalAction::TYPE_HT_EXECUTION) {
            return;
        }

        // Cegah dobel seed
        if ($action->adminChecklists()->exists()) {
            return;
        }

        $items = [
            ['check_code' => 'ht_doc_ready',     'check_label' => 'Berkas eksekusi siap',                         'is_required' => true,  'sort_order' => 10],
            ['check_code' => 'ht_coord_lawyer',  'check_label' => 'Koordinasi kuasa hukum / internal legal',     'is_required' => true,  'sort_order' => 20],
            ['check_code' => 'ht_announce',      'check_label' => 'Penetapan/pengumuman jadwal eksekusi/lelang', 'is_required' => false, 'sort_order' => 30],
            ['check_code' => 'ht_kpknl',         'check_label' => 'Pengajuan/koordinasi KPKNL (jika lelang)',    'is_required' => false, 'sort_order' => 40],
            ['check_code' => 'ht_execution',     'check_label' => 'Pelaksanaan eksekusi',                        'is_required' => true,  'sort_order' => 50],
            ['check_code' => 'ht_closing',       'check_label' => 'Closing & arsip dokumen',                     'is_required' => true,  'sort_order' => 60],
        ];

        DB::transaction(function () use ($action, $items) {
            foreach ($items as $it) {
                $action->adminChecklists()->create([
                    'check_code'   => $it['check_code'],
                    'check_label'  => $it['check_label'],
                    'is_required'  => $it['is_required'],
                    'sort_order'   => $it['sort_order'],
                    'is_checked'   => false,
                    'checked_by'   => null,
                    'checked_at'   => null,
                    'notes'        => null,
                ]);
            }
        });
    }
}
