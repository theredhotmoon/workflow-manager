// frontend/e2e/run.mjs — what `npm run test:e2e` runs.
//
// The e2e global setup wipes and re-seeds the database of the `shift_changer_backend` container,
// and refuses to unless E2E_ALLOW_RESET=1 (see global-setup.js). Running the suite through this
// script is that consent; it sets the variable in a way that works on Windows (cmd/PowerShell) and
// POSIX shells alike, then hands every argument on to `playwright test`:
//
//   npm run test:e2e
//   npm run test:e2e -- e2e/schedule --headed
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';

const cli = createRequire(import.meta.url).resolve('@playwright/test/cli');
const { status, error } = spawnSync(process.execPath, [cli, 'test', ...process.argv.slice(2)], {
  stdio: 'inherit',
  env: { ...process.env, E2E_ALLOW_RESET: '1' },
});
if (error) throw error;
process.exit(status ?? 1);
