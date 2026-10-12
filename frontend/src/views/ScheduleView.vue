<template>
  <AppLayout>
    <!-- Main Content -->
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">Shift Schedule</h1>
          <p class="text-slate-400 mt-1">Plan and manage monthly work shifts</p>
        </div>
        <div class="flex items-center gap-3">
          <!-- Month Navigator -->
          <div class="flex items-center gap-2 bg-white/5 border border-white/10 rounded-xl px-2 py-1">
            <button @click="prevMonth" aria-label="Previous month"
              class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
              </svg>
            </button>
            <span class="text-white font-semibold text-sm min-w-[140px] text-center">{{ monthLabel }}</span>
            <button @click="nextMonth" aria-label="Next month"
              class="p-2 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
              </svg>
            </button>
          </div>
          <!-- Generate Button (admin only, not in all-groups mode) -->
          <button v-if="canManageSchedule && !isAllGroupsMode" @click="openGenerateModal" :disabled="generating"
            class="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-500/25 transition-all duration-200 flex items-center gap-2 text-sm disabled:opacity-50">
            <svg v-if="generating" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Generate Month
          </button>

          <!-- Set shift types (requires canManageSchedule, not in all-groups mode) -->
          <button v-if="canManageSchedule && !isAllGroupsMode" @click="showShiftTypeEditor = true" :disabled="loading"
            class="px-4 py-2.5 bg-white/5 hover:bg-white/10 text-slate-200 border border-white/10 font-semibold rounded-xl transition-all duration-200 text-sm disabled:opacity-50 disabled:cursor-not-allowed">
            Set shift types
          </button>

          <!-- Delete Schedule Button (admin only, not in all-groups mode) -->
          <button v-if="canManageSchedule && !isAllGroupsMode" @click="deleteStep = 1"
            class="px-4 py-2.5 bg-red-500/10 hover:bg-red-500/20 text-red-400 hover:text-red-300 border border-red-500/20 hover:border-red-500/40 font-semibold rounded-xl transition-all duration-200 flex items-center gap-2 text-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            Delete Schedule
          </button>
        </div>
      </div>

      <!-- Shift Group Tabs (admin) -->
      <ScheduleGroupTabs
        v-if="shiftGroups.length > 0"
        v-model:editingGroupId="editingGroupId"
        v-model:editingGroupName="editingGroupName"
        v-model:showNewGroupInput="showNewGroupInput"
        v-model:showArchivedGroups="showArchivedGroups"
        v-model:newGroupName="newGroupName"
        v-model:newGroupColor="newGroupColor"
        :shift-groups="shiftGroups"
        :archived-groups="archivedGroups"
        :active-group-id="activeGroupId"
        :is-all-groups-mode="isAllGroupsMode"
        :restoring-group-id="restoringGroupId"
        :creating-group="creatingGroup"
        @switch-group="switchGroup"
        @start-rename="startGroupRename"
        @save-rename="saveGroupRename"
        @archive="archiveShiftGroup"
        @open-settings="openGroupSettings"
        @restore="restoreShiftGroup"
        @create="createShiftGroup" />

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20">
        <svg class="animate-spin w-8 h-8 text-indigo-400" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
      </div>

      <template v-else>
        <div class="flex gap-6">
          <!-- Left Sidebar: Employee Pool (admin only) -->
          <EmployeeSidePanel
            v-if="canManageSchedule"
            v-model:empSort="empSort"
            v-model:showDelegations="showDelegations"
            v-model:showNewbies="showNewbies"
            v-model:showClosures="showClosures"
            v-model:showShiftColors="showShiftColors"
            :is-all-groups-mode="isAllGroupsMode"
            :side-accordion="sideAccordion"
            :sorted-employee-pool="sortedEmployeePool"
            :selected-employee="selectedEmployee"
            :get-employee-shift-count="getEmployeeShiftCount"
            :get-employee-total-hours="getEmployeeTotalHours"
            :is-current-newbie="isCurrentNewbie"
            :month-key="monthKey"
            :active-group-id="activeGroupId"
            :has-schedule-shifts="hasScheduleShifts"
            @deselect="deselectEmployee"
            @drag-start="onDragStart"
            @drag-end="onDragEnd"
            @employee-click="handleEmployeeClick"
            @filled="fetchSchedule"
            @toast="(msg, type) => showToast(msg, type)" />

          <!-- Right: Calendar Grid -->
          <div class="flex-1 min-w-0">
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
              <!-- Day Headers -->
              <div class="grid grid-cols-7 border-b border-white/10">
                <div v-for="day in weekDays" :key="day"
                  class="px-2 py-3 text-center text-xs font-semibold text-slate-400 uppercase tracking-wider">
                  {{ day }}
                </div>
              </div>

              <!-- Calendar Rows -->
              <div v-for="(week, wi) in calendarWeeks" :key="wi" class="grid grid-cols-7 border-b border-white/5 last:border-b-0">
                <div v-for="(day, di) in week" :key="di"
                  :data-date="day.dateStr"
                  :class="[
                    'border-r border-white/5 last:border-r-0 min-h-[160px] p-2 transition-colors',
                    day.isCurrentMonth ? 'bg-transparent' : 'bg-white/[0.02]',
                    day.isToday ? 'ring-1 ring-inset ring-indigo-500/30' : '',
                  ]"
                  :style="getDayAvailStyle(day.dateStr)">
                  <!-- Day Number + Shift Controls -->
                  <div class="flex items-center justify-between mb-2">
                    <span :class="[
                      'text-xs font-medium',
                      day.isToday ? 'text-indigo-400 font-bold' : day.isCurrentMonth ? 'text-slate-300' : 'text-slate-600'
                    ]">
                      {{ day.dayNumber }}
                    </span>
                    <div v-if="canManageSchedule && !isAllGroupsMode && day.isCurrentMonth && day.shifts.length > 0" class="flex items-center gap-0.5">
                      <button @click="changeDayShiftCount(day.dateStr, day.shifts.length - 1)"
                        :disabled="day.shifts.length <= 1"
                        :aria-label="`Remove a shift slot on ${day.dateStr}`"
                        class="w-5 h-5 flex items-center justify-center text-[10px] text-slate-500 hover:text-slate-300 hover:bg-white/10 rounded transition-all disabled:opacity-30 disabled:cursor-not-allowed">
                        −
                      </button>
                      <span class="text-[10px] text-slate-500 min-w-[14px] text-center">{{ day.shifts.length }}</span>
                      <button @click="changeDayShiftCount(day.dateStr, day.shifts.length + 1)"
                        :disabled="day.shifts.length >= 10"
                        :aria-label="`Add a shift slot on ${day.dateStr}`"
                        class="w-5 h-5 flex items-center justify-center text-[10px] text-slate-500 hover:text-slate-300 hover:bg-white/10 rounded transition-all disabled:opacity-30 disabled:cursor-not-allowed">
                        +
                      </button>
                    </div>
                  </div>

                  <!-- Overnight shifts continuing from previous day -->
                  <div v-if="getOvernightFromPrevDay(day.dateStr).length > 0" class="mb-1">
                    <div v-for="ov in getOvernightFromPrevDay(day.dateStr)" :key="'ov-' + ov.id"
                      class="flex items-center gap-1 px-1.5 py-0.5 bg-indigo-500/8 border border-indigo-500/15 rounded text-[9px] text-indigo-400/80 italic">
                      <span>↩</span>
                      <span>S{{ ov.shift_number }} ends {{ ov.end_time?.substring(0, 5) }}</span>
                      <span class="text-slate-500 ml-auto">{{ ov.assignments?.length || 0 }} crew</span>
                    </div>
                  </div>

                  <!-- Delegation badges -->
                  <div v-if="showDelegations && getDayDelegations(day.dateStr).length > 0" class="mb-1 space-y-0.5">
                    <div v-for="del in getDayDelegations(day.dateStr)" :key="'del-' + del.id"
                      class="flex items-center gap-1 px-1.5 py-0.5 bg-purple-500/12 border border-purple-500/20 rounded text-[9px] text-purple-300" :title="del.destination">
                      <span>📍</span>
                      <span class="truncate">{{ del.users.map(u => u.name).join(', ') }}</span>
                    </div>
                  </div>

                  <!-- Newbie badges -->
                  <div v-if="showNewbies && getDayNewbies(day.dateStr).length > 0" class="mb-1 space-y-0.5">
                    <div v-for="np in getDayNewbies(day.dateStr)" :key="'nb-' + np.id"
                      class="flex items-center gap-1 px-1.5 py-0.5 bg-yellow-500/12 border border-yellow-500/20 rounded text-[9px] text-yellow-300" :title="'Newbie: ' + np.user?.name">
                      <span>⭐</span>
                      <span class="truncate">{{ np.user?.name }}</span>
                    </div>
                  </div>

                  <!-- Office Closure badges -->
                  <div v-if="showClosures && getClosureForDate(day.dateStr)" class="mb-1">
                    <div class="flex items-center gap-1 px-1.5 py-0.5 bg-red-500/12 border border-red-500/20 rounded text-[9px] text-red-400"
                      :title="getClosureForDate(day.dateStr).description || 'Office closed'">
                      <span>🚫</span>
                      <span class="truncate">{{ getClosureForDate(day.dateStr).title }}</span>
                    </div>
                  </div>

                  <!-- Shift Slots: All Groups mode -->
                  <div v-if="day.isCurrentMonth && isAllGroupsMode" class="space-y-1.5">
                    <div v-for="groupBlock in groupShiftsByGroup(day.shifts)" :key="groupBlock.groupId"
                      class="rounded-lg overflow-hidden" :style="{ borderLeft: '3px solid ' + (groupBlock.color || '#64748b') }">
                      <div class="px-1.5 py-0.5 flex items-center gap-1" :style="{ backgroundColor: (groupBlock.color || '#64748b') + '10' }">
                        <span class="w-1.5 h-1.5 rounded-full shrink-0" :style="{ backgroundColor: groupBlock.color || '#64748b' }"></span>
                        <span class="text-[8px] font-semibold uppercase tracking-wider" :style="{ color: groupBlock.color || '#94a3b8' }">{{ groupBlock.name }}</span>
                      </div>
                      <div class="px-1 pb-1 space-y-0.5">
                        <div v-for="shift in groupBlock.shifts" :key="shift.id" class="group relative">
                          <div class="flex items-center gap-1 flex-wrap">
                            <span class="text-[9px] uppercase tracking-wide text-slate-500">S{{ shift.shift_number }}<template v-if="shift.shift_type"> · <span class="normal-case">{{ shift.shift_type }}</span></template></span>
                            <span v-if="shift.start_time" class="text-[8px] font-mono text-slate-400">{{ shift.start_time }}–{{ shift.end_time }}</span>
                            <span v-if="shift.work_mode" :class="['text-[7px]', shift.work_mode === 'remote' ? 'text-teal-400' : 'text-amber-400']">{{ shift.work_mode === 'remote' ? '🏠' : '🏢' }}</span>
                          </div>
                          <div class="flex flex-wrap gap-0.5 mt-0.5">
                            <span v-for="a in shift.assignments" :key="a.user_id"
                              :class="['px-1 py-0.5 rounded text-[10px] font-medium whitespace-nowrap',
                                a.has_change_request
                                  ? 'bg-orange-500/20 text-orange-300 border border-orange-500/30'
                                  : 'bg-indigo-500/15 text-indigo-300 border border-indigo-500/20']">
                              {{ a.user_name }}
                            </span>
                            <span v-if="!shift.assignments?.length" class="text-[9px] text-slate-600 italic">—</span>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div v-if="!day.shifts.length" class="text-[9px] text-slate-600 italic text-center py-2">No shifts</div>
                  </div>

                  <!-- Shift Slots: Single Group mode (original) -->
                  <div v-else-if="day.isCurrentMonth" class="space-y-1">
                    <div v-for="shift in day.shifts" :key="shift.id"
                      :class="['group relative rounded-lg p-1 -m-1 transition-all',
                        isActiveShift(shift) ? 'border border-emerald-500/40' : '',
                        isSelectedEmployeeAssigned(shift) && !isActiveShift(shift) ? 'border border-sky-500/40' : ''
                      ]"
                      :style="isActiveShift(shift) ? {
                        backgroundImage: 'repeating-linear-gradient(135deg, transparent, transparent 4px, rgba(16, 185, 129, 0.12) 4px, rgba(16, 185, 129, 0.12) 8px)',
                        boxShadow: 'inset 0 0 0 1px rgba(16, 185, 129, 0.2)'
                      } : getShiftCellStyle(shift)">
                      <div class="flex items-center gap-1 mb-0.5 flex-wrap">
                        <span :class="['text-[9px] uppercase tracking-wide', isActiveShift(shift) ? 'text-emerald-400 font-bold' : 'text-slate-500']">S{{ shift.shift_number }}<template v-if="shift.shift_type"> · <span class="normal-case">{{ shift.shift_type }}</span></template></span>
                        <span v-if="isActiveShift(shift)"
                          class="px-1 py-0.5 text-[7px] font-bold uppercase rounded bg-emerald-500/25 text-emerald-300 border border-emerald-500/30 animate-pulse tracking-wider">
                          ● LIVE
                        </span>
                        <!-- Time label -->
                        <button v-if="canManageSchedule && shift.start_time"
                          @click.stop="openTimeEditor(shift, day.dateStr)"
                          :title="isOvernightShift(shift) ? 'Overnight shift (ends next day)' : 'Edit shift times'"
                          class="px-1 py-0.5 text-[8px] font-mono text-slate-400 hover:text-indigo-300 bg-white/[0.03] hover:bg-indigo-500/10 border border-white/5 hover:border-indigo-500/20 rounded transition-all cursor-pointer">
                          {{ shift.start_time }}–{{ shift.end_time }}
                          <span v-if="isOvernightShift(shift)" class="text-[7px]">🌙</span>
                        </button>
                        <button v-else-if="canManageSchedule"
                          @click.stop="openTimeEditor(shift, day.dateStr)"
                          title="Set shift times"
                          class="px-1 py-0.5 text-[7px] text-slate-600 hover:text-slate-400 tracking-wide opacity-0 group-hover:opacity-100 transition-all cursor-pointer">
                          ⏰
                        </button>
                        <!-- Employee: read-only time -->
                        <span v-else-if="shift.start_time" class="px-1 py-0.5 text-[8px] font-mono text-slate-400 bg-white/[0.03] border border-white/5 rounded">
                          {{ shift.start_time }}–{{ shift.end_time }}
                          <span v-if="isOvernightShift(shift)" class="text-[7px]">🌙</span>
                        </span>
                        <!-- Work mode toggle -->
                        <button v-if="canManageSchedule" @click.stop="toggleWorkMode(shift)"
                          :title="shift.work_mode === 'remote' ? 'Remote shift — click to switch to Office' : 'Office shift — click to switch to Remote'"
                          :class="['px-1 py-0.5 text-[7px] rounded transition-all cursor-pointer border',
                            shift.work_mode === 'remote'
                              ? 'bg-teal-500/15 text-teal-300 border-teal-500/20 hover:bg-teal-500/25'
                              : 'bg-amber-500/10 text-amber-400/70 border-amber-500/15 hover:bg-amber-500/20']">
                          {{ shift.work_mode === 'remote' ? '🏠' : '🏢' }}
                        </button>
                        <!-- Checklist badge -->
                        <button v-if="canManageSchedule && shift.shift_checklists?.length"
                          @click.stop="openChecklistConfig(shift, day.dateStr)"
                          :title="shift.shift_checklists.map(sc => (sc.is_required ? '☑' : '☐') + ' ' + sc.checklist_template_name).join('\n')"
                          class="px-1 py-0.5 text-[7px] font-bold uppercase rounded tracking-wide transition-all bg-emerald-500/20 text-emerald-400 border border-emerald-500/25 hover:bg-emerald-500/30">
                          📋 ×{{ shift.shift_checklists.length }}
                        </button>
                        <button v-else-if="canManageSchedule"
                          @click.stop="openChecklistConfig(shift, day.dateStr)"
                          title="Configure checklists"
                          class="px-1 py-0.5 text-[7px] text-slate-600 hover:text-slate-400 uppercase tracking-wide opacity-0 group-hover:opacity-100 transition-all">
                          + CL
                        </button>
                      </div>
                      <VueDraggable
                        v-model="shift.assignments"
                        :group="{ name: 'shifts', pull: canManageSchedule, put: canManageSchedule }"
                        :data-shift-id="shift.id"
                        filter=".chip-pending"
                        :prevent-on-filter="false"
                        @add="onDrop($event, shift, day.dateStr)"
                        class="min-h-[28px] bg-white/[0.03] border border-dashed border-white/10 rounded-lg flex flex-wrap gap-1 p-1 transition-colors hover:border-indigo-500/30 hover:bg-indigo-500/5"
                      >
                        <!-- A chip whose assign is still being saved (no id yet) can't be dragged on:
                             the server doesn't know it yet, so moving it would leave the first one behind. -->
                        <div v-for="assignment in shift.assignments" :key="assignment.user_id"
                          :data-pending="assignment.id ? undefined : 'true'"
                          :class="['flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[11px] font-medium cursor-grab active:cursor-grabbing whitespace-nowrap min-w-0 max-w-full',
                            assignment.id ? '' : 'chip-pending opacity-60',
                            assignment.has_change_request
                              ? 'bg-amber-500/20 border border-amber-500/30 text-amber-300'
                              : 'bg-indigo-500/20 border border-indigo-500/30 text-indigo-300']"
                          :title="assignment.has_change_request ? '⚠ Day-off request' + (assignment.change_request_reason ? ': ' + assignment.change_request_reason : '') : ''">
                          <span :class="['w-4 h-4 rounded-full flex items-center justify-center text-[8px] font-bold shrink-0',
                            assignment.has_change_request ? 'bg-orange-500/30 text-orange-200' : 'bg-indigo-500/30 text-indigo-200']">
                            {{ getInitials(assignment.user_name) }}
                          </span>
                          <!-- The chip shrinks to its day column (min-w-0/max-w-full above); the name
                               truncates instead of pushing the × into the next day's drop-zone. -->
                          <span class="truncate min-w-0" :title="assignment.user_name">{{ assignment.user_name }}</span>
                          <!-- Only while the shift is still on a rest day: unassigning the shift that opened the window leaves the policy behind. -->
                          <span v-if="assignment.rest_policy && assignment.rest_policy !== 'not_counted' && assignment.resting_until" class="shrink-0 text-[9px] text-amber-300"
                            :title="assignment.rest_policy === 'extends_rest' ? 'On a rest day: extends rest' : 'On a rest day: counts toward next streak'">⏸</span>
                          <span v-if="assignment.in_rest" class="shrink-0 text-[9px] text-red-300"
                            :title="'Now on a rest day (resting until ' + assignment.resting_until + '): nobody chose how it counts'">⚠</span>
                          <!-- Employee: request day off button -->
                          <button v-if="currentUser && !isAdmin(currentUser) && currentUser.id === assignment.user_id && !assignment.has_change_request && isShiftFuture(shift, day.dateStr)"
                            @click.stop="openChangeRequestModal(assignment, shift, day.dateStr)"
                            class="ml-0.5 w-4 h-4 shrink-0 flex items-center justify-center text-[9px] text-slate-400 hover:text-orange-300 hover:bg-orange-500/15 rounded transition-all"
                            title="Request day off">
                            🔄
                          </button>
                          <!-- Pending badge for employee -->
                          <span v-if="currentUser && !isAdmin(currentUser) && currentUser.id === assignment.user_id && assignment.has_change_request"
                            class="ml-0.5 shrink-0 text-[7px] px-1 py-0.5 bg-orange-500/20 text-orange-300 rounded font-bold uppercase">Pending</span>
                          <!-- Admin: remove button -->
                          <button v-if="canManageSchedule && assignment.id" @click.stop="removeAssignment(assignment, shift)"
                            class="ml-0.5 w-3 h-3 shrink-0 flex items-center justify-center text-indigo-400/60 hover:text-red-400 transition-colors">
                            ×
                          </button>
                        </div>
                      </VueDraggable>
                    </div>
                  </div>
                </div>
            </div>
          </div>
          </div>
        </div>
      </template>
    </main>


    <datalist id="shift-type-suggestions">
      <option v-for="type in shiftTypeSuggestions" :key="type" :value="type" />
    </datalist>

    <!-- Checklist Config Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out"
      leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <ShiftChecklistsModal
        v-if="showChecklistModal"
        @close="showChecklistModal = false"
        @save="saveChecklistConfig"
        :checklist-config-shift="checklistConfigShift"
        :checklist-config-date="checklistConfigDate"
        :checklist-form="checklistForm"
        :checklist-templates="checklistTemplates"
        :saving-checklist="savingChecklist" />
    </Transition>

    <!-- Time Editor Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out"
      leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <ShiftTimesModal
        v-if="showTimeModal"
        @close="showTimeModal = false"
        @save="saveShiftTimes"
        :time-config-shift="timeConfigShift"
        :time-config-date="timeConfigDate"
        :time-form="timeForm"
        :shift-colors="shiftColors"
        :shift-color-by-id="shiftColorById"
        :saving-times="savingTimes" />
    </Transition>

    <!-- Group Settlement Settings Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <GroupSettingsModal
        v-if="showGroupSettingsModal"
        @close="showGroupSettingsModal = false"
        @save="saveGroupSettings"
        :group-settings-target="groupSettingsTarget"
        :group-settings-form="groupSettingsForm"
        :saving-group-settings="savingGroupSettings" />
    </Transition>

    <!-- Unavailable Assignment Confirmation Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <UnavailableConfirmModal
        v-if="availConfirm.show"
        @cancel="cancelAvailConfirm"
        @confirm="confirmAvailAssign"
        :avail-confirm="availConfirm" />
    </Transition>

    <!-- Delegation Conflict Confirmation Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <DelegationConfirmModal
        v-if="delegConfirm.show"
        @cancel="cancelDelegConfirm"
        @confirm="confirmDelegAssign"
        :deleg-confirm="delegConfirm" />
    </Transition>

    <!-- Office Closure Conflict Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <ClosureConflictModal
        v-if="closureConflict.show"
        @cancel="cancelClosureConflict"
        @generate-remote="proceedGenerateAsRemote"
        @generate-office="proceedGenerate"
        :closure-conflict="closureConflict" />
    </Transition>

    <!-- Adjacent Shift Confirmation Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <AdjacentShiftConfirmModal
        v-if="adjacentConfirm.show"
        @cancel="cancelAdjacentConfirm"
        @confirm="confirmAdjacentAssign"
        :adjacent-confirm="adjacentConfirm" />
    </Transition>

    <ShiftTypeBulkEditor v-if="showShiftTypeEditor" :group-id="activeGroupId" :month-key="monthKey" :schedule="schedule"
      @close="showShiftTypeEditor = false" @saved="onShiftTypesSaved" />

    <!-- Generate Month Config Modal -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0">
      <GenerateMonthModal
        v-if="showGenerateModal"
        @close="showGenerateModal = false"
        @generate="generateMonth"
        @shift-count-change="onGenShiftCountChange"
        @sync-next-start="syncNextShiftStart"
        :gen-config="genConfig"
        :month-label="monthLabel"
        :generating="generating"
        :shift-colors="shiftColors"
        :shift-color-by-id="shiftColorById"
        :checklist-templates="checklistTemplates"
        :donut-segments="donutSegments"
        :donut-gap-minutes="donutGapMinutes"
        :donut-overlap-minutes="donutOverlapMinutes" />
    </Transition>

    <!-- Delete Schedule Confirmation Modal -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0">
      <!-- Keyed by step: step 2 is a new alertdialog (focused and announced), not a re-render
           that would drop focus with step 1's removed buttons. -->
      <DeleteScheduleModal
        v-if="deleteStep > 0"
        :key="deleteStep"
        @close="deleteStep = 0"
        @continue="deleteStep = 2"
        @confirm="clearMonth"
        :delete-step="deleteStep"
        :month-label="monthLabel"
        :deleting="deleting" />
    </Transition>

    <RestDayPolicyModal v-if="restPrompt" :user-name="restPrompt.item.user_name || 'This employee'"
      :resting-until="restPrompt.conflict.resting_until || ''" :affected="restPrompt.conflict.affected || null" :rule="restPrompt.conflict.rule"
      @choose="chooseRestPolicy" @cancel="cancelRestPolicy" />

    <!-- Shift Change Request Modal -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0">
      <ChangeRequestModal
        v-if="changeRequestModal.show"
        @close="changeRequestModal.show = false"
        @submit="submitChangeRequest"
        :change-request-modal="changeRequestModal" />
    </Transition>

  </AppLayout>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { VueDraggable } from 'vue-draggable-plus';
