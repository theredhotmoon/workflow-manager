<template>
  <div>
    <div class="flex items-center justify-between mb-4" v-if="editable">
      <p class="text-slate-400 text-sm">Ready-made response fragments</p>
      <button @click="emit('add-template')"
        class="px-3 py-1.5 text-xs text-violet-400 bg-violet-500/15 border border-violet-500/20 rounded-lg hover:bg-violet-500/25 transition-all">
        + Add Template
      </button>
    </div>
    <div v-if="!templates.length" class="text-center text-slate-500 text-sm py-8">No templates yet.</div>
    <div v-else class="space-y-3">
      <div v-for="tmpl in templates" :key="tmpl.id"
        class="bg-white/5 border border-white/10 rounded-xl p-4 hover:border-violet-500/20 transition-all">
        <div class="flex items-center justify-between mb-2">
          <div class="flex items-center gap-2">
            <h4 class="text-white text-sm font-medium">{{ tmpl.title }}</h4>
            <span class="px-1.5 py-0.5 text-[9px] font-semibold uppercase bg-white/10 text-slate-400 rounded">{{ tmpl.language }}</span>
          </div>
          <div class="flex items-center gap-1">
            <button @click="copyTemplate(tmpl)" class="p-1.5 text-slate-400 hover:text-emerald-400 transition-colors" title="Copy to clipboard">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
            </button>
            <template v-if="editable">
              <button @click="emit('edit-template', tmpl)"
                :aria-label="`Edit template: ${tmpl.title}`"
                class="p-1.5 text-slate-400 hover:text-indigo-400 transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
              </button>
              <button @click="emit('delete-template', tmpl)"
                :aria-label="`Delete template: ${tmpl.title}`"
                class="p-1.5 text-slate-400 hover:text-red-400 transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
              </button>
            </template>
          </div>
        </div>
        <p class="text-slate-300 text-sm whitespace-pre-wrap">{{ tmpl.body }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  templates: { type: Array, required: true },
  editable: { type: Boolean, default: false },
});

const emit = defineEmits(['add-template', 'edit-template', 'delete-template', 'copied']);

function copyTemplate(tmpl) {
  navigator.clipboard.writeText(tmpl.body);
  emit('copied', 'Template copied!');
}
</script>
