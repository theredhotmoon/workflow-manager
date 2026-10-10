// frontend/e2e/fixtures/drag.js

// Drags a roster card or a shift chip onto a shift drop-zone with real mouse events (Sortable.js
// ignores synthetic drag events).
export async function dragToShiftDropzone(page, sourceLocator, targetLocator) {
  // Shift-group tabs above the grid wrap as groups accumulate during a run, pushing late days below
  // the fold. The employee roster is sticky, so scrolling the target in keeps both ends on screen.
  // The source first (a no-op for the sticky roster, but a card inside a scrolled list is brought in),
  // then the target.
  await sourceLocator.scrollIntoViewIfNeeded();
  await targetLocator.scrollIntoViewIfNeeded();
  const source = await sourceLocator.boundingBox();
  const target = await targetLocator.boundingBox();
  if (!source || !target) throw new Error('drag source/target not visible');
  // A mouse drag can't scroll the page, so both ends must already be on screen — otherwise the drop
  // silently never happens. Fail loudly instead.
  const viewport = page.viewportSize();
  for (const [name, box] of [
    ['source', source],
    ['target', target],
  ]) {
    if (box.y < 0 || box.y + box.height > viewport.height) {
      throw new Error(
        `drag ${name} is outside the ${viewport.width}x${viewport.height} viewport (y=${Math.round(box.y)}); bring it up (e.g. sort the roster so the card is near the top) or use a taller viewport`,
      );
    }
  }

  await page.mouse.move(source.x + source.width / 2, source.y + source.height / 2);
  await page.mouse.down();
  // Sortable.js needs several intermediate mousemove events to register a real drag.
  const steps = 8;
  for (let i = 1; i <= steps; i++) {
    const x = source.x + ((target.x - source.x) * i) / steps;
    const y = source.y + ((target.y - source.y) * i) / steps;
    await page.mouse.move(x, y, { steps: 3 });
  }
  await page.mouse.move(target.x + target.width / 2, target.y + target.height / 2, { steps: 3 });
  // Hovering other cells moves the dragged chip and reflows the grid, so the drop-zone can have
  // shifted under the cursor: re-aim at where it is now before releasing.
  for (let i = 0; i < 2; i++) {
    const now = await targetLocator.boundingBox();
    if (!now) break;
    await page.mouse.move(now.x + now.width / 2 - 4 + i * 8, now.y + now.height / 2, { steps: 4 });
  }
  await page.mouse.up();
}
