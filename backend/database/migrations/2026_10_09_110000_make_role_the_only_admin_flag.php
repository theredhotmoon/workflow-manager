<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin status had two sources of truth: the is_admin flag (set on the Users screen) and role
 * (admin / schedule_maintainer / employee). role is now the only one: everyone flagged is_admin
 * becomes role 'admin' (as they already were in practice), then the flag goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });

        DB::table('users')->where('role', 'admin')->update(['is_admin' => true]);
    }
};
