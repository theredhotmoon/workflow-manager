<?php

namespace App\Models;

use App\Services\ProcedureHtmlSanitizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Procedure extends Model
{
    protected $fillable = [
        'identifier',
        'title',
        'purpose',
        'requirements',
        'content',
        'version',
        'owner_id',
        'parent_id',
        'sort_order',
        'has_draft',
        'draft_title',
        'draft_purpose',
        'draft_requirements',
        'draft_content',
    ];

    protected $casts = [
        'has_draft' => 'boolean',
        'archived_at' => 'datetime',
    ];

    /**
     * Procedures people read and plan with. An archived one keeps its versions, FAQ, feedback, reads
     * and access log, but is left out of the tree, search, lists, pickers and unread counts.
     */
    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * {procedure} in a route: an archived procedure is found for admins only (they see it with an
     * Archived banner and can restore it); everyone else gets 404, as if it didn't exist.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->resolveRouteBindingQuery($this->newQuery(), $value, $field)
            ->when(!request()->user()?->isAdmin(), fn ($query) => $query->whereNull('archived_at'))
            ->first();
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function tags()
    {
        return $this->belongsToMany(ProcedureTag::class, 'procedure_tag', 'procedure_id', 'tag_id');
    }

    public function faqItems()
    {
        return $this->hasMany(ProcedureFaqItem::class);
    }

    public function responseTemplates()
    {
        return $this->hasMany(ProcedureResponseTemplate::class);
    }

    public function accessLogs()
    {
        return $this->hasMany(ProcedureAccessLog::class);
    }

    public function feedbacks()
    {
        return $this->hasMany(ProcedureFeedback::class);
    }

    public function userReads()
    {
        return $this->hasMany(ProcedureUserRead::class);
    }

    public function versions()
    {
        return $this->hasMany(ProcedureVersion::class);
    }

    /**
     * Get the version this user last read.
     */
    public function latestReadVersion(int $userId): ?int
    {
        return $this->userReads()->where('user_id', $userId)->value('version_read');
    }

    /**
     * Generate the next PROC-XX identifier.
     */
    public static function nextIdentifier(): string
    {
        $last = static::orderByDesc('id')->value('identifier');
        $num = $last ? ((int) str_replace('PROC-', '', $last)) + 1 : 1;
        return 'PROC-' . str_pad($num, 2, '0', STR_PAD_LEFT);
    }

    public function ownerChanges()
    {
        return $this->hasMany(ProcedureOwnerChange::class);
    }

    /** Rendered with v-html: sanitised on every write (see ProcedureHtmlSanitizer). */
    protected function content(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => app(ProcedureHtmlSanitizer::class)->clean($value));
    }

    /** Rendered with v-html: sanitised on every write (see ProcedureHtmlSanitizer). */
    protected function draftContent(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => app(ProcedureHtmlSanitizer::class)->clean($value));
    }
}
