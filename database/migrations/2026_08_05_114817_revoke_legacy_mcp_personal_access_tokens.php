<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('personal_access_tokens')
            ->where(function ($query): void {
                $query->where('abilities', 'like', '%"mcp:use"%')
                    ->orWhere('abilities', 'like', '%"mcp:write"%');
            })
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revoked credentials cannot be restored securely.
    }
};