import { useRoute, useRouter } from 'vue-router';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import RestDayPolicyModal from '../components/RestDayPolicyModal.vue';
import ShiftTypeBulkEditor from '../components/ShiftTypeBulkEditor.vue';
import AdjacentShiftConfirmModal from '../components/schedule/AdjacentShiftConfirmModal.vue';
import ChangeRequestModal from '../components/schedule/ChangeRequestModal.vue';
import ClosureConflictModal from '../components/schedule/ClosureConflictModal.vue';
import DelegationConfirmModal from '../components/schedule/DelegationConfirmModal.vue';
import DeleteScheduleModal from '../components/schedule/DeleteScheduleModal.vue';
import EmployeeSidePanel from '../components/schedule/EmployeeSidePanel.vue';
import GenerateMonthModal from '../components/schedule/GenerateMonthModal.vue';
import GroupSettingsModal from '../components/schedule/GroupSettingsModal.vue';
import ScheduleGroupTabs from '../components/schedule/ScheduleGroupTabs.vue';
import ShiftChecklistsModal from '../components/schedule/ShiftChecklistsModal.vue';
import ShiftTimesModal from '../components/schedule/ShiftTimesModal.vue';
import UnavailableConfirmModal from '../components/schedule/UnavailableConfirmModal.vue';
import { useAssignmentFlow } from '../composables/schedule/useAssignmentFlow.js';
import { useChangeRequest } from '../composables/schedule/useChangeRequest.js';
import { useEmployeeSelection } from '../composables/schedule/useEmployeeSelection.js';
import { useGenerateMonth } from '../composables/schedule/useGenerateMonth.js';
import { useScheduleMonth } from '../composables/schedule/useScheduleMonth.js';
import { useShiftEditors } from '../composables/schedule/useShiftEditors.js';
import { useShiftGroups } from '../composables/schedule/useShiftGroups.js';
import { confirm } from '../composables/useConfirm.js';
import { useToast } from '../composables/useToast.js';
import { putDayShiftCount } from '../dayShiftCount.js';
import { isAdmin, canManageSchedule as userCanManageSchedule } from '../roles.js';
import { currentUser } from '../router/index.js';
import { getInitials, groupShiftsByGroup, isOvernightShift } from '../schedule/helpers.js';

