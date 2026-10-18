<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_tray_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 60);
            $table->string('subtitle', 120)->nullable();
            $table->text('url');
            // Font Awesome class ("fa-solid fa-camera"), emoji, or image URL
            $table->string('icon', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_tray_items');
    }
};
