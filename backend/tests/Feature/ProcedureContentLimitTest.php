<?php

use App\Models\Procedure;
use App\Models\User;

// Request bodies are capped at 2 MB in Nginx except on the procedure writes (create, update, draft),
// which keep 50 MB because pasted images are stored inside the HTML as data: URLs. The content
// itself has a limit below that, so an oversized procedure is a clear 422, not a 413 or a 500.

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
});

/** A PNG of about $bytes (valid header, random pixel data) as a base64 data: URL. */
function pclPngDataUrl(int $bytes): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $png = "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', 1000, 1000, 8, 2, 0, 0, 0))
        .$chunk('IDAT', random_bytes($bytes))
        .$chunk('IEND', '');

    return 'data:image/png;base64,'.base64_encode($png);
}

function pclProcedure(User $owner): Procedure
{
    $id = test()->actingAs($owner)->postJson('/api/procedures', ['title' => 'Base', 'owner_id' => $owner->id, 'content' => '<p>x</p>'])
        ->assertCreated()->json('id');

    return Procedure::findOrFail($id);
}

it('saves a procedure with a pasted image of a few MB', function () {
    $src = pclPngDataUrl(3 * 1024 * 1024);
    $html = '<p>Wiring</p><img src="'.$src.'" alt="board">';

    $this->actingAs($this->admin)
        ->postJson('/api/procedures', ['title' => 'Rack wiring', 'owner_id' => $this->admin->id, 'content' => $html])
        ->assertCreated();

    expect(Procedure::where('title', 'Rack wiring')->sole()->content)->toContain($src);
});

it('refuses content over the limit with a 422 on create, update and draft', function () {
    $tooLong = '<p>'.str_repeat('a', Procedure::CONTENT_MAX_LENGTH).'</p>';
    $procedure = pclProcedure($this->admin);

    $this->actingAs($this->admin)
        ->postJson('/api/procedures', ['title' => 'Huge', 'owner_id' => $this->admin->id, 'content' => $tooLong])
        ->assertUnprocessable()->assertJsonValidationErrors('content');
    $this->actingAs($this->admin)
        ->putJson("/api/procedures/{$procedure->id}", ['content' => $tooLong])
        ->assertUnprocessable()->assertJsonValidationErrors('content');
    $this->actingAs($this->admin)
        ->putJson("/api/procedures/{$procedure->id}/draft", ['title' => 'Base', 'content' => $tooLong])
        ->assertUnprocessable()->assertJsonValidationErrors('content');

    expect(Procedure::where('title', 'Huge')->exists())->toBeFalse()
        ->and($procedure->fresh()->content)->toBe('<p>x</p>')
        ->and($procedure->fresh()->draft_content)->toBeNull();
});
