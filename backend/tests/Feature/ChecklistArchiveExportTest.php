<?php

use App\Models\ChecklistSubmission;
use App\Models\ChecklistTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// M23: the archive listed (and exported) at most 1000 handovers. The list is now paginated on the
// server and the CSV comes from a streamed endpoint that exports every matching row, with the same
// filters and the same per-user scoping as the list.

function caeTemplate(string $name = 'Handover'): ChecklistTemplate
{
    return ChecklistTemplate::create(['name' => $name, 'is_active' => true]);
}

/** Inserts $count submissions in one query (dates counting back from 2026-10-09). */
function caeInsert(int $count, ChecklistTemplate $template, User $outgoing, ?User $incoming = null, array $overrides = []): void
{
    $now = now();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = array_merge([
            'checklist_template_id' => $template->id,
            'date' => $now->copy()->setDate(2026, 10, 9)->subDays($i % 400)->toDateString(),
            'outgoing_user_id' => $outgoing->id,
            'incoming_user_id' => $incoming?->id,
            'submitted_by_user_id' => $outgoing->id,
            'status' => 'completed',
            'submitted_at' => $now,
            'confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('checklist_submissions')->insert($chunk);
    }
}

/** The streamed CSV as rows of cells (header first), after checking and dropping the UTF-8 BOM. */
function caeCsv($response): array
{
    $content = $response->streamedContent();
    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF");
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, substr($content, 3));
    rewind($handle);
    $rows = [];
    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

const CAE_HEADER = ['Date', 'Template', 'Outgoing Employee', 'Incoming Employee', 'Status', 'Timing', 'Submitted At'];

it('exports every matching row, beyond the old 1000 cap', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    caeInsert(1100, caeTemplate(), $admin);

    $response = $this->actingAs($admin)->get('/api/checklist-submissions/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('attachment; filename=checklist-archive-');
    $rows = caeCsv($response);
    expect($rows[0])->toBe(CAE_HEADER)
        ->and(count($rows))->toBe(1101);
});

it('exports only the rows matching the filters', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $alice = User::factory()->create(['name' => 'Alice Export']);
    $bob = User::factory()->create(['name' => 'Bob Export']);
    $doors = caeTemplate('Doors');
    $lights = caeTemplate('Lights');
    caeInsert(3, $doors, $alice, null, ['date' => '2026-10-01']);
    caeInsert(2, $doors, $bob, $alice, ['date' => '2026-10-02', 'status' => 'pending_confirmation', 'confirmed_at' => null]);
    caeInsert(4, $lights, $bob, null, ['date' => '2026-09-01']);

    $count = fn (array $query) => count(caeCsv($this->actingAs($admin)
        ->get('/api/checklist-submissions/export?' . http_build_query($query)))) - 1;

    expect($count([]))->toBe(9)
        ->and($count(['template_id' => $doors->id]))->toBe(5)
        ->and($count(['status' => 'pending_confirmation']))->toBe(2)
        ->and($count(['date_from' => '2026-10-01', 'date_to' => '2026-10-01']))->toBe(3)
        ->and($count(['user_id' => $alice->id]))->toBe(5)
        ->and($count(['search' => 'lights']))->toBe(4)
        ->and($count(['search' => 'alice', 'status' => 'completed']))->toBe(3);
});

it('scopes the export like the list: employees get only their own handovers', function () {
    $alice = User::factory()->create(['name' => 'Alice Scope']);
    $bob = User::factory()->create(['name' => 'Bob Scope']);
    $carol = User::factory()->create(['name' => 'Carol Scope']);
    $template = caeTemplate();
    caeInsert(2, $template, $alice);              // Alice's own
    caeInsert(1, $template, $bob, $alice);        // Alice is incoming
    caeInsert(5, $template, $bob, $carol);        // not Alice's

    $rows = caeCsv($this->actingAs($alice)->get('/api/checklist-submissions/export'));
    expect(count($rows) - 1)->toBe(3);
    foreach (array_slice($rows, 1) as $row) {
        expect(in_array('Alice Scope', [$row[2], $row[3]], true))->toBeTrue();
    }

    // A filter can't widen it either.
    $rows = caeCsv($this->actingAs($alice)->get("/api/checklist-submissions/export?user_id={$carol->id}"));
    expect(count($rows) - 1)->toBe(0);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/checklist-submissions/export')->assertUnauthorized();
});

