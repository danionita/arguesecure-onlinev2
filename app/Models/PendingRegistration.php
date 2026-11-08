<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Someone who asked for an account and has not yet proved the address. No
 * `users` row exists for them, and the request carries no name or password:
 * those are chosen only after the address is proved.
 *
 * @property int $id
 * @property string $email
 */
class PendingRegistration extends Model
{
    /** @var list<string> */
    protected $fillable = ['email'];
}
