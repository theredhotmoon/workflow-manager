<?php

use App\Models\Procedure;
use App\Models\ProcedureVersion;
use App\Models\User;
use App\Services\ProcedureHtmlSanitizer;

// M3: procedure HTML (rendered with v-html) is sanitised on every write, against the TipTap schema
// the editor uses (StarterKit + Image + Link + Underline).

/** What the editor's getHTML() produces for every node and mark it has (captured from the app). */
const PSAN_TIPTAP = '<h1>Title</h1><h2>Sub</h2><h3>Third</h3><p>Plain <strong>bold</strong> <em>it</em> <u>under</u> <s>strike</s> <code>inline</code> and <a target="_blank" rel="noopener noreferrer nofollow" href="https://example.com/a?b=1&amp;c=2">a link</a> and <a target="_blank" rel="noopener noreferrer nofollow" href="mailto:ops@example.com">mail</a>.<br>after break</p><ul><li><p>One</p></li><li><p>Two</p><ul><li><p>Nested</p></li></ul></li></ul><ol start="3"><li><p>Three</p></li></ol><ol><li><p>First</p></li></ol><blockquote><p>Quote</p></blockquote><pre><code class="language-bash">echo "hi" &amp;&amp; ls &lt;dir&gt;</code></pre><pre><code>plain code</code></pre><hr><p></p><img src="https://example.com/x.png" alt="diagram" title="Diagram"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==" alt="pasted"><p></p>';

const PSAN_EVIL = '<p>Hi<img src=x onerror=alert(1)></p>'
    .'<script>alert(2)</script>'
    .'<p><a href="javascript:alert(3)">js link</a> <a href="JaVaScRiPt:alert(4)">mixed case</a> '
    .'<a href="https://ok.example" onclick="alert(5)">click</a> '
    .'<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCg2KTwvc2NyaXB0Pg==">data link</a></p>'
    .'<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoNyk+">'
    .'<iframe src="https://evil.example"></iframe><style>body{display:none}</style>'
    .'<p style="position:fixed" class="fixed inset-0">styled</p><form action="/x"><input name="p"></form>'
    .'<pre><code class="fixed inset-0">x</code></pre>';

function psanAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function psanAssertClean(?string $html): void
{
    expect($html)->not->toBeNull();
    foreach (['<script', 'alert(2)', 'onerror', 'onclick', 'javascript:', 'data:text/html', 'svg', '<iframe',
        '<style', 'style=', 'fixed inset-0', '<form', '<input'] as $bad) {
        expect(stripos($html, $bad))->toBeFalse("'{$bad}' survived in: {$html}");
    }
    // The harmless text and the safe link are kept.
    expect($html)->toContain('Hi')->toContain('href="https://ok.example"')->toContain('styled');
}

it('strips script, event handlers and dangerous links from content on create', function () {
    $admin = psanAdmin();
    $id = test()->actingAs($admin)->postJson('/api/procedures', [
        'title' => 'Evil', 'owner_id' => $admin->id, 'content' => PSAN_EVIL,
    ])->assertCreated()->json('id');

    psanAssertClean(Procedure::find($id)->content);
    psanAssertClean(ProcedureVersion::where('procedure_id', $id)->value('content'));
});

it('strips them from drafts, from what a draft publishes, and from a direct update', function () {
    $admin = psanAdmin();
    $procedure = Procedure::create(['identifier' => 'PSAN-1', 'title' => 'T', 'content' => '<p>v1</p>', 'owner_id' => $admin->id, 'version' => 1]);

    test()->actingAs($admin)->putJson("/api/procedures/{$procedure->id}/draft", ['title' => 'T', 'content' => PSAN_EVIL])->assertOk();
    psanAssertClean($procedure->fresh()->draft_content);

    test()->actingAs($admin)->postJson("/api/procedures/{$procedure->id}/publish", ['change_reason' => 'r'])->assertOk();
    psanAssertClean($procedure->fresh()->content);
    psanAssertClean(ProcedureVersion::where('procedure_id', $procedure->id)->where('version', 2)->value('content'));

    test()->actingAs($admin)->putJson("/api/procedures/{$procedure->id}", ['content' => '<p>x</p>'.PSAN_EVIL])->assertOk();
    psanAssertClean($procedure->fresh()->content);
});

