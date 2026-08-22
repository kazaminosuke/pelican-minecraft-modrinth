<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mod_manager_server_settings', function (Blueprint $table): void {
            $table->boolean('mod_enabled')->default(true);
            $table->boolean('plugin_enabled')->default(true);
            $table->boolean('datapack_enabled')->default(true);
            $table->integer('mod_navigation_sort')->nullable();
            $table->integer('plugin_navigation_sort')->nullable();
            $table->integer('datapack_navigation_sort')->nullable();
        });

        // The previous `enabled` switch was a server-wide kill switch. Copy
        // it to every type so an existing explicit disable remains disabled,
        // while rows created by the old schema keep their exact behaviour.
        DB::table('mod_manager_server_settings')->update([
            'mod_enabled' => DB::raw('enabled'),
            'plugin_enabled' => DB::raw('enabled'),
            'datapack_enabled' => DB::raw('enabled'),
        ]);
    }

    public function down(): void
    {
        Schema::table('mod_manager_server_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'mod_enabled',
                'plugin_enabled',
                'datapack_enabled',
                'mod_navigation_sort',
                'plugin_navigation_sort',
                'datapack_navigation_sort',
            ]);
        });
    }
};
