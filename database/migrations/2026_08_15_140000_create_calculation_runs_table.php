<?php

use App\Enums\CalculationRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_runs', function (Blueprint $table) {
            $table->id();

            $table->string('period', 7);          // YYYY-MM
            $table->string('run_type', 30);       // direct | upline | target | company_club
            $table->string('status', 20)->default(CalculationRunStatus::Running->value);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error_message')->nullable();

            // Snapshot of what the run produced, so a completed run can be
            // reconciled without recomputing it.
            $table->unsignedInteger('records_created')->default(0);
            $table->decimal('total_sqft', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);

            $table->timestamps();

            $table->index(['period', 'run_type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculation_runs');
    }
};
