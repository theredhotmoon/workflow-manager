// frontend/e2e/global-setup.js
import { execFileSync } from 'node:child_process';

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
    [
      'exec',
      '-u',
      'www-data',
      '-e',
      'APP_ENV=testing',
      'shift_changer_backend',
      'php',
      'artisan',
      ...args,
    ],
    { stdio: 'pipe' },
  );
}

export default function globalSetup() {
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
