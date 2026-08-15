<?php

use App\Enums\MemberStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();

            // Formatted, staff-facing identifier (prefix + sequence), and the
            // raw sequence it was built from. Keeping the number separate means
            // changing the prefix continues the numbering instead of colliding.
            $table->string('member_code', 32)->unique();
            $table->unsignedInteger('sequence_number')->unique();

            $table->string('name');
            $table->string('mobile', 20)->unique();
            $table->string('email')->nullable()->unique();
            $table->text('address')->nullable();

            // Nullable: root members are allowed, and several independent trees
            // may exist. This is also what produces the documented upline case
            // of "0 eligible uplines -> no calculation".
            $table->foreignId('sponsor_id')
                ->nullable()
                ->constrained('members')
                ->nullOnDelete();

            $table->date('joining_date');
            $table->string('status', 20)->default(MemberStatus::Active->value);

            $table->timestamps();
            $table->softDeletes();

            // Indexes required by docs/03_DATABASE_AND_ARCHITECTURE.md.
            // sponsor_id is already indexed by the foreign key constraint.
            $table->index('status');
            $table->index('joining_date');
            $table->index(['status', 'sponsor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
