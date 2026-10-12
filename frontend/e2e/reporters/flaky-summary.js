// frontend/e2e/reporters/flaky-summary.js
//
// CI retries a failed test once (playwright.config.js), so a single flake no longer fails the job.
// This reporter keeps those flakes visible: tests that failed first and passed on the retry are
// listed in the GitHub Actions job summary (and on stdout). It never changes the run's result.
import { appendFileSync } from 'node:fs';
import { relative } from 'node:path';

export default class FlakySummaryReporter {
  onBegin(config, suite) {
    this.rootDir = config.rootDir;
    this.suite = suite;
  }

  onEnd() {
    const flaky = this.suite.allTests().filter((test) => test.outcome() === 'flaky');
    if (flaky.length === 0) return;

    const rows = flaky.map((test) => {
      const file = relative(this.rootDir, test.location.file).split('\\').join('/');
      const title = test.titlePath().filter(Boolean).slice(2).join(' › ');
      return `- \`e2e/${file}:${test.location.line}\` ${title}`;
    });
    const text = `### Flaky e2e tests (${flaky.length}): failed, then passed on retry\n\n${rows.join('\n')}\n`;

    console.log(`\n${text}`);
    if (process.env.GITHUB_STEP_SUMMARY) {
      appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${text}\n`);
    }
  }

  printsToStdio() {
    return false;
  }
}
