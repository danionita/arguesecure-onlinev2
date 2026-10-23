<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property-read AssessmentUser $pivot present only when this instance was
 *  loaded through Assessment::members(); absent otherwise.
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property int|null $two_factor_last_step
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * is_admin and is_active are deliberately absent: they are set by the
     * admin area (a later package), never by a user editing their own
     * profile (security.md §7).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // W116: the second factor never leaves the server — not in an Inertia
        // prop (auth.user is this model), not in JSON, not in a log context.
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'two_factor_last_step',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
        ];
    }

    /**
     * W8b: clears the admin-set "must change password" flag the moment the
     * password itself changes, without needing to touch every controller
     * that can change a password (several — including
     * App\Http\Controllers\Auth\PasswordController — are outside this
     * package's file ownership). Only clears it when the flag wasn't *also*
     * being set in the same save() call, so
     * UserController::resetPassword() can set password and
     * must_change_password=true together in one save without this
     * immediately undoing it (security.md §4: an admin reset forces a
     * change on next login).
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('password') && ! $user->isDirty('must_change_password')) {
                $user->must_change_password = false;
            }
        });
    }

    /**
     * Whether a second factor is *confirmed* (D77). An enrolment that was
     * started and never finished leaves no trace in the row, so this cannot
     * be true for an account that has not proved it holds the secret.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /** @return HasMany<UserIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class);
    }

    /** @return HasMany<Assessment, $this> */
    public function ownedAssessments(): HasMany
    {
        return $this->hasMany(Assessment::class, 'owner_user_id');
    }

    /** @return BelongsToMany<Assessment, $this, AssessmentUser, 'pivot'> */
    public function sharedAssessments(): BelongsToMany
    {
        return $this->belongsToMany(Assessment::class, 'assessment_user')
            ->using(AssessmentUser::class)
            ->withPivot('role', 'invited_by', 'invited_at', 'accepted_at')
            ->withTimestamps();
    }
}
