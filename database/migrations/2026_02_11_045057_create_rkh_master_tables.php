<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('master_jenis_kegiatan', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();   // perkenalan, penawaran_program, dst
            $table->string('label');            // Perkenalan, Penawaran Program, dst
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('master_tujuan_kegiatan', function (Blueprint $table) {
            $table->id();
            $table->string('jenis_code');       // FK by code (lebih simpel daripada numeric id)
            $table->string('code');             // kunjungan_pertama, reminder_jt, dst
            $table->string('label');            // tampilan dropdown
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['jenis_code', 'code'], 'tujuan_unique_per_jenis');
            $table->index(['jenis_code', 'is_active'], 'tujuan_jenis_active_idx');
        });

        // Seed default (sesuai request kamu)
        DB::table('master_jenis_kegiatan')->insert([
            ['code'=>'perkenalan','label'=>'Perkenalan','is_active'=>true,'sort'=>10,'created_at'=>now(),'updated_at'=>now()],
            ['code'=>'penawaran_program','label'=>'Penawaran Program','is_active'=>true,'sort'=>20,'created_at'=>now(),'updated_at'=>now()],
            ['code'=>'pengembangan_jaringan','label'=>'Pengembangan Jaringan','is_active'=>true,'sort'=>30,'created_at'=>now(),'updated_at'=>now()],
            ['code'=>'maintain','label'=>'Maintain','is_active'=>true,'sort'=>40,'created_at'=>now(),'updated_at'=>now()],
            ['code'=>'penagihan','label'=>'Penagihan','is_active'=>true,'sort'=>50,'created_at'=>now(),'updated_at'=>now()],
        ]);

        DB::table('master_tujuan_kegiatan')->insert([
            // Perkenalan
            ['jenis_code'=>'perkenalan','code'=>'kunjungan_pertama','label'=>'Kunjungan Pertama','is_active'=>true,'sort'=>10,'created_at'=>now(),'updated_at'=>now()],

            // Penawaran Program
            ['jenis_code'=>'penawaran_program','code'=>'penawaran_fasilitas_msa','label'=>'Penawaran Fasilitas MSA','is_active'=>true,'sort'=>10,'created_at'=>now(),'updated_at'=>now()],
            ['jenis_code'=>'penawaran_program','code'=>'penawaran_program_msa','label'=>'Penawaran Program MSA','is_active'=>true,'sort'=>20,'created_at'=>now(),'updated_at'=>now()],

            // Pengembangan Jaringan
            ['jenis_code'=>'pengembangan_jaringan','code'=>'pengembangan_jaringan','label'=>'Pengembangan Jaringan','is_active'=>true,'sort'=>10,'created_at'=>now(),'updated_at'=>now()],
            ['jenis_code'=>'pengembangan_jaringan','code'=>'gathering','label'=>'Gathering','is_active'=>true,'sort'=>20,'created_at'=>now(),'updated_at'=>now()],

            // Maintain
            ['jenis_code'=>'maintain','code'=>'reminder_angs_akan_jt','label'=>'Reminder angs akan JT','is_active'=>true,'sort'=>10,'created_at'=>now(),'updated_at'=>now()],

            // Penagihan
            ['jenis_code'=>'penagihan','code'=>'l0_ke_lt_bulan_ini','label'=>'L0 migrasi ke LT dalam bulan ini','is_active'=>true,'sort'=>10,'created_at'=>now(),'updated_at'=>now()],
            ['jenis_code'=>'penagihan','code'=>'l0_ke_lt_bulan_kemarin','label'=>'L0 migrasi ke LT bulan kemarin','is_active'=>true,'sort'=>20,'created_at'=>now(),'updated_at'=>now()],
            ['jenis_code'=>'penagihan','code'=>'lt_bulan_kemarin','label'=>'LT bulan kemarin','is_active'=>true,'sort'=>30,'created_at'=>now(),'updated_at'=>now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('master_tujuan_kegiatan');
        Schema::dropIfExists('master_jenis_kegiatan');
    }
};
