<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void
  {
    Schema::create('marketing_kpi_monthlies', function (Blueprint $table) {
      $table->id();

      $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
      $table->foreignId('target_id')->nullable()->constrained('marketing_kpi_targets')->nullOnDelete();

      // "Bulan KPI" selalu tanggal 1 (YYYY-MM-01)
      $table->date('period')->index();

      // AO code saat dihitung (dibekukan)
      $table->string('ao_code', 20)->nullable()->index();

      // TARGET
      $table->unsignedBigInteger('target_os_growth')->default(0);
      $table->unsignedInteger('target_noa')->default(0);
      $table->unsignedTinyInteger('weight_os')->default(60);
      $table->unsignedTinyInteger('weight_noa')->default(40);

      // REALISASI (Now & Prev)
      $table->unsignedBigInteger('os_end_now')->default(0);
      $table->unsignedBigInteger('os_end_prev')->default(0);
      $table->bigInteger('os_growth')->default(0);

      $table->unsignedInteger('noa_end_now')->default(0);
      $table->unsignedInteger('noa_end_prev')->default(0);
      $table->integer('noa_growth')->default(0);

      // SOURCE INFO
      $table->string('os_source_now', 20)->nullable();   // live|snapshot
      $table->string('os_source_prev', 20)->nullable();  // snapshot
      $table->date('position_date_now')->nullable();     // live: today, snapshot: last day in month (opsional)
      $table->date('position_date_prev')->nullable();

      // ACH & SCORE
      $table->decimal('os_ach_pct', 8, 2)->default(0);
      $table->decimal('noa_ach_pct', 8, 2)->default(0);
      $table->decimal('score_os', 10, 2)->default(0);
      $table->decimal('score_noa', 10, 2)->default(0);
      $table->decimal('score_total', 10, 2)->default(0);

      $table->boolean('is_final')->default(false); // true kalau bulan sudah ditutup (snapshot)
      $table->timestamp('computed_at')->nullable();

      $table->timestamps();

      $table->unique(['user_id','period'], 'uq_mkm_user_period');
      $table->index(['ao_code','period'], 'idx_mkm_aocode_period');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('marketing_kpi_monthlies');
  }
};