const router = useRouter();
const route = useRoute();

// Toast
const { showToast } = useToast();

const canManageSchedule = computed(() => userCanManageSchedule(currentUser.value));

// State
const showShiftTypeEditor = ref(false);
const sideAccordion = ref({ employees: true, autoFill: false, overlays: false });
// Shifts on right now, in every group (the all-groups view can show several).
const activeShiftIds = ref(new Set());
const isActiveShift = (shift) => activeShiftIds.value.has(shift.id);

// The active group tab (a group id, or 'all'): the month loads it, the group tabs switch it.
const activeGroupId = ref(null);
const isAllGroupsMode = computed(() => activeGroupId.value === 'all');

// Employee availability selection (read by the month's day lookups)
const selectedEmployee = ref(null);
const restPrompt = ref(null); // { shift, item, index, conflict, restPolicy, seenShiftIds }

// The month on screen and its overlays
const {
  loading,
  currentYear,
  currentMonth,
  schedule,
  hasScheduleShifts,
  employeePool,
  weekDays,
  monthKey,
  monthLabel,
  calendarWeeks,
  prevMonth,
  nextMonth,
  getOvernightFromPrevDay,
  showDelegations,
  monthDelegations,
  delegationsFailed,
  getDayDelegations,
  showNewbies,
  getDayNewbies,
  isCurrentNewbie,
  showClosures,
  getClosureForDate,
  showShiftColors,
  shiftColors,
  shiftColorById,
  fetchSchedule,
} = useScheduleMonth({
  activeGroupId,
  isAllGroupsMode,
  canManageSchedule,
  selectedEmployee,
  restPrompt,
  showToast,
});

