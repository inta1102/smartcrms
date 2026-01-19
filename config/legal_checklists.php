<?php

return [
    // checklist default untuk HT Execution (pendaftaran lelang)
    'ht_execution' => [
        ['code' => 'OBJ_CLEAR',     'label' => 'Objek HT jelas & sesuai data',                 'required' => true, 'order' => 10],
        ['code' => 'DEBTOR_CLEAR',  'label' => 'Debitur/penjamin sesuai dokumen kredit',        'required' => true, 'order' => 20],
        ['code' => 'HT_VALID',      'label' => 'Hak Tanggungan masih berlaku & dapat dieksekusi','required' => true, 'order' => 30],
        ['code' => 'DEFAULT_VALID', 'label' => 'Wanprestasi terbukti & dasar eksekusi tersedia', 'required' => true, 'order' => 40],
        ['code' => 'NO_DISPUTE',    'label' => 'Tidak dalam sengketa/keberatan yang menghambat', 'required' => true, 'order' => 50],
        ['code' => 'ADDRESS_VALID', 'label' => 'Alamat objek valid & siap proses lapangan',      'required' => true, 'order' => 60],
        ['code' => 'AUCTION_READY', 'label' => 'Kesiapan administrasi pendaftaran lelang terpenuhi', 'required' => true, 'order' => 70],
    ],
];
