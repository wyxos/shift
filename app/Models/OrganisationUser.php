<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganisationUser extends Model
{
    /** @use HasFactory */
    use HasFactory;

    protected $guarded = ['id'];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function matchingIdentity(Organisation $organisation, ?User $user, string $email): Builder
    {
        return static::query()
            ->where('organisation_id', $organisation->id)
            ->where(function (Builder $query) use ($user, $email) {
                $query->whereRaw('LOWER(user_email) = LOWER(?)', [$email]);

                if ($user) {
                    $query->orWhere('user_id', $user->id);
                }
            });
    }

    public static function ensureForIdentity(Organisation $organisation, ?User $user, string $email, string $name): self
    {
        $organisationUser = static::matchingIdentity($organisation, $user, $email)->first();

        if (! $organisationUser) {
            return static::create([
                'organisation_id' => $organisation->id,
                'user_id' => $user?->id,
                'user_email' => $email,
                'user_name' => $name,
            ]);
        }

        $attributes = [];

        if ($user && ! $organisationUser->user_id) {
            $attributes['user_id'] = $user->id;
        }

        if ($organisationUser->user_name !== $name && filled($name)) {
            $attributes['user_name'] = $name;
        }

        if ($attributes !== []) {
            $organisationUser->forceFill($attributes)->save();
        }

        return $organisationUser;
    }
}