it('escapes commas, quotes and newlines, and defuses spreadsheet formulas', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $tricky = User::factory()->create(['name' => '=HYPERLINK("http://x.test","click")']);
    $plus = User::factory()->create(['name' => '+48 Plus']);
    // Filed second, so exported first (newest filed first).
    caeInsert(1, caeTemplate('-1 Minus'), User::factory()->create(['name' => '@SUM(A1)']), null, ['date' => '2026-10-04']);
    caeInsert(1, caeTemplate("Doors, \"front\"\nand back"), $tricky, $plus, ['date' => '2026-10-05']);

    $response = $this->actingAs($admin)->get('/api/checklist-submissions/export');
    $rows = caeCsv($response);

    expect($rows)->toHaveCount(3)
        ->and($rows[1][0])->toBe('2026-10-05')
        ->and($rows[1][1])->toBe("Doors, \"front\"\nand back")
        ->and($rows[1][2])->toBe("'=HYPERLINK(\"http://x.test\",\"click\")")
        ->and($rows[1][3])->toBe("'+48 Plus")
        ->and($rows[1][4])->toBe('Completed')
        ->and($rows[2][1])->toBe("'-1 Minus")
        ->and($rows[2][2])->toBe("'@SUM(A1)")
        ->and($rows[2][3])->toBe('');
    // The raw text quotes the cell holding the comma, quotes and newline.
    expect($response->streamedContent())->toContain("\"Doors, \"\"front\"\"\nand back\"");
});

it('paginates the list on the server and caps the page size', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    caeInsert(130, caeTemplate(), $admin);
    caeInsert(5, caeTemplate('Pending one'), $admin, null, ['status' => 'pending_confirmation', 'confirmed_at' => null]);

    $page = $this->actingAs($admin)->getJson('/api/checklist-submissions?per_page=1000&page=2')
        ->assertOk()
        ->json();

    expect($page['per_page'])->toBe(100)
        ->and($page['current_page'])->toBe(2)
        ->and($page['total'])->toBe(135)
        ->and($page['data'])->toHaveCount(35)
        ->and($page['status_counts'])->toBe(['completed' => 130, 'pending_confirmation' => 5, 'draft' => 0]);
});

it('searches and sorts the list on the server', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $zed = User::factory()->create(['name' => 'Zed Sorter']);
    caeInsert(1, caeTemplate('Alpha checks'), $zed, null, ['date' => '2026-10-01']);
    caeInsert(1, caeTemplate('Beta checks'), $admin, null, ['date' => '2026-10-03']);
    caeInsert(1, caeTemplate('Gamma checks'), $admin, $zed, ['date' => '2026-10-02']);

    $names = fn (array $query) => collect($this->actingAs($admin)
        ->getJson('/api/checklist-submissions?' . http_build_query($query))->json('data'))
        ->pluck('template.name')->all();

    expect($names([]))->toBe(['Beta checks', 'Gamma checks', 'Alpha checks'])
        ->and($names(['sort' => 'template', 'direction' => 'asc']))->toBe(['Alpha checks', 'Beta checks', 'Gamma checks'])
        ->and($names(['sort' => 'outgoing', 'direction' => 'desc'])[0])->toBe('Alpha checks')
        ->and($names(['search' => 'zed']))->toBe(['Gamma checks', 'Alpha checks']);

    $this->actingAs($admin)->getJson('/api/checklist-submissions?sort=password')->assertUnprocessable();
});

it('starts the CSV with a UTF-8 BOM so Excel reads Polish names right', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    caeInsert(1, caeTemplate('Zmiana'), User::factory()->create(['name' => 'Kuba Urbańczyk']));

    $response = $this->actingAs($admin)->get('/api/checklist-submissions/export');

    expect(str_starts_with($response->streamedContent(), "\xEF\xBB\xBFDate,Template,"))->toBeTrue()
        ->and(caeCsv($response)[1][2])->toBe('Kuba Urbańczyk');
});

it('exports newest filed first (by id) across chunks, whatever the list sort', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $template = caeTemplate();
    // Filed in this order; their handover dates deliberately disagree with it.
    foreach (['2026-10-01', '2026-10-09', '2026-10-05'] as $date) {
        caeInsert(1, $template, $admin, null, ['date' => $date]);
    }
    // Enough later rows for several 500-row chunks.
    caeInsert(1200, $template, $admin, null, ['date' => '2026-01-01']);

    $rows = caeCsv($this->actingAs($admin)->get('/api/checklist-submissions/export?sort=template&direction=asc'));

    expect(count($rows) - 1)->toBe(1203)
        ->and(array_column(array_slice($rows, -3), 0))->toBe(['2026-10-05', '2026-10-09', '2026-10-01']);
});
