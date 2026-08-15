<?php

use App\Enums\SaleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registry_sales', function (Blueprint $table) {
            $table->id();

            // The seller. Restricted on delete: a sale must never lose the member
            // it belongs to, because every reward traces back through this column.
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();

            $table->string('registry_reference', 100);

            // CLIENT-CONFIRMED: registry_date is the day the admin records the
            // sale, and it is the date that decides which month the sale belongs
            // to for every reward calculation. sale_date is retained from the
            // documented schema for reporting and defaults to the same day.
            $table->date('registry_date');
            $table->date('sale_date');

            // Money-adjacent: DECIMAL, never a float
            // (docs/03_DATABASE_AND_ARCHITECTURE.md).
            $table->decimal('sqft', 12, 2);

            // CLIENT-CONFIRMED: entry is approval. Every row is created approved.
            $table->string('status', 20)->default(SaleStatus::Approved->value);

            $table->text('notes')->nullable();

            // Who recorded it. Nullable on delete so removing a staff account
            // never destroys the sale record.
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // A registry reference identifies one legal registration and must not
            // be entered twice — this is the duplicate-sale guard.
            $table->unique('registry_reference');

            // Indexes required by docs/03_DATABASE_AND_ARCHITECTURE.md, plus the
            // composite the monthly reward engines will query on from Phase 5.
            $table->index('registry_date');
            $table->index('sale_date');
            $table->index('status');
            $table->index(['member_id', 'registry_date']);
            $table->index(['status', 'registry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_sales');
    }
};
