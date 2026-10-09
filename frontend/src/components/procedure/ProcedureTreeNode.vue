<template>
  <div>
    <!-- This node -->
    <div :class="['flex items-center gap-1 group rounded-lg transition-all duration-150 cursor-pointer',
      isDragOver ? 'bg-indigo-500/20 border border-indigo-500/40 ring-1 ring-indigo-500/30' : 'hover:bg-white/5',
      isSelected ? 'bg-indigo-500/10' : '']"
      :style="{ paddingLeft: (depth * 14) + 'px' }"
      @click="$emit('select', node.id)"
      @dragover.prevent="onDragOver"
      @dragleave="isDragOver = false"
      @drop.prevent="onDrop">

      <!-- Expand / collapse toggle -->
      <button v-if="node.children?.length"
        @click.stop="expanded = !expanded"
        :aria-label="`${expanded ? 'Collapse' : 'Expand'} ${node.title}`"
        :aria-expanded="expanded"
        class="w-5 h-5 flex items-center justify-center text-slate-500 hover:text-white transition-colors shrink-0">
        <svg :class="['w-3 h-3 transition-transform duration-150', expanded ? 'rotate-90' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
      </button>
      <span v-else class="w-5 shrink-0"></span>

      <!-- Folder / leaf icon -->
      <span class="text-[11px] shrink-0">{{ node.children?.length ? '📁' : '📄' }}</span>

      <!-- Label -->
      <span :class="['text-[11px] truncate py-1', isSelected ? 'text-indigo-300 font-semibold' : 'text-slate-300']">
        {{ node.title }}
      </span>
      <span v-if="node.identifier" class="text-[9px] text-slate-600 shrink-0 ml-auto mr-1">{{ node.identifier }}</span>
    </div>

    <!-- Children -->
    <div v-if="expanded && node.children?.length">
      <ProcedureTreeNode v-for="child in node.children" :key="child.id"
        :node="child" :depth="depth + 1" :selected-id="selectedId"
        @select="$emit('select', $event)"
        @drop-procedure="$emit('drop-procedure', $event)" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  node: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  selectedId: { type: Number, default: null },
});

const emit = defineEmits(['select', 'drop-procedure']);

const expanded = ref(props.depth === 0 || (props.node.children?.length > 0 && props.depth < 2));
const isDragOver = ref(false);

const isSelected = computed(() => props.selectedId === props.node.id);

function onDragOver(e) {
  if (e.dataTransfer.types.includes('application/procedure-id')) {
    isDragOver.value = true;
  }
}

function onDrop(e) {
  isDragOver.value = false;
  const procId = e.dataTransfer.getData('application/procedure-id');
  if (procId) {
    emit('drop-procedure', { procedureId: parseInt(procId), parentId: props.node.id });
  }
}
</script>

<script>
export default {
  name: 'ProcedureTreeNode',
};
</script>
