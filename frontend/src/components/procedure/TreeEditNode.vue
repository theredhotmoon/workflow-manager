<template>
  <div>
    <!-- This node -->
    <div :class="['flex items-center gap-1.5 group rounded-xl transition-all duration-150',
      isDragOver ? 'bg-indigo-500/20 border border-indigo-500/40 ring-1 ring-indigo-500/30' : 'hover:bg-white/5 border border-transparent',
      ]"
      :style="{ paddingLeft: (depth * 16 + 4) + 'px' }"
      @dragover.prevent="onDragOver"
      @dragleave="isDragOver = false"
      @drop.prevent="onDrop">

      <!-- Expand / collapse -->
      <button v-if="node.children?.length"
        @click="expanded = !expanded"
        :aria-label="`${expanded ? 'Collapse' : 'Expand'} ${node.title}`"
        :aria-expanded="expanded"
        class="w-5 h-5 flex items-center justify-center text-slate-500 hover:text-white transition-colors shrink-0">
        <svg :class="['w-3 h-3 transition-transform duration-150', expanded ? 'rotate-90' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
      </button>
      <span v-else class="w-5 shrink-0"></span>

      <!-- Drag handle for this node -->
      <span draggable="true" @dragstart="onDragSelf($event)"
        class="text-slate-600 hover:text-slate-400 cursor-grab active:cursor-grabbing text-xs select-none">⠿</span>

      <!-- Icon + label -->
      <span class="text-xs">{{ node.children?.length ? '📁' : '📄' }}</span>
      <span class="text-xs text-slate-300 truncate py-1.5">{{ node.title }}</span>
      <span class="text-[9px] text-slate-600 shrink-0 font-mono ml-auto mr-2">{{ node.identifier }}</span>
    </div>

    <!-- Children -->
    <div v-if="expanded && node.children?.length">
      <TreeEditNode v-for="child in node.children" :key="child.id"
        :node="child" :depth="depth + 1"
        @drop-procedure="$emit('drop-procedure', $event)" />
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
  node: { type: Object, required: true },
  depth: { type: Number, default: 0 },
});

const emit = defineEmits(['drop-procedure']);

const expanded = ref(props.depth < 3);
const isDragOver = ref(false);

function onDragOver(e) {
  if (e.dataTransfer.types.includes('application/procedure-id')) {
    isDragOver.value = true;
  }
}

function onDrop(e) {
  isDragOver.value = false;
  const procId = e.dataTransfer.getData('application/procedure-id');
  if (procId && parseInt(procId) !== props.node.id) {
    emit('drop-procedure', { procedureId: parseInt(procId), parentId: props.node.id });
  }
}

function onDragSelf(e) {
  e.dataTransfer.setData('application/procedure-id', props.node.id.toString());
  e.dataTransfer.effectAllowed = 'move';
}
</script>

<script>
export default {
  name: 'TreeEditNode',
};
</script>
