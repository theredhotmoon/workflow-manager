<!--
  The Schedule screen's left sidebar for schedule managers (moved out of ScheduleView.vue
  unchanged): the employee roster (sort, click to show availability, drag onto a shift to assign),
  Auto-Fill and the overlay switches. The screen (useEmployeeSelection, useScheduleMonth) owns the
  state: the sort and the overlay switches are v-models, the accordion is the screen's object (so
  it survives a reload of the month), and clicks and drags are emitted.
-->
<template>
  <div class="w-64 shrink-0">

    <!-- All Groups info banner -->
    <div v-if="isAllGroupsMode" class="mb-2 px-3 py-2.5 bg-gradient-to-r from-indigo-500/10 via-purple-500/10 to-pink-500/10 border border-purple-500/20 rounded-xl">
      <div class="flex items-center gap-2 mb-1">
        <span class="w-2 h-2 rounded-full" style="background: linear-gradient(135deg, #6366f1, #a855f7, #ec4899)"></span>
        <span class="text-xs font-semibold text-purple-300">All Groups View</span>
      </div>
      <p class="text-[10px] text-slate-400 leading-snug">Viewing shifts from all groups. Switch to a specific group to manage shifts.</p>
    </div>
    <div class="sticky top-4 z-10 space-y-2">

      <!-- Accordion: Employees -->
      <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
        <button @click="sideAccordion.employees = !sideAccordion.employees"
          class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-white/[0.03] transition-colors">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span class="text-sm font-semibold text-slate-300">Employees</span>
            <span class="text-[10px] text-slate-500 ml-1">({{ sortedEmployeePool.length }})</span>
          </div>
          <svg :class="['w-4 h-4 text-slate-500 transition-transform duration-200', sideAccordion.employees ? 'rotate-180' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <div v-show="sideAccordion.employees" class="px-4 pb-4 border-t border-white/5">
          <p class="text-[10px] text-slate-500 mb-2 mt-3 leading-snug">Click to preview availability · Drag to assign</p>

      <!-- Sort Controls -->
      <div class="flex items-center gap-1 mb-3">
        <span class="text-[10px] text-slate-500 uppercase tracking-wide mr-1">Sort:</span>
        <button @click="empSort = 'name'"
          :class="[
            'px-2 py-1 text-[10px] font-medium rounded-md border transition-all',
            empSort === 'name'
              ? 'bg-indigo-500/20 border-indigo-500/30 text-indigo-300'
              : 'bg-white/5 border-white/10 text-slate-500 hover:text-slate-300'
          ]">
          Name
        </button>
        <button @click="empSort = empSort === 'shifts-asc' ? 'shifts-desc' : 'shifts-asc'"
          :class="[
            'px-2 py-1 text-[10px] font-medium rounded-md border transition-all flex items-center gap-0.5',
            empSort.startsWith('shifts')
              ? 'bg-indigo-500/20 border-indigo-500/30 text-indigo-300'
              : 'bg-white/5 border-white/10 text-slate-500 hover:text-slate-300'
          ]">
          Shifts
          <span v-if="empSort === 'shifts-asc'" class="text-[8px]">▲</span>
          <span v-else-if="empSort === 'shifts-desc'" class="text-[8px]">▼</span>
        </button>
        <button @click="empSort = empSort === 'hours-asc' ? 'hours-desc' : 'hours-asc'"
          :class="[
            'px-2 py-1 text-[10px] font-medium rounded-md border transition-all flex items-center gap-0.5',
            empSort.startsWith('hours')
              ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-300'
              : 'bg-white/5 border-white/10 text-slate-500 hover:text-slate-300'
          ]">
          Hours
          <span v-if="empSort === 'hours-asc'" class="text-[8px]">▲</span>
          <span v-else-if="empSort === 'hours-desc'" class="text-[8px]">▼</span>
        </button>
      </div>

      <!-- Selected employee indicator -->
      <div v-if="selectedEmployee" class="mb-3 px-3 py-2 bg-indigo-500/10 border border-indigo-500/20 rounded-xl">
        <div class="flex items-center justify-between mb-1">
          <span class="text-xs text-indigo-300 font-medium">Availability for</span>
          <button @click="emit('deselect')" class="text-slate-400 hover:text-white text-sm leading-none">×</button>
        </div>
        <span class="text-sm text-white font-semibold">{{ selectedEmployee.name }}</span>
        <div class="flex items-center gap-3 mt-1.5">
          <span class="flex items-center gap-1 text-[10px]"><span class="w-2 h-2 rounded bg-red-500/60"></span><span class="text-red-300">Unavail</span></span>
          <span class="flex items-center gap-1 text-[10px]"><span class="w-2 h-2 rounded bg-emerald-500/60"></span><span class="text-emerald-300">Preferred</span></span>
        <span class="flex items-center gap-1 text-[10px]"><span class="w-2 h-2 rounded bg-sky-500/60"></span><span class="text-sky-300">Assigned</span></span>
        </div>
      </div>

      <!-- Employee List -->
      <VueDraggable
        :model-value="sortedEmployeePool"
        :group="{ name: 'shifts', pull: 'clone', put: false }"
        :sort="false"
        :clone="cloneEmployee"
        @start="emit('drag-start', $event)"
        @end="emit('drag-end', $event)"
        class="space-y-1.5"
      >
        <div v-for="emp in sortedEmployeePool" :key="emp.id"
          @click="emit('employee-click', emp)"
          :class="[
            'flex items-center gap-2.5 px-3 py-2 border rounded-xl cursor-grab active:cursor-grabbing transition-all duration-200 select-none',
            selectedEmployee?.id === emp.id
              ? 'bg-indigo-500/20 border-indigo-500/40 ring-2 ring-indigo-500/30'
              : 'bg-white/5 hover:bg-white/10 border-white/10'
          ]">
          <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white font-bold text-[10px] shrink-0">
            {{ getInitials(emp.name) }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-1">
              <span class="text-sm text-slate-300 font-medium truncate">{{ emp.name }}</span>
              <span v-if="isCurrentNewbie(emp.id)" class="text-yellow-400 text-[10px]" title="Newbie">⭐</span>
              <span v-if="isAdmin(emp)" class="px-1 py-0.5 text-[7px] font-semibold uppercase tracking-wider bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full shrink-0">A</span>
            </div>
          </div>
          <div class="flex items-center gap-1 shrink-0">
            <span :class="[
              'px-2 py-0.5 text-[11px] font-bold rounded-full tabular-nums',
              getEmployeeShiftCount(emp.id) > 0
                ? 'bg-indigo-500/20 text-indigo-300 border border-indigo-500/25'
                : 'bg-white/5 text-slate-500 border border-white/10'
            ]">
              {{ getEmployeeShiftCount(emp.id) }}
            </span>
            <span
              class="px-1.5 py-0.5 text-[10px] font-medium rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 tabular-nums">
              {{ getEmployeeTotalHours(emp.id) }}h
            </span>
          </div>
        </div>
          </VueDraggable>
        </div>
      </div>

      <!-- Accordion: Auto-Fill -->
      <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl">
        <button @click="sideAccordion.autoFill = !sideAccordion.autoFill"
          class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-white/[0.03] transition-colors">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            <span class="text-sm font-semibold text-slate-300">Auto-Fill</span>
          </div>
          <svg :class="['w-4 h-4 text-slate-500 transition-transform duration-200', sideAccordion.autoFill ? 'rotate-180' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <div v-show="sideAccordion.autoFill" class="border-t border-white/5">
          <AutoFillPanel v-if="!isAllGroupsMode" :monthKey="monthKey" :shiftGroupId="activeGroupId" :disabled="!hasScheduleShifts" @filled="emit('filled')" @toast="(msg, type) => emit('toast', msg, type)" />
          <div v-else class="p-3 text-[10px] text-slate-500 italic">Select a specific group to use auto-fill.</div>
        </div>
      </div>

      <!-- Accordion: Overlays -->
      <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
        <button @click="sideAccordion.overlays = !sideAccordion.overlays"
          class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-white/[0.03] transition-colors">
          <div class="flex items-center gap-2">
            <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
            </svg>
            <span class="text-sm font-semibold text-slate-300">Overlays</span>
          </div>
          <svg :class="['w-4 h-4 text-slate-500 transition-transform duration-200', sideAccordion.overlays ? 'rotate-180' : '']" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <div v-show="sideAccordion.overlays" class="px-4 pb-4 border-t border-white/5 pt-3 space-y-2">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" v-model="showDelegations" class="accent-purple-500 w-3.5 h-3.5 rounded" />
            <span class="text-xs text-slate-400">Show Delegations</span>
            <span class="w-2 h-2 rounded bg-purple-500/60"></span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" v-model="showNewbies" class="accent-yellow-500 w-3.5 h-3.5 rounded" />
            <span class="text-xs text-slate-400">Show Newbies</span>
            <span class="w-2 h-2 rounded bg-yellow-500/60"></span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" v-model="showClosures" class="accent-gray-400 w-3.5 h-3.5 rounded" />
            <span class="text-xs text-slate-400">Show Office Unavailability</span>
            <span class="w-2 h-2 rounded bg-red-500/60"></span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" v-model="showShiftColors" class="accent-cyan-500 w-3.5 h-3.5 rounded" />
            <span class="text-xs text-slate-400">Show Shift Colors</span>
            <span class="w-2 h-2 rounded" style="background: linear-gradient(135deg, #3b82f6, #f59e0b, #10b981)"></span>
          </label>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { VueDraggable } from 'vue-draggable-plus';
