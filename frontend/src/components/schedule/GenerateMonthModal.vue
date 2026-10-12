<!--
  Generate Month dialog of the Schedule screen: shift templates for every day of the month and the
  24 h overview (moved out of ScheduleView.vue unchanged). The fields edit `genConfig` in place;
  the slider asks the screen to rebuild the default shifts (`shift-count-change`), an end time
  moves the next shift's start (`sync-next-start`).
-->
<template>
  <BaseModal
    overlay-class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm"
    panel-class="bg-slate-800/95 border border-white/10 rounded-2xl shadow-2xl w-full max-w-4xl mx-4 max-h-[85vh] flex flex-col"
    :busy="generating"
    @close="emit('close')"
    v-slot="{ titleId }">
    <!-- Modal Header -->
    <div class="p-6 pb-3 border-b border-white/10 shrink-0">
      <h3 :id="titleId" class="text-lg font-bold text-white flex items-center gap-2">
        <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
        </svg>
        Generate Shifts — {{ monthLabel }}
      </h3>
      <p class="text-slate-400 text-sm mt-1">Configure shift templates for all days in this month</p>
    </div>

    <!-- Modal Body (scrollable) -->
    <div class="p-6 pt-4 overflow-y-auto flex-1">
      <div class="flex gap-6">
        <!-- Left Column: Config -->
        <div class="flex-1 min-w-0">
          <!-- Shifts per day -->
          <div class="mb-5">
            <label class="block text-sm font-medium text-slate-300 mb-2">Shifts per day</label>
            <div class="flex items-center gap-3">
              <input v-model.number="genConfig.shiftsPerDay" type="range" min="1" max="10" step="1"
                @input="emit('shift-count-change')"
                class="flex-1 h-2 bg-white/10 rounded-lg appearance-none cursor-pointer accent-indigo-500" />
              <span class="w-8 text-center text-lg font-bold text-white">{{ genConfig.shiftsPerDay }}</span>
            </div>
          </div>

          <!-- Work mode defaults -->
          <div class="mb-5">
            <label class="block text-sm font-medium text-slate-300 mb-2">Default work mode</label>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-[10px] text-slate-500 uppercase tracking-wide mb-1">Weekdays</label>
                <select v-model="genConfig.workModeWeekday"
                  class="w-full px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 appearance-none">
                  <option value="office" class="bg-slate-800">🏢 Office</option>
                  <option value="remote" class="bg-slate-800">🏠 Remote</option>
                </select>
              </div>
              <div>
                <label class="block text-[10px] text-slate-500 uppercase tracking-wide mb-1">Weekends</label>
                <select v-model="genConfig.workModeWeekend"
                  class="w-full px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 appearance-none">
                  <option value="office" class="bg-slate-800">🏢 Office</option>
                  <option value="remote" class="bg-slate-800">🏠 Remote</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Shift templates -->
          <div class="space-y-3">
            <div v-for="(shift, idx) in genConfig.shifts" :key="idx"
              class="bg-white/5 border border-white/10 rounded-xl p-3">
              <div class="flex items-center gap-2 mb-2">
                <span class="text-xs font-bold text-slate-400 uppercase">Shift {{ idx + 1 }}</span>
                <span v-if="shift.start_time && shift.end_time && shift.end_time < shift.start_time"
                  class="px-1.5 py-0.5 text-[8px] bg-indigo-500/20 text-indigo-300 border border-indigo-500/20 rounded font-medium">
                  🌙 Overnight
                </span>
                <span v-if="shift.color && shiftColorById[shift.color]"
                  class="w-3 h-3 rounded-full border border-white/20" :style="{ backgroundColor: shiftColorById[shift.color]?.hex }"></span>
              </div>

              <!-- Times + Color in one row -->
              <div>
                <div class="flex gap-2 mb-1">
                  <span class="w-24 shrink-0 text-[10px] text-slate-500 uppercase tracking-wide">Start</span>
                  <span class="w-24 shrink-0 text-[10px] text-slate-500 uppercase tracking-wide">End</span>
                  <span class="text-[10px] text-slate-500 uppercase tracking-wide">Color</span>
                </div>
                <div class="flex items-center gap-2">
                  <input v-model="shift.start_time" type="time"
                    class="w-24 shrink-0 px-2 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
                  <input v-model="shift.end_time" type="time"
                    @blur="emit('sync-next-start', idx)"
                    class="w-24 shrink-0 px-2 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
                  <div class="flex flex-wrap gap-1 items-center">
                    <button v-for="colorDef in shiftColors" :key="colorDef.id"
                      @click="shift.color = shift.color == colorDef.id ? null : String(colorDef.id)"
                      :class="['w-6 h-6 rounded border transition-all',
                        shift.color == colorDef.id ? 'border-white scale-110' : 'border-transparent hover:border-white/30']"
                      :style="{ backgroundColor: colorDef.hex }"
                      :title="colorDef.name">
                    </button>
                    <button @click="shift.color = null; shift.color_label = ''"
                      :class="['w-6 h-6 rounded border transition-all text-[9px]',
                        !shift.color ? 'border-white/30 bg-white/10' : 'border-white/10 bg-white/5']"
                      title="No color">✕</button>
                  </div>
                </div>
              </div>

              <div class="mt-2 flex items-center gap-2">
                <label :for="`gen-shift-type-${idx}`" class="w-24 shrink-0 text-[10px] text-slate-500 uppercase tracking-wide">
                  Shift {{ idx + 1 }} type
                </label>
                <input :id="`gen-shift-type-${idx}`" v-model="shift.shift_type" type="text" maxlength="50"
                  list="shift-type-suggestions" placeholder="e.g. night"
                  class="flex-1 px-2 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
              </div>

              <!-- Checklists -->
              <div class="mt-2">
                <div v-if="shift.checklists?.length" class="space-y-1.5 mb-1.5">
                  <div v-for="(cl, ci) in shift.checklists" :key="ci"
                    class="flex items-center gap-1.5 bg-white/[0.03] border border-white/10 rounded-lg px-2 py-1">
                    <select v-model="cl.checklist_template_id"
                      class="flex-1 px-2 py-1 bg-white/5 border border-white/10 rounded text-white text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50 cursor-pointer">
                      <option :value="null" class="bg-slate-800 text-slate-400">Template...</option>
                      <option v-for="t in checklistTemplates" :key="t.id" :value="t.id" class="bg-slate-800">{{ t.name }}</option>
                    </select>
                    <label v-if="cl.checklist_template_id" class="flex items-center gap-1 cursor-pointer shrink-0">
                      <input type="checkbox" v-model="cl.is_required"
                        class="w-3 h-3 rounded border-white/20 bg-white/5 text-emerald-500 focus:ring-emerald-500/30" />
                      <span class="text-[8px] text-slate-400 uppercase">Req</span>
                    </label>
                    <input v-if="cl.checklist_template_id" v-model.number="cl.deadline_minutes" type="number" min="1" placeholder="min"
                      class="w-12 px-1 py-0.5 bg-white/5 border border-white/10 rounded text-white text-[10px] focus:outline-none focus:ring-1 focus:ring-indigo-500/50" />
                    <select v-if="cl.checklist_template_id" v-model="cl.deadline_reference"
                      class="px-1 py-0.5 bg-white/5 border border-white/10 rounded text-white text-[10px] focus:outline-none cursor-pointer">
                      <option value="shift_start" class="bg-slate-800">after start</option>
                      <option value="shift_end" class="bg-slate-800">before end</option>
                    </select>
                    <button @click="shift.checklists.splice(ci, 1)" class="text-slate-600 hover:text-red-400 transition-colors shrink-0" title="Remove">
                      <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                  </div>
                </div>
                <button @click="shift.checklists = [...(shift.checklists || []), { checklist_template_id: null, is_required: false, deadline_minutes: null, deadline_reference: 'shift_start' }]"
                  class="text-[9px] text-indigo-400 hover:text-indigo-300 transition-colors">
                  + Add Checklist
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Right Column: Donut Chart -->
        <div class="w-72 shrink-0 flex flex-col items-center">
          <h4 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">24h Overview</h4>
          <div class="relative">
            <svg viewBox="0 0 200 200" class="w-56 h-56">
              <!-- Background ring -->
              <circle cx="100" cy="100" r="70" fill="none" stroke="rgba(255,255,255,0.05)" stroke-width="28" />
              <!-- Shift segments -->
              <path v-for="(seg, si) in donutSegments" :key="si"
                :d="seg.path" fill="none"
                :stroke="seg.color" stroke-width="28"
                :stroke-opacity="0.7"
                class="transition-all duration-300" />
              <!-- Hour markers -->
              <template v-for="h in 24" :key="'h'+h">
                <line
                  :x1="100 + 88 * Math.sin((h-1) * Math.PI / 12)"
                  :y1="100 - 88 * Math.cos((h-1) * Math.PI / 12)"
                  :x2="100 + (h % 6 === 0 ? 82 : 85) * Math.sin((h-1) * Math.PI / 12)"
                  :y2="100 - (h % 6 === 0 ? 82 : 85) * Math.cos((h-1) * Math.PI / 12)"
                  :stroke="h % 6 === 0 ? 'rgba(255,255,255,0.3)' : 'rgba(255,255,255,0.1)'"
                  :stroke-width="h % 6 === 0 ? 1.5 : 0.5" />
              </template>
              <!-- Hour labels (0, 6, 12, 18) -->
              <text x="100" y="18" text-anchor="middle" class="fill-slate-400 text-[9px] font-mono">0:00</text>
              <text x="186" y="103" text-anchor="middle" class="fill-slate-400 text-[9px] font-mono">6:00</text>
              <text x="100" y="190" text-anchor="middle" class="fill-slate-400 text-[9px] font-mono">12:00</text>
              <text x="14" y="103" text-anchor="middle" class="fill-slate-400 text-[9px] font-mono">18:00</text>
              <!-- Center text -->
              <text x="100" y="95" text-anchor="middle" class="fill-white text-[11px] font-bold">{{ genConfig.shiftsPerDay }}</text>
              <text x="100" y="109" text-anchor="middle" class="fill-slate-400 text-[8px]">{{ genConfig.shiftsPerDay === 1 ? 'shift' : 'shifts' }}</text>
            </svg>
          </div>
          <!-- Legend -->
          <div class="mt-4 w-full space-y-1.5">
            <div v-for="(seg, si) in donutSegments" :key="'leg'+si"
              class="flex items-center gap-2 px-3 py-1.5 bg-white/[0.03] border border-white/5 rounded-lg">
              <div class="w-3 h-3 rounded-sm shrink-0" :style="{ backgroundColor: seg.color, opacity: 0.8 }"></div>
              <span class="text-[11px] text-white font-medium">S{{ si + 1 }}</span>
              <span class="text-[10px] text-slate-400 font-mono">{{ seg.startTime }}–{{ seg.endTime }}</span>
              <span class="text-[10px] text-slate-500 ml-auto tabular-nums">{{ seg.durationLabel }}</span>
            </div>
            <div v-if="donutGapMinutes > 0" class="flex items-center gap-2 px-3 py-1.5">
              <div class="w-3 h-3 rounded-sm shrink-0 bg-white/5"></div>
              <span class="text-[10px] text-slate-500 italic">Uncovered: {{ formatDuration(donutGapMinutes) }}</span>
            </div>
            <div v-if="donutOverlapMinutes > 0" class="flex items-center gap-2 px-3 py-1.5 bg-red-500/5 border border-red-500/10 rounded-lg">
              <span class="text-[10px] text-red-400">⚠ Overlap: {{ formatDuration(donutOverlapMinutes) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Footer -->
    <div class="p-6 pt-3 border-t border-white/10 shrink-0 flex items-center justify-end gap-3">
      <button @click="emit('close')"
        class="px-5 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
        Cancel
      </button>
      <button @click="emit('generate')" :disabled="generating"
        class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
        <svg v-if="generating" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        Generate {{ monthLabel }}
      </button>
    </div>
  </BaseModal>
</template>

<script setup>
import { formatDuration } from '../../shiftHours.js';
import BaseModal from '../BaseModal.vue';

defineProps({
  genConfig: { type: Object, required: true },
  monthLabel: { type: String, required: true },
  generating: { type: Boolean, default: false },
  shiftColors: { type: Array, required: true },
  shiftColorById: { type: Object, required: true },
  checklistTemplates: { type: Array, required: true },
  donutSegments: { type: Array, required: true },
  donutGapMinutes: { type: Number, required: true },
  donutOverlapMinutes: { type: Number, required: true },
});

const emit = defineEmits(['close', 'generate', 'shift-count-change', 'sync-next-start']);
</script>
