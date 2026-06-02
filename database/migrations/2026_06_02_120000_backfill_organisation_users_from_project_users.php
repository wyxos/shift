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
        DB::table('project_users as pu')
            ->join('projects as p', 'p.id', '=', 'pu.project_id')
            ->leftJoin('clients as c', 'c.id', '=', 'p.client_id')
            ->select([
                'pu.user_id',
                'pu.user_email',
                'pu.user_name',
                DB::raw('COALESCE(p.organisation_id, c.organisation_id) as organisation_id'),
            ])
            ->whereNotNull(DB::raw('COALESCE(p.organisation_id, c.organisation_id)'))
            ->orderBy('pu.id')
            ->chunk(100, function ($projectUsers): void {
                foreach ($projectUsers as $projectUser) {
                    $existing = DB::table('organisation_users')
                        ->where('organisation_id', $projectUser->organisation_id)
                        ->where(function ($query) use ($projectUser) {
                            $query->whereRaw('LOWER(user_email) = LOWER(?)', [$projectUser->user_email]);

                            if ($projectUser->user_id) {
                                $query->orWhere('user_id', $projectUser->user_id);
                            }
                        })
                        ->first();

                    if ($existing) {
                        if ($projectUser->user_id && ! $existing->user_id) {
                            DB::table('organisation_users')
                                ->where('id', $existing->id)
                                ->update([
                                    'user_id' => $projectUser->user_id,
                                    'updated_at' => now(),
                                ]);
                        }

                        continue;
                    }

                    DB::table('organisation_users')->insert([
                        'organisation_id' => $projectUser->organisation_id,
                        'user_id' => $projectUser->user_id,
                        'user_email' => $projectUser->user_email,
                        'user_name' => $projectUser->user_name,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
