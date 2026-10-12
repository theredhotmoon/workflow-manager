<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Alarm extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'type', 'user_id', 'short_message', 'details',
        'link_url', 'link_label', 'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (! $model->id) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function markAsRead()
    {
        if (! $this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }

    // All alarm types
    const TYPE_EMPLOYEE_CANNOT_ATTEND_SHIFT = 'EMPLOYEE_CANNOT_ATTEND_SHIFT';

    const TYPE_PROCEDURE_NEW_ISSUE_REPORTED = 'PROCEDURE_NEW_ISSUE_REPORTED';

    const TYPE_PROCEDURE_NEW_QUESTION_ADDED = 'PROCEDURE_NEW_QUESTION_ADDED';

    const TYPE_PROCEDURE_QUESTION_REPLIED = 'PROCEDURE_QUESTION_REPLIED';

    const TYPE_PROCEDURE_ISSUE_SORTED = 'PROCEDURE_ISSUE_SORTED';

    const TYPE_SHIFT_CHANGE_REQUEST_ACKNOWLEDGED = 'SHIFT_CHANGE_REQUEST_ACKNOWLEDGED';

    const ALL_TYPES = [
        self::TYPE_EMPLOYEE_CANNOT_ATTEND_SHIFT,
        self::TYPE_PROCEDURE_NEW_ISSUE_REPORTED,
        self::TYPE_PROCEDURE_NEW_QUESTION_ADDED,
        self::TYPE_PROCEDURE_QUESTION_REPLIED,
        self::TYPE_PROCEDURE_ISSUE_SORTED,
        self::TYPE_SHIFT_CHANGE_REQUEST_ACKNOWLEDGED,
    ];
}
