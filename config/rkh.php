<?php

return [
    /**
     * Map kode kolek (angka) -> label internal
     * Sesuaikan jika di data kamu berbeda.
     *
     * Contoh umum:
     * 1 = L0 (Lancar)
     * 2 = DPK
     * "LT" biasanya internal (misal 9 / 8 / 6). Sesuaikan.
     */
    'kolek_map' => [
        1 => 'L0',
        2 => 'DPK',
        3 => 'KL',
        4 => 'D',
        5 => 'M',
        // 9 => 'LT', // <-- contoh kalau LT di kode 9
    ],

    // threshold plafon 500jt
    'plafon_big' => 500_000_000,

    /**
     * Master Jenis Kegiatan RKH (RO)
     * Format: [['code'=>'...', 'label'=>'...'], ...]
     */
    'jenis' => [
        ['code' => 'kunjungan',            'label' => 'Kunjungan'],
        ['code' => 'penagihan',            'label' => 'Penagihan'],
        ['code' => 'monitoring',           'label' => 'Monitoring'],
        ['code' => 'pengembangan_jaringan','label' => 'Pengembangan Jaringan'],
        ['code' => 'administrasi',         'label' => 'Administrasi'],
    ],

    /**
     * Master Tujuan by Jenis (dipakai untuk dropdown Tujuan auto)
     * Format:
     *  'jenis_code' => [['code'=>'...', 'label'=>'...'], ...]
     */
    'tujuan_by_jenis' => [
        'kunjungan' => [
            ['code' => 'silaturahmi', 'label' => 'Silaturahmi'],
            ['code' => 'survey',      'label' => 'Survey'],
            ['code' => 'evaluasi',    'label' => 'Evaluasi Kondisi Usaha'],
        ],

        'penagihan' => [
            ['code' => 'penagihan',  'label' => 'Penagihan'],
            ['code' => 'negosiasi',  'label' => 'Negosiasi / Komitmen Bayar'],
            ['code' => 'somasi_awal','label' => 'Peringatan Awal'],
        ],

        'monitoring' => [
            ['code' => 'monitoring', 'label' => 'Monitoring'],
            ['code' => 'cek_agunan', 'label' => 'Cek Agunan'],
        ],

        'pengembangan_jaringan' => [
            ['code' => 'prospek', 'label' => 'Prospek'],
            ['code' => 'relasi',  'label' => 'Relasi / Networking'],
            ['code' => 'event',   'label' => 'Event / Kegiatan Komunitas'],
        ],

        'administrasi' => [
            ['code' => 'input_data', 'label' => 'Input Data / Laporan'],
            ['code' => 'arsip',      'label' => 'Dokumentasi / Arsip'],
        ],
    ],
];
