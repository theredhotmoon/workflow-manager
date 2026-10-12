// frontend/e2e/fixtures/cleanup.js
//
// Cleanup for what a spec creates (users to deactivate, groups to archive, contexts to close).
// Each step is added right after its thing is created and run in a `finally`, in reverse order,
// each on its own: one failing step, or a test failing half-way through its setup, doesn't skip
// the others. The first error is rethrown once every step has run.
//
//   const cleanup = cleanupStack();
//   try {
//     const user = await createUser(admin, { ... });
//     cleanup.add(() => deactivate(admin, user.id));
//     ...
//   } finally {
//     await cleanup.run();
//   }
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
      if (errors.length) throw errors[0];
    },
  };
}