// Shift groups
const {
  shiftGroups,
  archivedGroups,
  showArchivedGroups,
  restoringGroupId,
  showNewGroupInput,
  newGroupName,
  creatingGroup,
  newGroupColor,
  editingGroupId,
  editingGroupName,
  fetchShiftGroups,
  switchGroup,
  createShiftGroup,
  archiveShiftGroup,
  restoreShiftGroup,
  startGroupRename,
  saveGroupRename,
  showGroupSettingsModal,
  savingGroupSettings,
  groupSettingsTarget,
  groupSettingsForm,
  openGroupSettings,
  saveGroupSettings,
} = useShiftGroups({ activeGroupId, fetchSchedule, showToast });

// The roster, the selected employee's availability and the cell styles
const {
  empSort,
  getEmployeeShiftCount,
  getEmployeeTotalHours,
  sortedEmployeePool,
  onDragStart,
  onDragEnd,
  handleEmployeeClick,
  deselectEmployee,
  fetchAvailability,
  getDayAvailStyle,
  isSelectedEmployeeAssigned,
  getShiftCellStyle,
} = useEmployeeSelection({
  selectedEmployee,
  monthKey,
  schedule,
  employeePool,
  showShiftColors,
  shiftColorById,
});

// Drag-and-drop assigning (with its confirmations) and unassigning
const {
  availConfirm,
  adjacentConfirm,
  delegConfirm,
  onDrop,
  chooseRestPolicy,
  cancelRestPolicy,
  cancelAvailConfirm,
  confirmAvailAssign,
  cancelDelegConfirm,
  confirmDelegAssign,
  cancelAdjacentConfirm,
  confirmAdjacentAssign,
  removeAssignment,
} = useAssignmentFlow({
  schedule,
  monthKey,
  monthDelegations,
  delegationsFailed,
  restPrompt,
  fetchSchedule,
  showToast,
});