it('keeps the editor\'s own markup unchanged', function () {
    $admin = psanAdmin();
    $id = test()->actingAs($admin)->postJson('/api/procedures', [
        'title' => 'Rich', 'owner_id' => $admin->id, 'content' => PSAN_TIPTAP,
    ])->assertCreated()->json('id');
    expect(Procedure::find($id)->content)->toBe(PSAN_TIPTAP);

    test()->actingAs($admin)->putJson("/api/procedures/{$id}/draft", ['title' => 'Rich', 'content' => PSAN_TIPTAP])->assertOk();
    expect(Procedure::find($id)->draft_content)->toBe(PSAN_TIPTAP);
});

it('does not publish a new version when the same editor markup is saved again', function () {
    $admin = psanAdmin();
    $id = test()->actingAs($admin)->postJson('/api/procedures', [
        'title' => 'Rich', 'owner_id' => $admin->id, 'content' => PSAN_TIPTAP,
    ])->assertCreated()->json('id');

    test()->actingAs($admin)->putJson("/api/procedures/{$id}", ['content' => PSAN_TIPTAP])->assertOk();
    // Different but equivalent once sanitised (the handler is dropped): still no new version.
    test()->actingAs($admin)->putJson("/api/procedures/{$id}", [
        'content' => str_replace('<h1>Title</h1>', '<h1 onclick="x()">Title</h1>', PSAN_TIPTAP),
    ])->assertOk();
    expect(Procedure::find($id)->version)->toBe(1);
});

it('adds noopener to a new-tab link and keeps only the link schemes it allows', function () {
    $clean = app(ProcedureHtmlSanitizer::class);

    expect($clean->clean('<p><a href="https://x.example" target="_blank">x</a></p>'))
        ->toBe('<p><a href="https://x.example" target="_blank" rel="noreferrer noopener">x</a></p>');
    expect($clean->clean('<p><a href="ftp://x.example/f">f</a><a href="vbscript:x">v</a></p>'))
        ->toBe('<p><a>f</a><a>v</a></p>');
    expect($clean->clean(null))->toBeNull();
    expect($clean->clean(''))->toBe('');
    // Sanitising is idempotent.
    $once = $clean->clean(PSAN_EVIL);
    expect($clean->clean($once))->toBe($once);
});

it('sanitises procedures stored before the fix', function () {
    $admin = psanAdmin();
    // Written straight to the table, bypassing the model, as old rows were.
    $id = DB::table('procedures')->insertGetId([
        'identifier' => 'PSAN-OLD', 'title' => 'Old', 'content' => PSAN_EVIL, 'draft_content' => PSAN_EVIL,
        'has_draft' => true, 'owner_id' => $admin->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('procedure_versions')->insert([
        'procedure_id' => $id, 'version' => 1, 'title' => 'Old', 'content' => PSAN_EVIL,
        'change_reason' => 'Initial version', 'published_by' => $admin->id, 'published_at' => now(),
    ]);
    $tidy = DB::table('procedures')->insertGetId([
        'identifier' => 'PSAN-OK', 'title' => 'Fine', 'content' => PSAN_TIPTAP,
        'owner_id' => $admin->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration = include database_path('migrations/2026_10_09_120100_sanitize_stored_procedure_html.php');
    $migration->up();
    $migration->up(); // idempotent

    psanAssertClean(DB::table('procedures')->where('id', $id)->value('content'));
    psanAssertClean(DB::table('procedures')->where('id', $id)->value('draft_content'));
    psanAssertClean(DB::table('procedure_versions')->where('procedure_id', $id)->value('content'));
    expect(DB::table('procedures')->where('id', $tidy)->value('content'))->toBe(PSAN_TIPTAP);
});
