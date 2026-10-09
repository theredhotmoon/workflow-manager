<template>
  <Transition enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
    enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center px-4">
      <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="$emit('close')"></div>
      <div ref="diffModalRef"
        role="dialog"
        aria-modal="true"
        aria-labelledby="diff-modal-title"
        class="relative w-full max-w-4xl max-h-[85vh] bg-slate-900 border border-white/10 rounded-2xl shadow-2xl flex flex-col overflow-hidden">
        <!-- Header -->
        <div class="flex items-center justify-between p-5 border-b border-white/10 shrink-0">
          <div>
            <h3 id="diff-modal-title" class="text-white font-bold text-lg">Changes: v{{ oldVersion }} → v{{ newVersion }}</h3>
            <p v-if="changeReason" class="text-slate-400 text-sm mt-0.5">{{ changeReason }}</p>
          </div>
          <div class="flex items-center gap-3">
            <div class="flex text-xs">
              <span class="px-2 py-1 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-l-lg">+{{ stats.added }}</span>
              <span class="px-2 py-1 bg-red-500/10 text-red-400 border border-red-500/20 rounded-r-lg">-{{ stats.removed }}</span>
            </div>
            <button @click="$emit('close')"
              aria-label="Close diff modal"
              class="text-slate-400 hover:text-white transition-colors">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
        </div>

        <!-- Diff content -->
        <div class="overflow-auto flex-1 font-mono text-[13px] leading-relaxed p-5 space-y-4">
          <!-- Title diff -->
          <div v-if="titleDiff.length" class="bg-white/5 border border-white/10 rounded-xl overflow-hidden">
            <div class="px-4 py-2 bg-white/3 text-slate-400 text-xs font-semibold uppercase tracking-wider">Title</div>
            <div class="px-4 py-2">
              <template v-for="(part, i) in titleDiff" :key="'t'+i">
                <span :class="wordClass(part)">{{ part.value }}</span>
              </template>
            </div>
          </div>

          <!-- Purpose diff -->
          <div v-if="purposeDiff.length" class="bg-white/5 border border-white/10 rounded-xl overflow-hidden">
            <div class="px-4 py-2 bg-white/3 text-slate-400 text-xs font-semibold uppercase tracking-wider">Purpose</div>
            <div class="px-4 py-2">
              <template v-for="(part, i) in purposeDiff" :key="'p'+i">
                <span :class="wordClass(part)">{{ part.value }}</span>
              </template>
            </div>
          </div>

          <!-- Requirements diff -->
          <div v-if="requirementsDiff.length" class="bg-white/5 border border-white/10 rounded-xl overflow-hidden">
            <div class="px-4 py-2 bg-white/3 text-slate-400 text-xs font-semibold uppercase tracking-wider">Requirements</div>
            <div class="px-4 py-2">
              <template v-for="(part, i) in requirementsDiff" :key="'r'+i">
                <span :class="wordClass(part)">{{ part.value }}</span>
              </template>
            </div>
          </div>

          <!-- Content diff — line by line with inline word highlighting -->
          <div v-if="contentDiffResult.length" class="bg-white/5 border border-white/10 rounded-xl overflow-hidden">
            <div class="px-4 py-2 bg-white/3 text-slate-400 text-xs font-semibold uppercase tracking-wider">Content</div>
            <div class="py-1">
              <template v-for="(group, gi) in contentDiffResult" :key="'g'+gi">
                <!-- Unchanged line -->
                <div v-if="group.type === 'equal'" class="px-4 py-0.5 text-slate-500 whitespace-pre-wrap">
                  <span class="select-none w-5 inline-block text-right mr-3 opacity-30"> </span>{{ group.value }}
                </div>
                <!-- Changed block: show removed then added with inline word diff -->
                <template v-if="group.type === 'changed'">
                  <div v-for="(line, li) in group.removed" :key="'r'+gi+li"
                    class="px-4 py-0.5 bg-red-500/8 whitespace-pre-wrap">
                    <span class="select-none w-5 inline-block text-right mr-3 text-red-400/40">-</span>
                    <template v-for="(w, wi) in line.words" :key="wi">
                      <span :class="w.highlight ? 'bg-red-500/25 text-red-300 rounded px-0.5' : 'text-red-300/70'">{{ w.value }}</span>
                    </template>
                  </div>
                  <div v-for="(line, li) in group.added" :key="'a'+gi+li"
                    class="px-4 py-0.5 bg-emerald-500/8 whitespace-pre-wrap">
                    <span class="select-none w-5 inline-block text-right mr-3 text-emerald-400/40">+</span>
                    <template v-for="(w, wi) in line.words" :key="wi">
                      <span :class="w.highlight ? 'bg-emerald-500/25 text-emerald-300 rounded px-0.5' : 'text-emerald-300/70'">{{ w.value }}</span>
                    </template>
                  </div>
                </template>
                <!-- Pure addition -->
                <div v-if="group.type === 'added'" class="px-4 py-0.5 bg-emerald-500/8 whitespace-pre-wrap">
                  <span class="select-none w-5 inline-block text-right mr-3 text-emerald-400/40">+</span>
                  <span class="text-emerald-300">{{ group.value }}</span>
                </div>
                <!-- Pure removal -->
                <div v-if="group.type === 'removed'" class="px-4 py-0.5 bg-red-500/8 whitespace-pre-wrap">
                  <span class="select-none w-5 inline-block text-right mr-3 text-red-400/40">-</span>
                  <span class="text-red-300 line-through opacity-70">{{ group.value }}</span>
                </div>
              </template>
            </div>
          </div>

          <!-- No changes -->
          <div v-if="!hasChanges" class="text-center py-12 text-slate-500">
            <p class="text-lg">No text changes detected between these versions.</p>
          </div>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { diffLines, diffWords } from 'diff';
