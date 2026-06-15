<?php

use App\Enums\ExternalUserRole;
use App\Enums\OrganisationRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organisation_users', 'role')) {
            Schema::table('organisation_users', function (Blueprint $table) {
                $table->string('role')->default(OrganisationRole::Developer->value)->after('user_name');
            });
        }

        if (! Schema::hasColumn('external_users', 'role')) {
            Schema::table('external_users', function (Blueprint $table) {
                $table->string('role')->default(ExternalUserRole::User->value)->after('external_id');
            });
        }

        DB::table('organisation_users')->update([
            'role' => OrganisationRole::Developer->value,
        ]);

        DB::table('organisations')
            ->whereNotNull('author_id')
            ->orderBy('id')
            ->each(function ($organisation) {
                $author = DB::table('users')->where('id', $organisation->author_id)->first();

                if (! $author) {
                    return;
                }

                $existing = DB::table('organisation_users')
                    ->where('organisation_id', $organisation->id)
                    ->where(function ($query) use ($author) {
                        $query->where('user_id', $author->id)
                            ->orWhereRaw('LOWER(user_email) = LOWER(?)', [$author->email]);
                    })
                    ->first();

                if ($existing) {
                    DB::table('organisation_users')
                        ->where('id', $existing->id)
                        ->update([
                            'user_id' => $author->id,
                            'user_email' => $author->email,
                            'user_name' => $author->name,
                            'role' => OrganisationRole::Administrator->value,
                            'updated_at' => now(),
                        ]);

                    return;
                }

                DB::table('organisation_users')->insert([
                    'organisation_id' => $organisation->id,
                    'user_id' => $author->id,
                    'user_email' => $author->email,
                    'user_name' => $author->name,
                    'role' => OrganisationRole::Administrator->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('external_users', 'role')) {
            Schema::table('external_users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }

        if (Schema::hasColumn('organisation_users', 'role')) {
            Schema::table('organisation_users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }
};