// Generate Month (and its office-closure conflict) and Delete Schedule
const {
  generating,
  deleteStep,
  deleting,
  clearMonth,
  closureConflict,
  showGenerateModal,
  genConfig,
  openGenerateModal,
  onGenShiftCountChange,
  syncNextShiftStart,
  donutSegments,
  donutGapMinutes,
  donutOverlapMinutes,
  generateMonth,
  cancelClosureConflict,
  proceedGenerate,
  proceedGenerateAsRemote,
} = useGenerateMonth({
  monthKey,
  activeGroupId,
  schedule,
  shiftColorById,
  fetchSchedule,
  showToast,
});

// The Shift Checklists and Shift Times dialogs
const {
  checklistTemplates,
  showChecklistModal,
  checklistConfigShift,
  checklistConfigDate,
  savingChecklist,
  checklistForm,
  showTimeModal,
  timeConfigShift,
  timeConfigDate,
  savingTimes,
  timeForm,
  fetchTemplates,
  openChecklistConfig,
  saveChecklistConfig,
  openTimeEditor,
  saveShiftTimes,
} = useShiftEditors({ canManageSchedule, showToast });

// Request Day Off (employees, their own future shifts)
const { changeRequestModal, isShiftFuture, openChangeRequestModal, submitChangeRequest } =
  useChangeRequest({
    schedule,
    showToast,
  });

