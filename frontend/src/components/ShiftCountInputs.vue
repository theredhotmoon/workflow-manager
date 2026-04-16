<template>
  <div class="space-y-3">
    <div>
      <label class="block text-sm font-medium text-slate-300 mb-2">Users per shift — Weekdays (Mon–Fri)</label>
      <div class="flex items-center gap-3">
        <input :value="weekday" @input="$emit('update:weekday', parseInt($event.target.value) || 1)"
          type="range" min="1" max="10" step="1"
          class="flex-1 h-2 bg-white/10 rounded-lg appearance-none cursor-pointer accent-violet-500" />
        <span class="w-8 text-center text-lg font-bold text-white tabular-nums">{{ weekday }}</span>
      </div>
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-300 mb-2">Users per shift — Weekends (Sat–Sun)</label>
      <div class="flex items-center gap-3">
        <input :value="weekend" @input="$emit('update:weekend', parseInt($event.target.value) || 1)"
          type="range" min="1" max="10" step="1"
          class="flex-1 h-2 bg-white/10 rounded-lg appearance-none cursor-pointer accent-violet-500" />
        <span class="w-8 text-center text-lg font-bold text-white tabular-nums">{{ weekend }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  weekday: { type: Number, default: 1 },
  weekend: { type: Number, default: 1 },
});

defineEmits(['update:weekday', 'update:weekend']);
</script>
