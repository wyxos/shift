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
            return;
        }

        Schema::table('project_environments', function (Blueprint $table) {
            if (! Schema::hasColumn('project_environments', 'external_widget_enabled')) {
                $table->boolean('external_widget_enabled')->default(false)->after('url');
            }

            if (! Schema::hasColumn('project_environments', 'external_widget_guest_submissions_enabled')) {
                $table->boolean('external_widget_guest_submissions_enabled')->default(false)->after('external_widget_enabled');
            }
        });

        $environments = DB::table('project_environments')
            ->join('projects', 'project_environments.project_id', '=', 'projects.id')
            ->select([
                'project_environments.id',
                'projects.external_widget_enabled',
                'projects.external_widget_guest_submissions_enabled',
            ])
            ->orderBy('project_environments.id')
            ->get();

        foreach ($environments as $environment) {
            DB::table('project_environments')
                ->where('id', $environment->id)
                ->update([
                    'external_widget_enabled' => (bool) $environment->external_widget_enabled,
                    'external_widget_guest_submissions_enabled' => (bool) $environment->external_widget_guest_submissions_enabled,
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('project_environments')) {
            return;
        }

        Schema::table('project_environments', function (Blueprint $table) {
            if (Schema::hasColumn('project_environments', 'external_widget_guest_submissions_enabled')) {
                $table->dropColumn('external_widget_guest_submissions_enabled');
            }

            if (Schema::hasColumn('project_environments', 'external_widget_enabled')) {
                $table->dropColumn('external_widget_enabled');
            }
        });
    }
};
