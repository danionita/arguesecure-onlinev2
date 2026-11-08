<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An administrator's override of one message kind's subject and body. Only
 * App\Domain\Mail\MailTemplates reads and writes it.
 *
 * @property int $id
 * @property string $kind
 * @property string $subject
 * @property string $body
 */
class MailTemplate extends Model
{
    protected $fillable = ['kind', 'subject', 'body'];
}