async function onShiftTypesSaved({ updated, rebooked }) {
  showShiftTypeEditor.value = false;
  const counters =
    rebooked > 0
      ? `; streak counters adjusted for ${rebooked} assignment${rebooked === 1 ? '' : 's'}`
      : '';
  showToast(`${updated} shift${updated === 1 ? '' : 's'} updated${counters}.`);
  await fetchSchedule();
}

// Streak rules match shift types exactly, so offer the active group's rule types plus types
// already used this month; free text is still allowed.
const shiftTypeSuggestions = computed(() => {
  const group = shiftGroups.value.find((g) => g.id === activeGroupId.value);
  const fromRules = (group?.streak_rules ?? []).map((r) => r.shift_type);
  const fromMonth = Object.values(schedule.value ?? {})
    .flat()
    .map((s) => s.shift_type);
  return [...new Set([...fromRules, ...fromMonth].filter(Boolean))].sort();
});

async function toggleWorkMode(shift) {
  const newMode = shift.work_mode === 'remote' ? 'office' : 'remote';
  try {
    await api.put(`/api/schedule/shifts/${shift.id}/work-mode`, { work_mode: newMode });
    shift.work_mode = newMode;
  } catch {
    showToast('Failed to update work mode.', 'error');
  }
}

async function changeDayShiftCount(dateStr, newCount) {
  if (newCount < 1 || newCount > 10) return;
  try {
    // Removing staffed slots asks first (and again if the list changed); the count only changes
    // once saved, so a cancel leaves the day as it was.
    const result = await putDayShiftCount({
      put: (body) => api.put('/api/schedule/day-shifts', body),
      confirm,
      payload: { date: dateStr, count: newCount, shift_group_id: activeGroupId.value },
    });
    if (result.status === 'gave_up') showToast(result.message, 'error');
    if (result.status !== 'saved') return;
    schedule.value = { ...schedule.value, [dateStr]: result.response.data.shifts };
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to update shift count.', 'error');
  }
}

