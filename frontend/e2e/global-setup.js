// frontend/e2e/global-setup.js
import { execFileSync } from 'node:child_process';

export default function globalSetup() {
  try {
    // -e APP_ENV=testing: docker-compose.yml fixes the container's APP_ENV to "production", and
    // Laravel's ConfirmableTrait prompts interactively for confirmation before running a destructive
    // command (migrate:fresh, db:seed) against what it thinks is production — which hangs/fails under
    // execSync (no stdin). Overriding APP_ENV per-command (discovered + verified during Task 1) avoids
    // the prompt without needing --force and without changing the container's real environment.
    //
    // execFileSync (argv array, no shell) instead of execSync (single shell-command string):
    // discovered during Task 3 that a shell-string execSync spawns via cmd.exe on Windows, which
    // (unlike a POSIX shell) does not collapse the `\\` needed to produce a literal single backslash
    // inside `--class="Database\\Seeders\\..."` — so PHP receives the class name with a literal double
    // backslash and `class_exists()` fails ("Target class ... does not exist"), even though the class
    // is present and correctly autoloaded (confirmed directly via docker exec). Passing argv as an
    // array bypasses host-shell string parsing entirely, so the class name reaches PHP unchanged on
    // both Windows and POSIX. Verified via direct node invocation during this task.
    execFileSync(
      'docker',
      ['exec', '-e', 'APP_ENV=testing', 'shift_changer_backend', 'php', 'artisan', 'migrate:fresh'],
      { stdio: 'pipe' },
    );
    execFileSync(
      'docker',
      [
        'exec',
        '-e',
        'APP_ENV=testing',
        'shift_changer_backend',
        'php',
        'artisan',
        'db:seed',
        '--class=Database\\Seeders\\E2eBaselineSeeder',
      ],
      { stdio: 'pipe' },
    );
  } catch (err) {
    throw new Error(
      'e2e global setup failed to reset the backend DB via `docker exec shift_changer_backend`. ' +
        'Is the shift_changer_backend container running? (docker ps)\n' +
        (err.stderr?.toString() || err.message),
    );
  }
}