import { isAdmin } from '../../roles.js';
import { cloneEmployee, getInitials } from '../../schedule/helpers.js';
import AutoFillPanel from '../AutoFillPanel.vue';

defineProps({
  isAllGroupsMode: { type: Boolean, default: false },
  /** Which accordion sections are open: { employees, autoFill, overlays }, toggled in place. */
  sideAccordion: { type: Object, required: true },
  sortedEmployeePool: { type: Array, required: true },
  selectedEmployee: { type: Object, default: null },
  getEmployeeShiftCount: { type: Function, required: true },
  getEmployeeTotalHours: { type: Function, required: true },
  isCurrentNewbie: { type: Function, required: true },
  monthKey: { type: String, required: true },
  activeGroupId: { type: [Number, String], default: null },
  hasScheduleShifts: { type: Boolean, default: false },
});

const empSort = defineModel('empSort', { type: String, default: 'name' });
const showDelegations = defineModel('showDelegations', { type: Boolean, default: false });
const showNewbies = defineModel('showNewbies', { type: Boolean, default: false });
const showClosures = defineModel('showClosures', { type: Boolean, default: false });
const showShiftColors = defineModel('showShiftColors', { type: Boolean, required: true });

const emit = defineEmits([
  'deselect',
  'drag-start',
  'drag-end',
  'employee-click',
  'filled',
  'toast',
]);
</script>
