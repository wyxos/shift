<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_environments')) {
            Schema::create('project_environments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->string('environment');
                $table->string('url');
                $table->timestamps();

                $table->unique(['project_id', 'environment'], 'project_environments_project_environment_unique');
            });
        }

        $rows = DB::table('external_users')
            ->select([
                'project_id',
                'environment',
                'url',
                DB::raw('MIN(created_at) as created_at'),
                DB::raw('MAX(updated_at) as updated_at'),
            ])
            ->whereNotNull('project_id')
            ->whereNotNull('environment')
            ->whereNotNull('url')
            ->groupBy('project_id', 'environment', 'url')
            ->get();

        foreach ($rows as $row) {
            DB::table('project_environments')->updateOrInsert(
                [
                    'project_id' => $row->project_id,
                    'environment' => $row->environment,
                ],
                [
                    'url' => rtrim((string) $row->url, '/'),
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_environments');
    }
};
