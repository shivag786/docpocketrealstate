<?php

use App\Enums\PropertyStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->constrained('projects')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('property_code', 64);
            $table->text('details')->nullable();
            $table->string('status', 20)->default(PropertyStatus::Active->value);
            $table->timestamps();
            $table->softDeletes();

            // A site code is unique within its project, not across the company —
            // two projects may legitimately both have a "Plot A-1".
            $table->unique(['project_id', 'property_code']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
