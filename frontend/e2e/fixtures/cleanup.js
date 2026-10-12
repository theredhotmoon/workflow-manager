// frontend/e2e/fixtures/cleanup.js
//
// Cleanup for what a spec creates (users to deactivate, groups to archive, contexts to close).
// Each step is added right after its thing is created and run in a `finally`, in reverse order,
// each on its own: one failing step, or a test failing half-way through its setup, doesn't skip
// the others. Failed steps are reported with `expect.soft` once every step has run: a test that
// passed still fails, but a test that already failed keeps its own error as the reported cause
// (an error thrown from `finally` would replace it).
//
//   const cleanup = cleanupStack();
//   try {
//     const user = await createUser(admin, { ... });
//     cleanup.add(() => deactivate(admin, user.id));
//     ...
//   } finally {
//     await cleanup.run();
//   }
import { expect } from '@playwright/test';

export function cleanupStack() {
  const steps = [];
  return {
    add(step) {
      steps.push(step);
    },
    async run() {
      const errors = [];
      for (const step of steps.splice(0).reverse()) {
        try {
          await step();
        } catch (error) {
          errors.push(error);
        }
      }
      expect.soft(errors.map(String), 'cleanup steps failed').toEqual([]);
    },
  };
}