// Watch month changes
watch(monthKey, (newVal) => {
  fetchSchedule();
  if (selectedEmployee.value) fetchAvailability(selectedEmployee.value.id);
  // Sync URL with current month
  router.replace({ name: 'schedule', params: { month: newVal }, query: route.query });
});

onMounted(async () => {
  // Read params for month, and query params for highlight/scroll
  const qMonth = route.params.month || route.query.month;
  const highlightUserId = route.query.highlight_user;
  const scrollDate = route.query.scroll_date;
  // ?group=<id> (e.g. from a change request): open that group's tab, if it is an active group.
  const groupFromQuery = Number(route.query.group) || null;

  // Navigate to the correct month if specified
  if (qMonth) {
    const [y, m] = qMonth.split('-').map(Number);
    if (y && m) {
      currentYear.value = y;
      currentMonth.value = m - 1;
    }
  }

  // Load shift groups first so activeGroupId is set
  await fetchShiftGroups();
  if (groupFromQuery && shiftGroups.value.some((g) => g.id === groupFromQuery)) {
    activeGroupId.value = groupFromQuery;
  }

  await fetchSchedule();
  fetchTemplates();
  try {
    const res = await api.get('/api/current-shift');
    activeShiftIds.value = new Set((res.data.active_shifts || []).map((s) => s.id));
  } catch {}

  // Auto-select highlighted employee after data loads
  if (highlightUserId) {
    const empId = parseInt(highlightUserId);
    const emp = employeePool.value.find((e) => e.id === empId);
    if (emp) {
      selectedEmployee.value = emp;
      fetchAvailability(emp.id);
    }
  }

  // Scroll to specific date cell
  if (scrollDate) {
    await nextTick();
    const cell = document.querySelector(`[data-date="${scrollDate}"]`);
    if (cell) {
      cell.scrollIntoView({ behavior: 'smooth', block: 'center' });
      cell.style.transition = 'box-shadow 0.3s';
      cell.style.boxShadow = '0 0 0 3px rgba(99, 102, 241, 0.5) inset';
      setTimeout(() => {
        cell.style.boxShadow = '';
      }, 3000);
    }
  }

  // Clean up highlight/scroll query params, set month in path
  if (highlightUserId || scrollDate || groupFromQuery) {
    router.replace({ name: 'schedule', params: { month: monthKey.value } });
  } else if (!route.params.month) {
    router.replace({ name: 'schedule', params: { month: monthKey.value } });
  }
});
</script>

