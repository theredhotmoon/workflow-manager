# Frontend

Vue 3 (Composition API, `<script setup>`) + Vite.

## Linting & formatting

This project uses [Biome](https://biomejs.dev/) for linting and formatting (no ESLint/Prettier).

```bash
npm run lint          # biome check (lint + format + import order), fails on errors only; CI runs this
npm run lint:fix      # biome check --write (safe fixes only)
npm run format        # reformat all files
npm run format:check  # check formatting without writing
npm run check         # lint + format check combined
npm run check:fix     # lint + format fix combined (safe fixes only)
```

A git pre-commit hook (husky + lint-staged) runs `biome check --write` on staged `.js`/`.vue`
files automatically and blocks the commit if Biome reports an error.

### ⚠️ Never run Biome with `--unsafe` fixes on this codebase

Biome's Vue `<script setup>` support is experimental: it cannot see that an import is used
only inside the `<template>` block, and will flag components like `AppLayout` or
`ProcedureContent` (imported and used only as `<AppLayout>` / `<ProcedureContent>` in a
template) as unused imports. The `noUnusedImports` fix for that is classified as an *unsafe*
fix in Biome — meaning `biome check --write` (safe fixes only, what the pre-commit hook runs)
correctly leaves these alone, but running `biome lint --write --unsafe` or
`biome check --write --unsafe` manually **will delete these imports and break the components
at runtime** (the exact class of blank-page bug this tooling exists to prevent). Treat
`noUnusedVariables`/`noUnusedImports` warnings as advisory only until Biome's Vue template
analysis matures — do not "clean them up" with an unsafe fix pass. For that reason `biome.json`
turns both rules off for `.vue` files (they stay on for `.js`).

### After pulling this for the first time

Run `npm install` in this directory. This regenerates `.husky/` and wires up the git
pre-commit hook (via the `prepare` script) — without it, the hook will not be installed and
Biome will only run when invoked manually.
