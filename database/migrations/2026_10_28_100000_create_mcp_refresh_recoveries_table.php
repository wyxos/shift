<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_refresh_recoveries', function (Blueprint $table): void {
            $table->char('request_hash', 64)->primary();
            $table->char('access_token_id', 80);
            $table->char('refresh_token_id', 80);
            $table->text('payload');
            $table->dateTime('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_refresh_recoveries');
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
