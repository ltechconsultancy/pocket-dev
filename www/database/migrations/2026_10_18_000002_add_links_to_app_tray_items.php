<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_tray_items', function (Blueprint $table) {
            // Sub-links for specific pages: [{"title","url","icon"}], max 8
            $table->json('links')->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('app_tray_items', function (Blueprint $table) {
            $table->dropColumn('links');
        });
    }
};