import { computed, ref, toRef } from 'vue';
import { useFocusTrap } from '../../composables/useFocusTrap.js';

const props = defineProps({
  show: { type: Boolean, default: false },
  oldVersion: { type: Number, required: true },
  newVersion: { type: Number, required: true },
  oldData: { type: Object, default: () => ({}) },
  newData: { type: Object, default: () => ({}) },
  changeReason: { type: String, default: '' },
});

const emit = defineEmits(['close']);

// Focus trap — uses toRef so the composable can watch the prop reactively
const diffModalRef = ref(null);
useFocusTrap(diffModalRef, toRef(props, 'show'), { onEscape: () => emit('close') });

/**
 * Convert HTML to readable text with proper line breaks at block boundaries.
 */
function stripHtml(html) {
  if (!html) return '';
  // Insert newlines before block-level elements so each paragraph becomes a line
  let text = html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|h[1-6]|li|tr|blockquote|pre)>/gi, '\n')
    .replace(/<(p|div|h[1-6]|li|tr|blockquote|pre|ul|ol)[^>]*>/gi, '')
    .replace(/<[^>]+>/g, '') // strip remaining tags
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#39;/g, "'")
    .replace(/\n{3,}/g, '\n\n') // collapse excessive newlines
    .trim();
  return text;
}

function computeWordDiff(oldText, newText) {
  const old = (oldText || '').trim();
  const nw = (newText || '').trim();
  if (old === nw) return [];
  return diffWords(old, nw);
}

/**
 * Compute line-level diff with inline word highlighting for changed blocks.
 * Returns an array of groups: { type: 'equal'|'added'|'removed'|'changed', ... }
 */
function computeContentDiff(oldHtml, newHtml) {
  const oldText = stripHtml(oldHtml);
  const newText = stripHtml(newHtml);
  if (oldText === newText) return [];

  const lineDiff = diffLines(oldText, newText);
  const result = [];

  // Process line diff and pair up removed/added blocks for inline word diff
  let i = 0;
  while (i < lineDiff.length) {
    const part = lineDiff[i];
    if (!part.added && !part.removed) {
      // Unchanged lines
      const lines = part.value.split('\n').filter((l) => l.trim());
      for (const line of lines) {
        result.push({ type: 'equal', value: line });
      }
      i++;
    } else if (part.removed && i + 1 < lineDiff.length && lineDiff[i + 1].added) {
      // Paired change: removed followed by added — do inline word diff
      const removedText = part.value;
      const addedText = lineDiff[i + 1].value;
      const wordDiff = diffWords(removedText.trim(), addedText.trim());

      const removedWords = [];
      const addedWords = [];
      for (const w of wordDiff) {
        if (w.removed) {
          removedWords.push({ value: w.value, highlight: true });
        } else if (w.added) {
          addedWords.push({ value: w.value, highlight: true });
        } else {
          removedWords.push({ value: w.value, highlight: false });
          addedWords.push({ value: w.value, highlight: false });
        }
      }
      result.push({
        type: 'changed',
        removed: [{ words: removedWords }],
        added: [{ words: addedWords }],
      });
      i += 2;
    } else if (part.removed) {
      const lines = part.value.split('\n').filter((l) => l.trim());
      for (const line of lines) {
        result.push({ type: 'removed', value: line });
      }
      i++;
    } else if (part.added) {
      const lines = part.value.split('\n').filter((l) => l.trim());
      for (const line of lines) {
        result.push({ type: 'added', value: line });
      }
      i++;
    } else {
      i++;
    }
  }
  return result;
}

const titleDiff = computed(() => computeWordDiff(props.oldData.title, props.newData.title));
const purposeDiff = computed(() => computeWordDiff(props.oldData.purpose, props.newData.purpose));
const requirementsDiff = computed(() =>
  computeWordDiff(props.oldData.requirements, props.newData.requirements),
);
const contentDiffResult = computed(() =>
  computeContentDiff(props.oldData.content, props.newData.content),
);

const hasChanges = computed(
  () =>
    titleDiff.value.length > 0 ||
    purposeDiff.value.length > 0 ||
    requirementsDiff.value.length > 0 ||
    contentDiffResult.value.length > 0,
);

const stats = computed(() => {
  let added = 0,
    removed = 0;
  for (const p of [...titleDiff.value, ...purposeDiff.value, ...requirementsDiff.value]) {
    if (p.added) added += (p.value.match(/\S+/g) || []).length;
    if (p.removed) removed += (p.value.match(/\S+/g) || []).length;
  }
  for (const g of contentDiffResult.value) {
    if (g.type === 'added') added += (g.value.match(/\S+/g) || []).length;
    if (g.type === 'removed') removed += (g.value.match(/\S+/g) || []).length;
    if (g.type === 'changed') {
      for (const w of g.removed.flatMap((r) => r.words)) {
        if (w.highlight) removed += (w.value.match(/\S+/g) || []).length;
      }
      for (const w of g.added.flatMap((a) => a.words)) {
        if (w.highlight) added += (w.value.match(/\S+/g) || []).length;
      }
    }
  }
  return { added, removed };
});

function wordClass(part) {
  if (part.added) return 'bg-emerald-500/25 text-emerald-300 rounded px-0.5';
  if (part.removed) return 'bg-red-500/25 text-red-300 line-through rounded px-0.5';
  return 'text-slate-300';
}
</script>
