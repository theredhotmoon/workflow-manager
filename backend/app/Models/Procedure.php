<?php

namespace App\Models;

use App\Services\ProcedureHtmlSanitizer;
use App\Support\AdvisoryLock;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

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

    public const ARCHIVED_MESSAGE = 'This procedure is archived. Restore it before changing it.';

    /**
     * An archived procedure is frozen: no FAQ, reply, outdated toggle, feedback or response template
     * changes. Admins (who can see it) get 409 with ARCHIVED_MESSAGE; everyone else 404, as for the
     * procedure itself. Reads are not affected.
     */
    public function refuseWritesIfArchived(?User $user): void
    {
        if (! $this->isArchived()) {
            return;
        }
        if (! $user?->isAdmin()) {
            abort(404);
        }

        throw new HttpResponseException(response()->json(['message' => self::ARCHIVED_MESSAGE], 409));
    }

    /**
     * {procedure} in a route: an archived procedure is found for admins only (they see it with an
     * Archived banner and can restore it); everyone else gets 404, as if it didn't exist.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->resolveRouteBindingQuery($this->newQuery(), $value, $field)
            ->when(! request()->user()?->isAdmin(), fn ($query) => $query->whereNull('archived_at'))
            ->first();
    }

    /** The pending draft: shown only to admins and the procedure's owner (L3). */
    public const DRAFT_FIELDS = ['has_draft', 'draft_title', 'draft_purpose', 'draft_requirements', 'draft_content'];

    /**
     * Need-to-know (L3): admins and the procedure's owner. Decides who sees the pending draft (in
     * every payload that serialises a Procedure) and who may read the access log.
     */
    public function isAdminOrOwner(?User $viewer): bool
    {
        return $viewer !== null && ($viewer->isAdmin() || ($this->owner_id !== null && (int) $viewer->id === (int) $this->owner_id));
    }

    public function getHidden()
    {
        $hidden = parent::getHidden();

        return $this->isAdminOrOwner(User::viewer()) ? $hidden : [...$hidden, ...self::DRAFT_FIELDS];
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

    /** Advisory lock name that serialises identifier generation (see nextIdentifier()). */
    public const IDENTIFIER_LOCK = 'procedures.identifier';

    /**
     * The next PROC-XX identifier: one past the highest PROC number of every procedure, archived
     * ones included (procedures are never deleted, so a number is never handed out twice).
     * Call it inside the transaction that inserts the procedure: it takes an advisory lock held
     * until that commits, so a concurrent create waits and then sees this one's number.
     */
    public static function nextIdentifier(): string
    {
        AdvisoryLock::forTransaction(self::IDENTIFIER_LOCK);

        $highest = (int) static::query()
            ->whereRaw("identifier ~ '^PROC-[0-9]+$'")
            ->max(DB::raw('CAST(SUBSTRING(identifier FROM 6) AS BIGINT)'));

        return 'PROC-'.str_pad((string) ($highest + 1), 2, '0', STR_PAD_LEFT);
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
