<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class LoanAccount extends Model
{
    use HasFactory;

    // pakai koneksi default (crms_db)
    protected $table = 'loan_accounts';

    protected $fillable = [
        'account_no',
        'cif',
        'customer_name',
        'product_type',
        'alamat',
        'kolek',
        'dpd',
        'plafond',
        'outstanding',
        'arrears_principal',
        'arrears_interest',
        'branch_code',
        'branch_name',
        'ao_code',
        'ao_name',
        'position_date',
        'is_active',
        'is_restructured',
        'restructure_freq',
        'last_restructure_date',
        'jenis_agunan',
        'tgl_kolek',
        'keterangan_sandi',
        'cadangan_ppap',
        'nilai_agunan_yg_diperhitungkan',

    ];

    protected $casts = [
        'position_date' => 'date',
        'is_active'     => 'boolean',
        'is_restructured' => 'boolean',
        'tglakhir_restruktur' => 'date',
        'monitor_restruktur_until' => 'date',
        'last_restruktur_wa_sent_at' => 'datetime',
        'tgl_kolek' => 'date',
        'nilai_agunan_yg_diperhitungkan' => 'decimal:2',

    ];

    // 1 rekening bisa punya banyak kasus
    public function nplCases()
    {
        return $this->hasMany(NplCase::class);
    }

    public function scopeRestrukturActive($q)
    {
        return $q->where('is_restructured', true)
                ->whereNotNull('monitor_restruktur_until')
                ->whereDate('monitor_restruktur_until', '>=', now()->toDateString());
    }

    public function getIsRestructuredAttribute(): bool
    {
        return (int)($this->frek_restruktur ?? 0) > 0;
    }

}
