// frontend/e2e/global-setup.js
import { execFileSync } from 'node:child_process';

const CONTAINER = 'shift_changer_backend';

// Every call runs as www-data (-u): `docker exec` defaults to root, and a root-run artisan command
// leaves root-owned files in storage/logs that the php-fpm worker (www-data) then cannot write.
function artisan(...args) {
  // -e APP_ENV=testing: docker-compose.yml fixes the container's APP_ENV to "production", and
  // Laravel's ConfirmableTrait prompts interactively before running a destructive command
  // (migrate:fresh, db:seed) against what it thinks is production, which hangs/fails with no stdin.
  // Overriding APP_ENV per command avoids the prompt without --force and without changing the
  // container's real environment.
  //
  // execFileSync (argv array, no shell) rather than execSync (one command string): a shell string
  // is spawned via cmd.exe on Windows, which does not collapse the `\` needed to produce a single
  // backslash inside `--class="Database\\Seeders\\..."`, so PHP would receive a literal double
  // backslash and class_exists() would fail ("Target class ... does not exist"). An argv array
  // bypasses host-shell parsing, so the class name reaches PHP unchanged on Windows and POSIX.
  execFileSync(
    'docker',
    ['exec', '-u', 'www-data', '-e', 'APP_ENV=testing', CONTAINER, 'php', 'artisan', ...args],
    { stdio: 'pipe' },
  );
}

// Reads one environment variable of the backend container ('' when unset).
function containerEnv(name) {
  try {
    return execFileSync('docker', ['exec', CONTAINER, 'printenv', name], { stdio: 'pipe' })
      .toString()
      .trim();
  } catch {
    return '';
  }
}

// Wiping a database is only safe on purpose and against the local/CI stack. The container name is
// the same in production (docker-compose.yml), so `npx playwright test` on the server would
// otherwise reset the live data.
function assertResetAllowed() {
  if (process.env.E2E_ALLOW_RESET !== '1') {
    throw new Error(
      'e2e global setup refuses to reset the database without E2E_ALLOW_RESET=1. ' +
        'Run the suite with `npm run test:e2e` (it sets it), or set E2E_ALLOW_RESET=1 yourself ' +
        'to confirm that the database of the shift_changer_backend container may be wiped.',
    );
  }
  const appEnv = containerEnv('APP_ENV');
  const database = containerEnv('DB_DATABASE');
  if (!appEnv) {
    throw new Error(
      `e2e global setup could not read APP_ENV from the ${CONTAINER} container. ` +
        'Is it running? (docker ps)',
    );
  }
  // The dev override (docker-compose.dev.yml), which the e2e suite and CI run against, sets
  // APP_ENV=local; the production stack runs with APP_ENV=production.
  if (!['local', 'testing'].includes(appEnv) || /prod/i.test(database)) {
    throw new Error(
      `e2e global setup refuses to reset this database: the backend container runs with ` +
        `APP_ENV=${appEnv} and DB_DATABASE=${database || '(unset)'}. The suite ` +
        'only runs against the dev stack (docker compose -f docker-compose.yml ' +
        '-f docker-compose.dev.yml up -d db backend), which sets APP_ENV=local.',
    );
  }
}

export default function globalSetup() {
  assertResetAllowed();
  try {
    // Resets the API rate-limiter counters (cache-backed) at the start of each run. Safety net only:
    // docker-compose.dev.yml raises API_RATE_LIMIT so the suite doesn't normally get near it.
    artisan('cache:clear');
    artisan('migrate:fresh');
    artisan('db:seed', '--class=Database\\Seeders\\E2eBaselineSeeder');
  } catch (err) {
    throw new Error(
      'e2e global setup failed to reset the backend DB via `docker exec shift_changer_backend`. ' +
        'Is the shift_changer_backend container running? (docker ps)\n' +
        (err.stderr?.toString() || err.message),
    );
  }
}
