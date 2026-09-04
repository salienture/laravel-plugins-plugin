<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('version')->nullable();

            // Update channel state.
            $table->string('latest_version')->nullable();
            $table->text('changelog')->nullable();
            $table->string('download_url')->nullable();
            $table->boolean('update_available')->default(false);
            $table->timestamp('last_checked_at')->nullable();

            // Lifecycle state.
            $table->boolean('is_active')->default(false);
            $table->boolean('auto_update')->nullable()->comment('null = follow global default');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugins');
    }
};
