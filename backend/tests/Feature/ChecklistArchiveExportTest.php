<?php

use App\Models\ChecklistTemplate;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Support\Carbon;
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
        ->get('/api/checklist-submissions/export?'.http_build_query($query)))) - 1;

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
        ->getJson('/api/checklist-submissions?'.http_build_query($query))->json('data'))
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

/**
 * Inserts $count handovers with the same date and filing time (so only the id orders them) and a
 * submitted_at one minute apart: its CSV cell tells the rows apart. Templates and incoming users
 * cycle through the given lists (null = none).
 */
function caeInsertTied(int $count, array $templates, User $outgoing, array $incoming = [null], string $date = '2026-10-01', int $firstMinute = 0): void
{
    $filed = now()->setDate(2026, 10, 1)->setTime(12, 0);
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'checklist_template_id' => $templates[$i % count($templates)]->id,
            'date' => $date,
            'outgoing_user_id' => $outgoing->id,
            'incoming_user_id' => $incoming[$i % count($incoming)]?->id,
            'submitted_by_user_id' => $outgoing->id,
            'status' => 'completed',
            'submitted_at' => $filed->copy()->addMinutes($firstMinute + $i),
            'created_at' => $filed,
            'updated_at' => $filed,
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('checklist_submissions')->insert($chunk);
    }
}

/** The list's order for $query, read page by page (per_page 100), as Submitted At cells. */
function caeListOrder($test, User $admin, array $query): array
{
    $cells = [];
    for ($page = 1; ; $page++) {
        $res = $test->actingAs($admin)->getJson('/api/checklist-submissions?'.http_build_query($query + ['per_page' => 100, 'page' => $page]))->json();
        foreach ($res['data'] as $row) {
            $cells[] = Carbon::parse($row['submitted_at'])->setTimezone(BusinessDate::timezone())->format('Y-m-d H:i');
        }
        if ($page >= $res['last_page']) {
            return $cells;
        }
    }
}

it('exports in the list order, ties broken by id, without dropping or repeating rows across chunks', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $template = caeTemplate();
    // Filed first, but with handover dates on both sides of the rest: the list (newest handover
    // first) puts them at the top and the bottom, not in filing order.
    caeInsertTied(1, [$template], $admin, [null], '2026-10-09', 5000);
    caeInsertTied(1, [$template], $admin, [null], '2026-09-01', 5001);
    // 1203 rows tied on date and filing time: the 500-row chunks end inside the tie.
    caeInsertTied(1203, [$template], $admin);

    $rows = array_slice(caeCsv($this->actingAs($admin)->get('/api/checklist-submissions/export')), 1);
    $submitted = array_column($rows, 6);

    expect($rows)->toHaveCount(1205)
        ->and(array_unique($submitted))->toHaveCount(1205)
        ->and($submitted)->toBe(caeListOrder($this, $admin, []))
        ->and([$rows[0][0], $rows[1204][0]])->toBe(['2026-10-09', '2026-09-01'])
        // Within the tie, newest id first: the last inserted (latest submitted_at) leads.
        ->and($submitted[1])->toBeGreaterThan($submitted[1203]);
});

it('exports in the order of the list sort the screen sends, nulls included', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $templates = [caeTemplate('Bravo'), caeTemplate('Alpha'), caeTemplate('Charlie')];
    $incoming = [null, User::factory()->create(['name' => 'Ann']), User::factory()->create(['name' => 'Zoe'])];
    caeInsertTied(1100, $templates, $admin, $incoming);

    foreach ([['sort' => 'template', 'direction' => 'asc'], ['sort' => 'incoming', 'direction' => 'desc'],
        ['sort' => 'incoming', 'direction' => 'asc'], ['sort' => 'submitted_at', 'direction' => 'asc']] as $query) {
        $rows = array_slice(caeCsv($this->actingAs($admin)->get('/api/checklist-submissions/export?'.http_build_query($query))), 1);
        expect(array_column($rows, 6))->toBe(caeListOrder($this, $admin, $query), json_encode($query));
    }
});

it('exports in the list order for the timing (nullable boolean) and status sorts', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    caeInsertTied(1100, [caeTemplate()], $admin);
    // On time, late and not known (null); every status, so each sort has long ties across chunks.
    DB::statement("update checklist_submissions set
        submitted_on_time = case id % 3 when 0 then null when 1 then true else false end,
        status = case id % 4 when 0 then 'draft' when 1 then 'pending_confirmation' else 'completed' end");

    foreach ([['sort' => 'timing', 'direction' => 'asc'], ['sort' => 'timing', 'direction' => 'desc'],
        ['sort' => 'status', 'direction' => 'asc'], ['sort' => 'status', 'direction' => 'desc']] as $query) {
        $rows = array_slice(caeCsv($this->actingAs($admin)->get('/api/checklist-submissions/export?'.http_build_query($query))), 1);
        expect($rows)->toHaveCount(1100)
            ->and(array_column($rows, 6))->toBe(caeListOrder($this, $admin, $query), json_encode($query));
    }
    // The timing cells really vary (and blanks are there), so the boolean keyset was exercised.
    expect(array_unique(array_column($rows, 5)))->toEqualCanonicalizing(['On time', 'Late', '']);
});
