<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('downloads', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->text('url');
            $table->text('title')->nullable();
            $table->text('thumbnail')->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('format_id', 120)->nullable();
            $table->string('format_label', 255)->nullable();
            $table->text('file_path')->nullable();
            $table->text('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downloads');
    }
};
