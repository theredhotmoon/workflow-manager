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
      <div v-if="shiftGroups.length > 0" class="mb-4">
        <div class="flex items-center gap-2 flex-wrap">
          <!-- All Groups tab -->
          <button @click="switchGroup('all')"
            :class="['flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-xl border transition-all duration-200',
              isAllGroupsMode
                ? 'border-transparent text-white ring-1 ring-purple-500/40 shadow-lg shadow-purple-500/10'
                : 'bg-white/5 border-white/10 text-slate-400 hover:bg-white/10 hover:text-slate-300 hover:border-white/20']"
            :style="isAllGroupsMode ? { background: 'linear-gradient(135deg, rgba(99,102,241,0.25), rgba(168,85,247,0.25), rgba(236,72,153,0.15))' } : {}">
            <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background: linear-gradient(135deg, #6366f1, #a855f7, #ec4899)"></span>
            All Groups
          </button>

          <button v-for="group in shiftGroups" :key="group.id"
            @click="switchGroup(group.id)"
            :class="['flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-xl border transition-all duration-200',
              activeGroupId === group.id
                ? 'bg-indigo-500/20 border-indigo-500/40 text-indigo-300 ring-1 ring-indigo-500/30 shadow-lg shadow-indigo-500/10'
                : 'bg-white/5 border-white/10 text-slate-400 hover:bg-white/10 hover:text-slate-300 hover:border-white/20']">
            <span v-if="group.color" class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: group.color }"></span>
            <span v-else class="w-2.5 h-2.5 rounded-full shrink-0 bg-slate-500/50"></span>

            <!-- Inline rename -->
            <template v-if="isAdmin(currentUser) && editingGroupId === group.id">
              <input v-model="editingGroupName" type="text" @keyup.enter="saveGroupRename" @keyup.escape="editingGroupId = null" @blur="saveGroupRename"
                class="w-24 px-1.5 py-0.5 bg-white/10 border border-indigo-500/30 rounded text-white text-xs focus:outline-none focus:ring-1 focus:ring-indigo-500/50"
                ref="groupRenameInput" />
            </template>
            <template v-else>
              <span @dblclick.stop="isAdmin(currentUser) && startGroupRename(group)">{{ group.name }}</span>
            </template>

            <span v-if="activeGroupId === group.id && group.is_default" class="text-[8px] text-indigo-400/60 uppercase tracking-wider">default</span>

            <!-- Delete group button (admin only, non-default) -->
            <button v-if="isAdmin(currentUser) && !group.is_default && activeGroupId === group.id"
              @click.stop="deleteShiftGroup(group)"
              class="w-4 h-4 flex items-center justify-center text-slate-500 hover:text-red-400 transition-colors ml-1"
              title="Delete group">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <!-- Group settlement settings (admin only: saving writes the group) -->
            <button v-if="isAdmin(currentUser) && activeGroupId === group.id"
              @click.stop="openGroupSettings(group)"
              class="w-4 h-4 flex items-center justify-center text-slate-500 hover:text-indigo-400 transition-colors ml-1"
              title="Settlement period settings">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </button>
          </button>

          <!-- New Group button (admin only) -->
          <button v-if="isAdmin(currentUser)" @click="showNewGroupInput = !showNewGroupInput"
            class="flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border border-dashed border-white/15 text-slate-500 hover:text-indigo-400 hover:border-indigo-500/30 hover:bg-indigo-500/5 transition-all">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            New Group
          </button>
        </div>

        <!-- New group inline form -->
        <Transition enter-active-class="transition-all duration-200 ease-out" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0"
          leave-active-class="transition-all duration-150 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
          <div v-if="showNewGroupInput && isAdmin(currentUser)" class="mt-3 flex items-center gap-2">
            <input v-model="newGroupColor" type="color" class="w-8 h-8 rounded-lg border border-white/10 cursor-pointer bg-transparent shrink-0" />
            <input v-model="newGroupName" type="text" placeholder="Group name (e.g. Event Coverage)"
              class="flex-1 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500"
              @keyup.enter="createShiftGroup" />
            <button @click="createShiftGroup" :disabled="!newGroupName.trim()"
              class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl shadow-lg shadow-indigo-500/25 transition-all text-sm disabled:opacity-40 disabled:cursor-not-allowed">Create</button>
            <button @click="showNewGroupInput = false" class="px-3 py-2 text-sm text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
          </div>
        </Transition>
      </div>

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
          <div v-if="canManageSchedule" class="w-64 shrink-0">

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
                  <button @click="deselectEmployee" class="text-slate-400 hover:text-white text-sm leading-none">×</button>
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
                v-model="sortedEmployeePool"
                :group="{ name: 'shifts', pull: 'clone', put: false }"
                :sort="false"
                :clone="cloneEmployee"
                @start="onDragStart"
                @end="onDragEnd"
                class="space-y-1.5"
              >
                <div v-for="emp in sortedEmployeePool" :key="emp.id"
                  @click="handleEmployeeClick(emp)"
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
                  <AutoFillPanel v-if="!isAllGroupsMode" :monthKey="monthKey" :shiftGroupId="activeGroupId" :disabled="!hasScheduleShifts" @filled="fetchSchedule" @toast="(msg, type) => showToast(msg, type)" />
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
                        activeShiftId === shift.id ? 'border border-emerald-500/40' : '',
                        isSelectedEmployeeAssigned(shift) && activeShiftId !== shift.id ? 'border border-sky-500/40' : ''
                      ]"
                      :style="activeShiftId === shift.id ? {
                        backgroundImage: 'repeating-linear-gradient(135deg, transparent, transparent 4px, rgba(16, 185, 129, 0.12) 4px, rgba(16, 185, 129, 0.12) 8px)',
                        boxShadow: 'inset 0 0 0 1px rgba(16, 185, 129, 0.2)'
                      } : getShiftCellStyle(shift)">
                      <div class="flex items-center gap-1 mb-0.5 flex-wrap">
                        <span :class="['text-[9px] uppercase tracking-wide', activeShiftId === shift.id ? 'text-emerald-400 font-bold' : 'text-slate-500']">S{{ shift.shift_number }}<template v-if="shift.shift_type"> · <span class="normal-case">{{ shift.shift_type }}</span></template></span>
                        <span v-if="activeShiftId === shift.id"
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
                        @add="onDrop($event, shift, day.dateStr)"
                        class="min-h-[28px] bg-white/[0.03] border border-dashed border-white/10 rounded-lg flex flex-wrap gap-1 p-1 transition-colors hover:border-indigo-500/30 hover:bg-indigo-500/5"
                      >
                        <div v-for="assignment in shift.assignments" :key="assignment.user_id"
                          :class="['flex items-center gap-1 px-1.5 py-0.5 rounded-md text-[11px] font-medium cursor-grab active:cursor-grabbing whitespace-nowrap min-w-0 max-w-full',
                            assignment.has_change_request
                              ? 'bg-amber-500/20 border border-amber-500/30 text-amber-300'
                              : 'bg-indigo-500/20 border border-indigo-500/30 text-indigo-300']"
                          :title="assignment.has_change_request ? '⚠ Day-off request: ' + assignment.change_request_reason : ''">
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
                          <button v-if="canManageSchedule" @click.stop="removeAssignment(assignment, shift)"
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

    <AppToast />

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
      <div v-if="showChecklistModal" class="fixed inset-0 z-50 flex items-center justify-center px-4" @click.self="showChecklistModal = false">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showChecklistModal = false"></div>
        <div class="relative w-full max-w-lg bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6">
          <h3 class="text-white font-bold text-lg mb-1">Shift Checklists</h3>
          <p class="text-slate-400 text-sm mb-5">{{ checklistConfigDate }} · Shift {{ checklistConfigShift?.shift_number }}</p>

          <!-- Checklist rows -->
          <div class="space-y-3 mb-4 max-h-[50vh] overflow-y-auto">
            <div v-for="(item, idx) in checklistForm" :key="idx"
              class="bg-white/[0.03] border border-white/10 rounded-xl p-3 space-y-2">
              <div class="flex items-center gap-2">
                <span class="text-[10px] text-slate-500 font-bold w-5 shrink-0">#{{ idx + 1 }}</span>
                <select v-model="item.checklist_template_id"
                  class="flex-1 px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 cursor-pointer">
                  <option :value="null" class="bg-slate-800 text-slate-400">Select template...</option>
                  <option v-for="t in checklistTemplates" :key="t.id" :value="t.id" class="bg-slate-800">{{ t.name }}</option>
                </select>
                <button @click="checklistForm.splice(idx, 1)" class="p-1 text-slate-500 hover:text-red-400 transition-colors" title="Remove">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
              </div>
              <div v-if="item.checklist_template_id" class="flex items-center gap-3 flex-wrap">
                <!-- Required toggle -->
                <label class="flex items-center gap-1.5 cursor-pointer">
                  <input type="checkbox" v-model="item.is_required"
                    class="w-3.5 h-3.5 rounded border-white/20 bg-white/5 text-emerald-500 focus:ring-emerald-500/30" />
                  <span class="text-[10px] text-slate-400 uppercase tracking-wide">Required</span>
                </label>
                <!-- Deadline -->
                <div class="flex items-center gap-1.5">
                  <span class="text-[10px] text-slate-500 uppercase">Deadline:</span>
                  <input v-model.number="item.deadline_minutes" type="number" min="1" placeholder="min"
                    class="w-16 px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
                  <select v-model="item.deadline_reference"
                    class="px-2 py-1 bg-white/5 border border-white/10 rounded-lg text-white text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/50 cursor-pointer">
                    <option value="shift_start" class="bg-slate-800">after start</option>
                    <option value="shift_end" class="bg-slate-800">before end</option>
                  </select>
                </div>
              </div>
            </div>
            <div v-if="!checklistForm.length" class="text-center text-slate-500 text-sm py-4">
              No checklists assigned. Click "Add Checklist" below.
            </div>
          </div>

          <!-- Add button -->
          <button @click="checklistForm.push({ checklist_template_id: null, is_required: false, deadline_minutes: null, deadline_reference: 'shift_start' })"
            class="w-full mb-5 px-3 py-2 text-sm text-indigo-400 bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/20 rounded-xl transition-all">
            + Add Checklist
          </button>

          <!-- Actions -->
          <div class="flex items-center justify-end gap-3">
            <button @click="showChecklistModal = false"
              class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">
              Cancel
            </button>
            <button @click="saveChecklistConfig" :disabled="savingChecklist"
              class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
              <svg v-if="savingChecklist" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              Save
            </button>
          </div>
        </div>
      </div>
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
      <div v-if="showTimeModal" class="fixed inset-0 z-50 flex items-center justify-center px-4" @click.self="showTimeModal = false">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showTimeModal = false"></div>
        <div class="relative w-full max-w-sm bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6">
          <h3 class="text-white font-bold text-lg mb-1">Shift Times</h3>
          <p class="text-slate-400 text-sm mb-5">{{ timeConfigDate }} · Shift {{ timeConfigShift?.shift_number }}</p>

          <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Start Time</label>
              <input v-model="timeForm.start_time" type="time"
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">End Time</label>
              <input v-model="timeForm.end_time" type="time"
                class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
            </div>
          </div>

          <!-- Overnight indicator -->
          <div v-if="timeForm.start_time && timeForm.end_time && timeForm.end_time < timeForm.start_time"
            class="flex items-center gap-2 px-3 py-2 mb-4 bg-indigo-500/10 border border-indigo-500/20 rounded-xl">
            <span class="text-sm">🌙</span>
            <span class="text-xs text-indigo-300">Overnight shift — ends the next day</span>
          </div>

          <!-- Color Picker -->
          <div class="mb-4">
            <label class="block text-sm font-medium text-slate-300 mb-2">Shift Color</label>
            <div class="flex flex-wrap gap-2 mb-2">
              <button v-for="colorDef in shiftColors" :key="colorDef.id"
                @click="timeForm.color = timeForm.color == colorDef.id ? null : String(colorDef.id)"
                :class="['w-8 h-8 rounded-lg border-2 transition-all flex items-center justify-center text-[10px] font-bold',
                  timeForm.color == colorDef.id ? 'border-white scale-110 shadow-lg' : 'border-transparent hover:border-white/30']"
                :style="{ backgroundColor: colorDef.hex }"
                :title="colorDef.name">
                <span v-if="timeForm.color == colorDef.id" class="text-white drop-shadow">✓</span>
              </button>
              <button @click="timeForm.color = null; timeForm.color_label = ''"
                :class="['w-8 h-8 rounded-lg border-2 transition-all flex items-center justify-center text-xs',
                  !timeForm.color ? 'border-white/40 bg-white/10' : 'border-white/10 bg-white/5 hover:border-white/20']"
                title="No color">
                ✕
              </button>
            </div>
            <input v-if="timeForm.color" v-model="timeForm.color_label" type="text" :placeholder="'Label (default: ' + (shiftColorById[timeForm.color]?.name || '') + ')'"
              class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
          </div>

          <div class="mb-4">
            <label for="shift-edit-type" class="block text-sm font-medium text-slate-300 mb-1.5">Shift type</label>
            <input id="shift-edit-type" v-model="timeForm.shift_type" type="text" maxlength="50"
              list="shift-type-suggestions" placeholder="e.g. night (used by streak rules)"
              class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
          </div>

          <div class="flex items-center justify-end gap-3">
            <button @click="showTimeModal = false"
              class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">
              Cancel
            </button>
            <button @click="saveShiftTimes" :disabled="savingTimes"
              class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
              <svg v-if="savingTimes" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              Save
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Group Settlement Settings Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="showGroupSettingsModal" class="fixed inset-0 z-50 flex items-center justify-center px-4" @click.self="showGroupSettingsModal = false">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showGroupSettingsModal = false"></div>
        <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50 p-6">
          <h3 class="text-white font-bold text-lg mb-1">Settlement Period Settings</h3>
          <p class="text-slate-400 text-sm mb-5">{{ groupSettingsTarget?.name }}</p>

          <div class="mb-4">
            <label for="settlement-mode" class="block text-sm font-medium text-slate-300 mb-1.5">Mode</label>
            <select id="settlement-mode" v-model="groupSettingsForm.settlement_mode"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50">
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
              <option value="quarterly">Quarterly</option>
              <option value="custom">Custom</option>
            </select>
          </div>

          <div class="mb-4">
            <label for="settlement-anchor-date" class="block text-sm font-medium text-slate-300 mb-1.5">Anchor Date</label>
            <input id="settlement-anchor-date" v-model="groupSettingsForm.settlement_anchor_date" type="date"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div v-if="groupSettingsForm.settlement_mode === 'custom'" class="mb-4">
            <label for="settlement-custom-length" class="block text-sm font-medium text-slate-300 mb-1.5">Custom Length (days)</label>
            <input id="settlement-custom-length" v-model.number="groupSettingsForm.settlement_custom_length_days" type="number" min="1"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div class="mb-5">
            <label for="settlement-weekly-hour-cap" class="block text-sm font-medium text-slate-300 mb-1.5">Weekly Hour Cap</label>
            <input id="settlement-weekly-hour-cap" v-model.number="groupSettingsForm.weekly_hour_cap" type="number" min="0" step="0.5"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <div class="mb-5">
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Shift Streak Rules</label>
            <div v-for="(rule, idx) in groupSettingsForm.streak_rules" :key="idx" class="flex items-center gap-2 mb-2">
              <input v-model="rule.shift_type" type="text" placeholder="shift type (e.g. night)"
                class="flex-1 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500" />
              <input v-model.number="rule.streak_length" type="number" min="1" title="Streak length"
                class="w-16 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
              <span class="text-slate-500 text-xs whitespace-nowrap">then rest</span>
              <input v-model.number="rule.rest_days" type="number" min="1" title="Rest days"
                class="w-16 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
              <span class="text-slate-500 text-xs whitespace-nowrap">days</span>
              <button @click="groupSettingsForm.streak_rules.splice(idx, 1)"
                class="w-6 h-6 flex items-center justify-center text-slate-500 hover:text-red-400 transition-colors shrink-0" title="Remove rule">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
              </button>
            </div>
            <button @click="groupSettingsForm.streak_rules.push({ shift_type: '', streak_length: 3, rest_days: 2 })"
              class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">+ Add streak rule</button>
          </div>

          <div class="flex items-center justify-end gap-3">
            <button @click="showGroupSettingsModal = false" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Cancel</button>
            <button @click="saveGroupSettings" :disabled="savingGroupSettings"
              class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
              <svg v-if="savingGroupSettings" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
              Save
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Unavailable Assignment Confirmation Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="availConfirm.show" class="fixed inset-0 z-[60] flex items-center justify-center px-4">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
        <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-red-500/30 rounded-2xl shadow-2xl shadow-red-500/10 p-6">
          <div class="flex items-start gap-4 mb-5">
            <div class="w-12 h-12 rounded-xl bg-red-500/20 flex items-center justify-center shrink-0">
              <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
              </svg>
            </div>
            <div class="flex-1">
              <h3 class="text-red-300 font-bold text-lg mb-1">⚠️ Unavailable Period</h3>
              <p class="text-slate-300 text-sm mb-2">
                <strong class="text-white">{{ availConfirm.userName }}</strong> has marked
                <strong class="text-red-300">{{ availConfirm.dateStr }}</strong> as unavailable.
              </p>
              <div v-if="availConfirm.entries.length" class="space-y-1.5 mb-3">
                <div v-for="(entry, idx) in availConfirm.entries" :key="idx"
                  class="flex items-center gap-2 px-3 py-2 bg-red-500/10 border border-red-500/20 rounded-lg">
                  <span class="text-red-400 text-sm">🚫</span>
                  <div class="text-sm">
                    <span class="text-red-200 font-medium">{{ entry.start_time ? entry.start_time + '–' + entry.end_time : 'All day' }}</span>
                    <span v-if="entry.note" class="text-slate-400 ml-2">— "{{ entry.note }}"</span>
                  </div>
                </div>
              </div>
              <p class="text-slate-400 text-sm">Do you still want to assign this person to the shift?</p>
            </div>
          </div>
          <div class="flex items-center justify-end gap-3">
            <button @click="cancelAvailConfirm"
              class="px-5 py-2.5 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
              Cancel
            </button>
            <button @click="confirmAvailAssign"
              class="px-5 py-2.5 bg-red-500 hover:bg-red-600 text-white font-semibold rounded-xl shadow-lg shadow-red-500/25 transition-all text-sm">
              Assign Anyway
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Delegation Conflict Confirmation Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="delegConfirm.show" class="fixed inset-0 z-[60] flex items-center justify-center px-4">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
        <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-purple-500/30 rounded-2xl shadow-2xl shadow-purple-500/10 p-6">
          <div class="flex items-start gap-4 mb-5">
            <div class="w-12 h-12 rounded-xl bg-purple-500/20 flex items-center justify-center shrink-0">
              <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </div>
            <div class="flex-1">
              <h3 class="text-purple-300 font-bold text-lg mb-1">📍 On Delegation</h3>
              <p class="text-slate-300 text-sm mb-2">
                <strong class="text-white">{{ delegConfirm.userName }}</strong> is on delegation during
                <strong class="text-purple-300">{{ delegConfirm.dateStr }}</strong>.
              </p>
              <div v-if="delegConfirm.delegations.length" class="space-y-1.5 mb-3">
                <div v-for="(del, idx) in delegConfirm.delegations" :key="idx"
                  class="flex items-center gap-2 px-3 py-2 bg-purple-500/10 border border-purple-500/20 rounded-lg">
                  <span class="text-purple-400 text-sm">📍</span>
                  <div class="text-sm">
                    <span class="text-purple-200 font-medium">{{ del.name }}</span>
                    <span class="text-slate-400 ml-2">— {{ del.destination }}</span>
                  </div>
                </div>
              </div>
              <p class="text-slate-400 text-sm">Do you still want to assign this person to the shift?</p>
            </div>
          </div>
          <div class="flex items-center justify-end gap-3">
            <button @click="cancelDelegConfirm"
              class="px-5 py-2.5 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
              Cancel
            </button>
            <button @click="confirmDelegAssign"
              class="px-5 py-2.5 bg-purple-500 hover:bg-purple-600 text-white font-semibold rounded-xl shadow-lg shadow-purple-500/25 transition-all text-sm">
              Assign Anyway
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Office Closure Conflict Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="closureConflict.show" class="fixed inset-0 z-[60] flex items-center justify-center px-4">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
        <div class="relative w-full max-w-lg bg-slate-800/95 backdrop-blur-xl border border-orange-500/30 rounded-2xl shadow-2xl shadow-orange-500/10 p-6">
          <div class="flex items-start gap-4 mb-5">
            <div class="w-12 h-12 rounded-xl bg-orange-500/20 flex items-center justify-center shrink-0">
              <svg class="w-6 h-6 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
              </svg>
            </div>
            <div class="flex-1">
              <h3 class="text-orange-300 font-bold text-lg mb-1">🏢❌ Office Closed on Some Dates</h3>
              <p class="text-slate-300 text-sm mb-3">
                You're generating <strong class="text-amber-300">office shifts</strong> but the office is
                <strong class="text-orange-300">closed</strong> on the following dates:
              </p>
              <div class="space-y-1.5 mb-3 max-h-[200px] overflow-y-auto">
                <div v-for="c in closureConflict.closures" :key="c.id"
                  class="flex items-center gap-2 px-3 py-2 bg-orange-500/10 border border-orange-500/20 rounded-lg">
                  <span class="text-orange-400 text-sm">📅</span>
                  <div class="text-sm flex-1">
                    <span class="text-orange-200 font-medium">{{ formatClosureDate(c.date) }}</span>
                    <span class="text-white ml-2">— {{ c.title }}</span>
                    <p v-if="c.description" class="text-slate-500 text-[10px] mt-0.5">{{ c.description }}</p>
                  </div>
                </div>
              </div>
              <p class="text-slate-400 text-sm">These shifts will still be created as "office" type. You can change them individually later.</p>
            </div>
          </div>
          <div class="flex items-center justify-end gap-2 flex-wrap">
            <button @click="cancelClosureConflict"
              class="px-4 py-2.5 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
              Cancel
            </button>
            <button @click="proceedGenerateAsRemote"
              class="px-4 py-2.5 bg-slate-600 hover:bg-slate-500 text-white font-semibold rounded-xl transition-all text-sm flex items-center gap-1.5">
              🏠 Switch to Remote
            </button>
            <button @click="proceedGenerate"
              class="px-4 py-2.5 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm">
              Generate as Office
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Adjacent Shift Confirmation Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="adjacentConfirm.show" class="fixed inset-0 z-[60] flex items-center justify-center px-4">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
        <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-amber-500/30 rounded-2xl shadow-2xl shadow-amber-500/10 p-6">
          <div class="flex items-start gap-4 mb-5">
            <div class="w-12 h-12 rounded-xl bg-amber-500/20 flex items-center justify-center shrink-0">
              <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <div class="flex-1">
              <h3 class="text-amber-300 font-bold text-lg mb-1">⏰ Back-to-Back Shifts</h3>
              <p class="text-slate-300 text-sm mb-2">
                <strong class="text-white">{{ adjacentConfirm.userName }}</strong> is already assigned to an adjacent shift:
              </p>
              <div class="space-y-1.5 mb-3">
                <div v-for="(conflict, idx) in adjacentConfirm.conflicts" :key="idx"
                  class="flex items-center gap-2 px-3 py-2 bg-amber-500/10 border border-amber-500/20 rounded-lg">
                  <span class="text-amber-400 text-sm">🔄</span>
                  <div class="text-sm">
                    <span class="text-amber-200 font-medium">
                      {{ conflict.date }} · S{{ conflict.shiftNumber }}
                    </span>
                    <span v-if="conflict.startTime" class="text-slate-400 ml-1">
                      ({{ conflict.startTime }}–{{ conflict.endTime }})
                    </span>
                    <span class="text-slate-500 ml-1">— {{ conflict.position }}</span>
                  </div>
                </div>
              </div>
              <p class="text-slate-400 text-sm">This employee would work consecutive shifts without a break. Assign anyway?</p>
            </div>
          </div>
          <div class="flex items-center justify-end gap-3">
            <button @click="cancelAdjacentConfirm"
              class="px-5 py-2.5 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
              Cancel
            </button>
            <button @click="confirmAdjacentAssign"
              class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-xl shadow-lg shadow-amber-500/25 transition-all text-sm">
              Assign Anyway
            </button>
          </div>
        </div>
      </div>
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
      <div v-if="showGenerateModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="showGenerateModal = false">
        <div class="bg-slate-800/95 border border-white/10 rounded-2xl shadow-2xl w-full max-w-4xl mx-4 max-h-[85vh] flex flex-col">
          <!-- Modal Header -->
          <div class="p-6 pb-3 border-b border-white/10 shrink-0">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
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
                      @input="onGenShiftCountChange"
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
                          @blur="syncNextShiftStart(idx)"
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
                    <span class="text-[10px] text-slate-500 italic">Uncovered: {{ formatDonutDuration(donutGapMinutes) }}</span>
                  </div>
                  <div v-if="donutOverlapMinutes > 0" class="flex items-center gap-2 px-3 py-1.5 bg-red-500/5 border border-red-500/10 rounded-lg">
                    <span class="text-[10px] text-red-400">⚠ Overlap: {{ formatDonutDuration(donutOverlapMinutes) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Modal Footer -->
          <div class="p-6 pt-3 border-t border-white/10 shrink-0 flex items-center justify-end gap-3">
            <button @click="showGenerateModal = false"
              class="px-5 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">
              Cancel
            </button>
            <button @click="generateMonth" :disabled="generating"
              class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
              <svg v-if="generating" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              Generate {{ monthLabel }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Delete Schedule Confirmation Modal -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0">
      <div v-if="deleteStep > 0" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="deleteStep = 0">
        <div class="bg-slate-800/95 border border-red-500/20 rounded-2xl shadow-2xl w-full max-w-sm mx-4">
          <!-- Step 1 -->
          <template v-if="deleteStep === 1">
            <div class="p-6 text-center">
              <div class="w-14 h-14 rounded-2xl bg-red-500/15 flex items-center justify-center mx-auto mb-4">
                <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </div>
              <h3 class="text-lg font-bold text-white mb-2">Delete {{ monthLabel }} Schedule?</h3>
              <p class="text-sm text-slate-400">This will remove all shifts and assigned users for this month.</p>
            </div>
            <div class="p-4 pt-0 flex gap-3">
              <button @click="deleteStep = 0" class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
              <button @click="deleteStep = 2" class="flex-1 px-4 py-2.5 text-sm text-red-300 bg-red-500/15 hover:bg-red-500/25 border border-red-500/25 rounded-xl transition-all font-medium">Yes, continue</button>
            </div>
          </template>
          <!-- Step 2 (final confirm) -->
          <template v-if="deleteStep === 2">
            <div class="p-6 text-center">
              <div class="w-14 h-14 rounded-2xl bg-red-500/20 flex items-center justify-center mx-auto mb-4 animate-pulse">
                <svg class="w-7 h-7 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
              </div>
              <h3 class="text-lg font-bold text-red-300 mb-2">This cannot be undone!</h3>
              <p class="text-sm text-slate-400">All shifts, assignments, and checklist data for <strong class="text-white">{{ monthLabel }}</strong> will be permanently deleted.</p>
            </div>
            <div class="p-4 pt-0 flex gap-3">
              <button @click="deleteStep = 0" class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
              <button @click="clearMonth" :disabled="deleting" class="flex-1 px-4 py-2.5 text-sm text-white bg-red-600 hover:bg-red-700 rounded-xl transition-all font-semibold shadow-lg shadow-red-500/25 disabled:opacity-50 flex items-center justify-center gap-2">
                <svg v-if="deleting" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Delete Everything
              </button>
            </div>
          </template>
        </div>
      </div>
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
      <div v-if="changeRequestModal.show" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm" @click.self="changeRequestModal.show = false">
        <div class="bg-slate-800/95 border border-white/10 rounded-2xl shadow-2xl w-full max-w-md mx-4">
          <div class="p-6 pb-3 border-b border-white/10">
            <h3 class="text-lg font-bold text-white flex items-center gap-2">
              🔄 Request Day Off
            </h3>
            <p class="text-slate-400 text-sm mt-1">
              Shift {{ changeRequestModal.shiftNumber }} on <strong class="text-white">{{ changeRequestModal.dateLabel }}</strong>
            </p>
          </div>
          <div class="p-6 pt-4">
            <label class="block text-sm font-medium text-slate-300 mb-2">Reason <span class="text-red-400">*</span></label>
            <textarea v-model="changeRequestModal.reason" rows="4" placeholder="Please describe why you can't work this shift..."
              class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-orange-500/50 placeholder-slate-500 resize-none"></textarea>
            <p v-if="changeRequestModal.error" class="text-red-400 text-xs mt-1">{{ changeRequestModal.error }}</p>
          </div>
          <div class="p-6 pt-0 flex gap-3">
            <button @click="changeRequestModal.show = false"
              class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
            <button @click="submitChangeRequest" :disabled="changeRequestModal.submitting || !changeRequestModal.reason.trim()"
              class="flex-1 px-4 py-2.5 text-sm text-white bg-orange-600 hover:bg-orange-700 rounded-xl transition-all font-semibold shadow-lg shadow-orange-500/25 disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
              <svg v-if="changeRequestModal.submitting" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
              Submit Request
            </button>
          </div>
        </div>
      </div>
    </Transition>

  </AppLayout>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { VueDraggable } from 'vue-draggable-plus';
import { useRoute, useRouter } from 'vue-router';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AppToast from '../components/AppToast.vue';
import AutoFillPanel from '../components/AutoFillPanel.vue';
import RestDayPolicyModal from '../components/RestDayPolicyModal.vue';
import ShiftTypeBulkEditor from '../components/ShiftTypeBulkEditor.vue';
import { confirm } from '../composables/useConfirm.js';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast.js';
import { localDateStr, todayStr } from '../dates.js';
import { putDayShiftCount } from '../dayShiftCount.js';
import { isAdmin, canManageSchedule as userCanManageSchedule } from '../roles.js';
import { currentUser } from '../router/index.js';

const router = useRouter();
const route = useRoute();

// State
const loading = ref(true);
const generating = ref(false);
const currentYear = ref(new Date().getFullYear());
const currentMonth = ref(new Date().getMonth()); // 0-indexed
const schedule = ref({});
const showShiftTypeEditor = ref(false);
const sideAccordion = ref({ employees: true, autoFill: false, overlays: false });
const hasScheduleShifts = computed(() =>
  Object.values(schedule.value).some((shifts) => shifts.length > 0),
);
const employees = ref([]);
const activeShiftId = ref(null);
const empSort = ref('name');
const deleteStep = ref(0); // 0=hidden, 1=first confirm, 2=final confirm
const deleting = ref(false);

// Shift groups
const shiftGroups = ref([]);
const activeGroupId = ref(null);
const showNewGroupInput = ref(false);
const newGroupName = ref('');
const newGroupColor = ref('#6366f1');
const editingGroupId = ref(null);
const editingGroupName = ref('');

const isAllGroupsMode = computed(() => activeGroupId.value === 'all');

// Group shifts by shift_group_id for All Groups mode rendering
function groupShiftsByGroup(shifts) {
  const map = {};
  for (const shift of shifts) {
    const gid = shift.shift_group_id || 'default';
    if (!map[gid]) {
      map[gid] = {
        groupId: gid,
        name: shift.shift_group_name || 'Unknown',
        color: shift.shift_group_color || null,
        shifts: [],
      };
    }
    map[gid].shifts.push(shift);
  }
  return Object.values(map);
}

async function fetchShiftGroups() {
  try {
    const res = await api.get('/api/shift-groups');
    shiftGroups.value = res.data;
    // Set active group to first (default) if not set
    if (!activeGroupId.value && shiftGroups.value.length > 0) {
      const defaultGroup = shiftGroups.value.find((g) => g.is_default);
      activeGroupId.value = defaultGroup?.id || shiftGroups.value[0].id;
    }
  } catch {
    shiftGroups.value = [];
  }
}

function switchGroup(groupId) {
  if (activeGroupId.value === groupId) return;
  activeGroupId.value = groupId;
  fetchSchedule();
}

async function createShiftGroup() {
  if (!newGroupName.value.trim()) return;
  try {
    const res = await api.post('/api/shift-groups', {
      name: newGroupName.value.trim(),
      color: newGroupColor.value,
    });
    shiftGroups.value.push(res.data);
    newGroupName.value = '';
    newGroupColor.value = '#6366f1';
    showNewGroupInput.value = false;
    // Switch to the new group
    activeGroupId.value = res.data.id;
    fetchSchedule();
    showToast(`Group "${res.data.name}" created.`);
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to create group.', 'error');
  }
}

async function deleteShiftGroup(group) {
  const ok = await confirm({
    title: 'Delete Shift Group',
    message: `Delete group "${group.name}"? This will remove all shifts and assignments in this group.`,
    confirmLabel: 'Delete',
    danger: true,
  });
  if (!ok) return;
  try {
    await api.delete(`/api/shift-groups/${group.id}`);
    shiftGroups.value = shiftGroups.value.filter((g) => g.id !== group.id);
    // Switch to default group
    const defaultGroup = shiftGroups.value.find((g) => g.is_default);
    activeGroupId.value = defaultGroup?.id || shiftGroups.value[0]?.id;
    fetchSchedule();
    showToast(`Group "${group.name}" deleted.`);
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to delete group.', 'error');
  }
}

function startGroupRename(group) {
  editingGroupId.value = group.id;
  editingGroupName.value = group.name;
}

async function saveGroupRename() {
  if (!editingGroupId.value) return;
  const name = editingGroupName.value.trim();
  if (!name) {
    editingGroupId.value = null;
    return;
  }
  try {
    await api.put(`/api/shift-groups/${editingGroupId.value}`, { name });
    const group = shiftGroups.value.find((g) => g.id === editingGroupId.value);
    if (group) group.name = name;
    editingGroupId.value = null;
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to rename group.', 'error');
    editingGroupId.value = null;
  }
}

const showGroupSettingsModal = ref(false);
const savingGroupSettings = ref(false);
const groupSettingsTarget = ref(null);
const groupSettingsForm = ref({
  settlement_mode: 'monthly',
  settlement_anchor_date: '',
  settlement_custom_length_days: null,
  weekly_hour_cap: 48,
  streak_rules: [],
});

function openGroupSettings(group) {
  groupSettingsTarget.value = group;
  groupSettingsForm.value = {
    settlement_mode: group.settlement_mode || 'monthly',
    settlement_anchor_date: group.settlement_anchor_date || '',
    settlement_custom_length_days: group.settlement_custom_length_days ?? null,
    weekly_hour_cap: group.weekly_hour_cap ?? 48,
    streak_rules: (group.streak_rules ?? []).map((r) => ({
      shift_type: r.shift_type,
      streak_length: r.streak_length,
      rest_days: r.rest_days,
    })),
  };
  showGroupSettingsModal.value = true;
}

async function saveGroupSettings() {
  if (!groupSettingsTarget.value) return;
  savingGroupSettings.value = true;
  try {
    const payload = {
      settlement_mode: groupSettingsForm.value.settlement_mode,
      settlement_anchor_date: groupSettingsForm.value.settlement_anchor_date,
      settlement_custom_length_days:
        groupSettingsForm.value.settlement_mode === 'custom'
          ? groupSettingsForm.value.settlement_custom_length_days
          : null,
      weekly_hour_cap: groupSettingsForm.value.weekly_hour_cap,
    };
    const res = await api.put(`/api/shift-groups/${groupSettingsTarget.value.id}`, payload);

    const streakRes = await api.put(
      `/api/shift-groups/${groupSettingsTarget.value.id}/streak-rules`,
      {
        rules: groupSettingsForm.value.streak_rules.filter((r) => r.shift_type),
      },
    );

    const idx = shiftGroups.value.findIndex((g) => g.id === res.data.id);
    if (idx !== -1)
      shiftGroups.value[idx] = { ...res.data, streak_rules: streakRes.data.streak_rules };
    showGroupSettingsModal.value = false;
    showToast('Settlement settings saved.');
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to save settlement settings.', 'error');
  } finally {
    savingGroupSettings.value = false;
  }
}

// Delegations overlay
const showDelegations = ref(false);
const monthDelegations = ref([]);

// Office closures overlay
const showClosures = ref(false);
const monthClosures = ref([]);

// Shift colors
const showShiftColors = ref(true);
const shiftColors = ref([]);
const shiftColorById = computed(() => {
  const map = {};
  for (const c of shiftColors.value) {
    map[String(c.id)] = c;
  }
  return map;
});

async function fetchShiftColors() {
  try {
    const res = await api.get('/api/shift-colors');
    shiftColors.value = res.data;
  } catch {
    shiftColors.value = [];
  }
}

async function clearMonth() {
  deleting.value = true;
  try {
    const res = await api.delete('/api/schedule/clear-month', {
      data: { month: monthKey.value, shift_group_id: activeGroupId.value },
    });
    showToast(res.data.message, 'success');
    deleteStep.value = 0;
    await fetchSchedule();
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to delete schedule.', 'error');
  } finally {
    deleting.value = false;
  }
}

// Fetch delegations for the schedule overlay (also read by the drop-time delegation-conflict check).
// It runs after every schedule load and when the overlay is switched on; only the latest call may
// apply, so a slow previous month's list can't replace the month on screen.
const delegationRequests = useLatestRequest();
// A failed load is not "nobody is on delegation": the drop-time check warns instead of passing.
const delegationsFailed = ref(false);

async function fetchDelegations() {
  if (!canManageSchedule.value) return; // manager-only data (overlay and drop checks)
  const token = delegationRequests.begin();
  try {
    const res = await api.get('/api/delegations/month', { params: { month: monthKey.value } });
    if (!delegationRequests.isCurrent(token)) return;
    monthDelegations.value = res.data;
    delegationsFailed.value = false;
  } catch {
    if (!delegationRequests.isCurrent(token)) return;
    monthDelegations.value = [];
    delegationsFailed.value = true;
    showToast("Couldn't load delegations: delegation conflicts can't be checked.", 'warning');
  }
}

function getDayDelegations(dateStr) {
  if (!dateStr) return [];
  return monthDelegations.value.filter((d) => {
    const start = d.start_datetime.substring(0, 10);
    const end = d.end_datetime.substring(0, 10);
    if (dateStr < start || dateStr > end) return false;
    // If an employee is selected, show only their delegations
    if (selectedEmployee.value) {
      return d.users.some((u) => u.id === selectedEmployee.value.id);
    }
    return true;
  });
}

watch(showDelegations, (val) => {
  if (val) fetchDelegations();
});

// Newbies overlay
const showNewbies = ref(false);
const monthNewbies = ref([]);

const newbieRequests = useLatestRequest();

async function fetchNewbies() {
  if (!canManageSchedule.value) return; // manager-only data (overlay and roster stars)
  const token = newbieRequests.begin();
  try {
    const res = await api.get('/api/newbie-periods/month', { params: { month: monthKey.value } });
    if (!newbieRequests.isCurrent(token)) return;
    monthNewbies.value = res.data;
  } catch {
    if (!newbieRequests.isCurrent(token)) return;
    monthNewbies.value = [];
    showToast("Couldn't load newbie periods.", 'warning');
  }
}

function getDayNewbies(dateStr) {
  if (!dateStr) return [];
  return monthNewbies.value.filter((np) => {
    const start = np.start_date?.substring(0, 10);
    const end = np.end_date?.substring(0, 10);
    if (dateStr < start || dateStr > end) return false;
    if (selectedEmployee.value) {
      return np.user_id === selectedEmployee.value.id;
    }
    return true;
  });
}

watch(showNewbies, (val) => {
  if (val) fetchNewbies();
});

// Office closures fetch + helpers
const closureRequests = useLatestRequest();

async function fetchClosures() {
  const token = closureRequests.begin();
  try {
    const res = await api.get('/api/office-closures', { params: { month: monthKey.value } });
    if (!closureRequests.isCurrent(token)) return;
    monthClosures.value = res.data;
  } catch {
    if (!closureRequests.isCurrent(token)) return;
    monthClosures.value = [];
    showToast("Couldn't load office closures.", 'warning');
  }
}

function getClosureForDate(dateStr) {
  if (!dateStr) return null;
  return monthClosures.value.find((c) => (c.date?.substring?.(0, 10) || c.date) === dateStr);
}

watch(showClosures, (val) => {
  if (val) fetchClosures();
});

// Newbie helpers
function isCurrentNewbie(userId) {
  const today = todayStr();
  return monthNewbies.value.some((np) => {
    const start = np.start_date?.substring(0, 10);
    const end = np.end_date?.substring(0, 10);
    return np.user_id === userId && today >= start && today <= end;
  });
}

// Employee availability selection
const selectedEmployee = ref(null);
const selectedAvailability = ref([]);

// Unavailable assignment confirmation
const availConfirm = ref({
  show: false,
  shift: null,
  item: null,
  itemIndex: null,
  userName: '',
  dateStr: '',
  entries: [],
});

// Adjacent shift confirmation
const adjacentConfirm = ref({
  show: false,
  shift: null,
  item: null,
  itemIndex: null,
  userName: '',
  dateStr: '',
  conflicts: [],
});

// Delegation conflict confirmation
const delegConfirm = ref({
  show: false,
  shift: null,
  item: null,
  itemIndex: null,
  userName: '',
  dateStr: '',
  delegations: [],
});

// Office closure conflict
const closureConflict = ref({ show: false, closures: [] });

// Generation config modal
const showGenerateModal = ref(false);
const genConfig = ref({
  shiftsPerDay: 3,
  shifts: [],
  workModeWeekday: 'office',
  workModeWeekend: 'office',
});

function buildDefaultShifts(count) {
  const presets = {
    1: [{ start_time: '00:00', end_time: '00:00', checklists: [] }],
    2: [
      { start_time: '06:00', end_time: '18:00', checklists: [] },
      { start_time: '18:00', end_time: '06:00', checklists: [] },
    ],
    3: [
      {
        start_time: '06:00',
        end_time: '14:00',
        checklists: [],
        color: null,
        color_label: '',
        shift_type: '',
      },
      {
        start_time: '14:00',
        end_time: '22:00',
        checklists: [],
        color: null,
        color_label: '',
        shift_type: '',
      },
      {
        start_time: '22:00',
        end_time: '06:00',
        checklists: [],
        color: null,
        color_label: '',
        shift_type: '',
      },
    ],
  };
  if (presets[count])
    return presets[count].map((p) => ({
      ...p,
      checklists: [],
      color: null,
      color_label: '',
      shift_type: '',
    }));
  const hoursPerShift = Math.floor(24 / count);
  const shifts = [];
  let startMin = 6 * 60;
  for (let i = 0; i < count; i++) {
    const endMin = startMin + hoursPerShift * 60;
    shifts.push({
      start_time: `${String(Math.floor(startMin / 60) % 24).padStart(2, '0')}:${String(startMin % 60).padStart(2, '0')}`,
      end_time: `${String(Math.floor(endMin / 60) % 24).padStart(2, '0')}:${String(endMin % 60).padStart(2, '0')}`,
      checklists: [],
      color: null,
      color_label: '',
      shift_type: '',
    });
    startMin = endMin;
  }
  return shifts;
}

function openGenerateModal() {
  genConfig.value.shiftsPerDay = 3;
  genConfig.value.shifts = buildDefaultShifts(3);
  showGenerateModal.value = true;
}

function onGenShiftCountChange() {
  const count = genConfig.value.shiftsPerDay;
  genConfig.value.shifts = buildDefaultShifts(count);
}

function syncNextShiftStart(currentIdx) {
  const shifts = genConfig.value.shifts;
  if (currentIdx < shifts.length - 1 && shifts[currentIdx].end_time) {
    shifts[currentIdx + 1].start_time = shifts[currentIdx].end_time;
  }
}

// Donut chart computed data
const defaultDonutColors = [
  '#6366f1',
  '#8b5cf6',
  '#ec4899',
  '#f43f5e',
  '#f97316',
  '#eab308',
  '#22c55e',
  '#06b6d4',
  '#3b82f6',
  '#14b8a6',
];

function timeToMinutes(t) {
  if (!t) return 0;
  const [h, m] = t.split(':').map(Number);
  return h * 60 + m;
}

function minutesToAngle(minutes) {
  return (minutes / (24 * 60)) * 360;
}

function polarToCart(cx, cy, r, angleDeg) {
  const rad = ((angleDeg - 90) * Math.PI) / 180;
  return { x: cx + r * Math.cos(rad), y: cy + r * Math.sin(rad) };
}

function describeArc(cx, cy, r, startAngle, endAngle) {
  if (endAngle - startAngle >= 360) endAngle = startAngle + 359.99;
  const start = polarToCart(cx, cy, r, startAngle);
  const end = polarToCart(cx, cy, r, endAngle);
  const largeArc = endAngle - startAngle > 180 ? 1 : 0;
  return `M ${start.x} ${start.y} A ${r} ${r} 0 ${largeArc} 1 ${end.x} ${end.y}`;
}

const donutSegments = computed(() => {
  return genConfig.value.shifts.map((shift, idx) => {
    const startMin = timeToMinutes(shift.start_time);
    let endMin = timeToMinutes(shift.end_time);
    if (endMin <= startMin) endMin += 24 * 60; // overnight
    const duration = endMin - startMin;
    const startAngle = minutesToAngle(startMin);
    const endAngle = minutesToAngle(endMin);

    // Determine color
    let color = defaultDonutColors[idx % defaultDonutColors.length];
    if (shift.color && shiftColorById.value[shift.color]) {
      color = shiftColorById.value[shift.color].hex;
    }

    return {
      path: describeArc(100, 100, 70, startAngle, endAngle),
      color,
      startTime: shift.start_time || '00:00',
      endTime: shift.end_time || '00:00',
      durationLabel: formatDonutDuration(duration),
      durationMinutes: duration,
    };
  });
});

const donutGapMinutes = computed(() => {
  const total = donutSegments.value.reduce((sum, s) => sum + s.durationMinutes, 0);
  return Math.max(0, 24 * 60 - total);
});

const donutOverlapMinutes = computed(() => {
  const total = donutSegments.value.reduce((sum, s) => sum + s.durationMinutes, 0);
  return Math.max(0, total - 24 * 60);
});

function formatDonutDuration(minutes) {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

// Checklist config
const checklistTemplates = ref([]);
const showChecklistModal = ref(false);
const checklistConfigShift = ref(null);
const checklistConfigDate = ref('');
const savingChecklist = ref(false);
const checklistForm = ref([]);

// Time editor
const showTimeModal = ref(false);
const timeConfigShift = ref(null);
const timeConfigDate = ref('');
const savingTimes = ref(false);
const timeForm = ref({
  start_time: '',
  end_time: '',
  color: null,
  color_label: '',
  shift_type: '',
});

// Toast
const { showToast } = useToast();

// Computed
const canManageSchedule = computed(() => userCanManageSchedule(currentUser.value));

const weekDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const monthKey = computed(() => {
  const m = (currentMonth.value + 1).toString().padStart(2, '0');
  return `${currentYear.value}-${m}`;
});

const monthLabel = computed(() => {
  const date = new Date(currentYear.value, currentMonth.value, 1);
  return date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
});

const employeePool = ref([]);

// Count how many shifts a user is assigned to this month
function getEmployeeShiftCount(userId) {
  let count = 0;
  for (const dateStr in schedule.value) {
    const dayShifts = schedule.value[dateStr];
    if (!dayShifts) continue;
    for (const shift of dayShifts) {
      if (shift.assignments && shift.assignments.some((a) => a.user_id === userId)) {
        count++;
      }
    }
  }
  return count;
}

// Calculate total hours for an employee across all assigned shifts this month
function getEmployeeTotalHours(userId) {
  let totalMinutes = 0;
  for (const dateStr in schedule.value) {
    const dayShifts = schedule.value[dateStr];
    if (!dayShifts) continue;
    for (const shift of dayShifts) {
      if (!shift.assignments || !shift.start_time || !shift.end_time) continue;
      if (!shift.assignments.some((a) => a.user_id === userId)) continue;
      const [sh, sm] = shift.start_time.split(':').map(Number);
      const [eh, em] = shift.end_time.split(':').map(Number);
      let dur = eh * 60 + em - (sh * 60 + sm);
      if (dur <= 0) dur += 24 * 60; // overnight shift
      totalMinutes += dur;
    }
  }
  return Math.round(totalMinutes / 60);
}

// Get overnight shifts from previous day that spill into this day
function getOvernightFromPrevDay(dateStr) {
  if (!dateStr) return [];
  const prevDate = new Date(dateStr + 'T12:00:00');
  prevDate.setDate(prevDate.getDate() - 1);
  const prevDateStr = localDateStr(prevDate);
  const prevDayShifts = schedule.value[prevDateStr] || [];
  return prevDayShifts.filter((s) => s.start_time && s.end_time && s.end_time < s.start_time);
}

// Sorted employee pool for display
const sortedEmployeePool = computed(() => {
  const pool = [...employeePool.value];
  if (empSort.value === 'name') {
    pool.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
  } else if (empSort.value === 'shifts-desc') {
    pool.sort((a, b) => getEmployeeShiftCount(b.id) - getEmployeeShiftCount(a.id));
  } else if (empSort.value === 'shifts-asc') {
    pool.sort((a, b) => getEmployeeShiftCount(a.id) - getEmployeeShiftCount(b.id));
  } else if (empSort.value === 'hours-desc') {
    pool.sort((a, b) => getEmployeeTotalHours(b.id) - getEmployeeTotalHours(a.id));
  } else if (empSort.value === 'hours-asc') {
    pool.sort((a, b) => getEmployeeTotalHours(a.id) - getEmployeeTotalHours(b.id));
  }
  return pool;
});

const calendarWeeks = computed(() => {
  const year = currentYear.value;
  const month = currentMonth.value;
  const firstDay = new Date(year, month, 1);
  const lastDay = new Date(year, month + 1, 0);
  const totalDays = lastDay.getDate();

  // Monday = 0, Sunday = 6 (ISO week)
  let startWeekday = firstDay.getDay() - 1;
  if (startWeekday < 0) startWeekday = 6;

  const today = todayStr();

  const weeks = [];
  let currentWeek = [];

  // Fill previous month days
  const prevMonthLast = new Date(year, month, 0);
  for (let i = startWeekday - 1; i >= 0; i--) {
    const d = prevMonthLast.getDate() - i;
    currentWeek.push({
      dayNumber: d,
      dateStr: null,
      isCurrentMonth: false,
      isToday: false,
      shifts: [],
    });
  }

  // Current month days
  for (let d = 1; d <= totalDays; d++) {
    const dateStr = `${year}-${(month + 1).toString().padStart(2, '0')}-${d.toString().padStart(2, '0')}`;
    currentWeek.push({
      dayNumber: d,
      dateStr,
      isCurrentMonth: true,
      isToday: dateStr === today,
      shifts: schedule.value[dateStr] || [],
    });
    if (currentWeek.length === 7) {
      weeks.push(currentWeek);
      currentWeek = [];
    }
  }

  // Fill next month days
  if (currentWeek.length > 0) {
    let nextD = 1;
    while (currentWeek.length < 7) {
      currentWeek.push({
        dayNumber: nextD++,
        dateStr: null,
        isCurrentMonth: false,
        isToday: false,
        shifts: [],
      });
    }
    weeks.push(currentWeek);
  }

  return weeks;
});

// Methods
function prevMonth() {
  if (currentMonth.value === 0) {
    currentMonth.value = 11;
    currentYear.value--;
  } else {
    currentMonth.value--;
  }
}

function nextMonth() {
  if (currentMonth.value === 11) {
    currentMonth.value = 0;
    currentYear.value++;
  } else {
    currentMonth.value++;
  }
}

async function onShiftTypesSaved({ updated, rebooked }) {
  showShiftTypeEditor.value = false;
  const counters =
    rebooked > 0
      ? `; streak counters adjusted for ${rebooked} assignment${rebooked === 1 ? '' : 's'}`
      : '';
  showToast(`${updated} shift${updated === 1 ? '' : 's'} updated${counters}.`);
  await fetchSchedule();
}

// Several fetches can overlap (e.g. a deep link fires one before the groups have loaded, then the
// user switches tabs). Only the latest one may apply its response — otherwise a slower, older
// response (which also reports a shift_group_id) snaps the view back to the wrong group.
const scheduleRequests = useLatestRequest();

// quiet: refresh in place (no spinner, the grid keeps its scroll position).
async function fetchSchedule({ quiet = false } = {}) {
  const token = scheduleRequests.begin();
  restPrompt.value = null; // its shift/chip objects are about to be replaced
  if (!quiet) loading.value = true;
  try {
    const params = { month: monthKey.value };
    if (activeGroupId.value) params.shift_group_id = activeGroupId.value;
    const response = await api.get('/api/schedule', { params });
    if (!scheduleRequests.isCurrent(token)) return;
    schedule.value = response.data.schedule;
    employees.value = response.data.employees;
    employeePool.value = response.data.employees.map((e) => ({
      ...e,
      user_id: e.id,
      user_name: e.name,
    }));
    // Update activeGroupId from server response if available (but not when in all-groups mode)
    if (response.data.shift_group_id && !isAllGroupsMode.value)
      activeGroupId.value = response.data.shift_group_id;
    fetchDelegations(); // Always fetch for conflict checks
    fetchNewbies(); // Always fetch for newbie star display
    fetchShiftColors(); // Load global color definitions
    if (showClosures.value) fetchClosures(); // Keep the closure overlay on the displayed month
  } catch (err) {
    if (scheduleRequests.isCurrent(token)) showToast('Failed to load schedule.', 'error');
  } finally {
    if (scheduleRequests.isCurrent(token)) loading.value = false;
  }
}

async function fetchTemplates() {
  if (!canManageSchedule.value) return; // only the checklist pickers use them
  try {
    const response = await api.get('/api/checklist-templates');
    checklistTemplates.value = response.data;
  } catch {
    // silently fail
  }
}

function openChecklistConfig(shift, dateStr) {
  checklistConfigShift.value = shift;
  checklistConfigDate.value = dateStr;
  checklistForm.value = (shift.shift_checklists || []).map((sc) => ({
    checklist_template_id: sc.checklist_template_id,
    is_required: sc.is_required || false,
    deadline_minutes: sc.deadline_minutes || null,
    deadline_reference: sc.deadline_reference || 'shift_start',
  }));
  showChecklistModal.value = true;
}

async function saveChecklistConfig() {
  const shift = checklistConfigShift.value;
  if (!shift) return;

  // Filter out items without a template selected
  const checklists = checklistForm.value.filter((i) => i.checklist_template_id);

  savingChecklist.value = true;
  try {
    const response = await api.put(`/api/schedule/shifts/${shift.id}/checklist`, {
      checklists,
    });
    // Update local state
    shift.shift_checklists = response.data.shift_checklists;
    showChecklistModal.value = false;
    showToast('Checklist settings updated.');
  } catch (err) {
    const msg = err.response?.data?.message || 'Failed to update checklist settings.';
    showToast(msg, 'error');
  } finally {
    savingChecklist.value = false;
  }
}

function isOvernightShift(shift) {
  return shift.start_time && shift.end_time && shift.end_time < shift.start_time;
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

function openTimeEditor(shift, dateStr) {
  timeConfigShift.value = shift;
  timeConfigDate.value = dateStr;
  timeForm.value = {
    start_time: shift.start_time || '06:00',
    end_time: shift.end_time || '14:00',
    color: shift.color || null,
    color_label: shift.color_label || '',
    shift_type: shift.shift_type || '',
  };
  showTimeModal.value = true;
}

async function saveShiftTimes() {
  const shift = timeConfigShift.value;
  if (!shift) return;

  savingTimes.value = true;
  try {
    const response = await api.put(`/api/schedule/shifts/${shift.id}/times`, {
      start_time: timeForm.value.start_time,
      end_time: timeForm.value.end_time,
    });
    shift.start_time = response.data.start_time;
    shift.end_time = response.data.end_time;

    // Save color if changed
    const colorRes = await api.put(`/api/schedule/shifts/${shift.id}/color`, {
      color: timeForm.value.color,
      color_label: timeForm.value.color_label || null,
    });
    shift.color = colorRes.data.color;
    shift.color_label = colorRes.data.color_label;

    // Only call the type endpoint when the (normalised) value changed: it re-books streak counters.
    const newType = timeForm.value.shift_type.trim().toLowerCase() || null;
    let rebooked = 0;
    if (newType !== (shift.shift_type ?? null)) {
      const typeRes = await api.put(`/api/schedule/shifts/${shift.id}/type`, {
        shift_type: newType,
      });
      shift.shift_type = typeRes.data.shift_type;
      rebooked = typeRes.data.rebooked;
    }

    showTimeModal.value = false;
    showToast(
      rebooked > 0
        ? `Shift updated; streak counters adjusted for ${rebooked} employee${rebooked === 1 ? '' : 's'}.`
        : 'Shift updated.',
    );
  } catch (err) {
    const msg = err.response?.data?.message || 'Failed to update shift.';
    showToast(msg, 'error');
  } finally {
    savingTimes.value = false;
  }
}

async function generateMonth() {
  // First check for office closure conflicts
  const hasOfficeWeekday = genConfig.value.workModeWeekday === 'office';
  const hasOfficeWeekend = genConfig.value.workModeWeekend === 'office';

  if (hasOfficeWeekday || hasOfficeWeekend) {
    try {
      const res = await api.get('/api/office-closures/conflicts', {
        params: { month: monthKey.value },
      });
      const allClosures = res.data || [];
      // Filter: which closed dates conflict with office-mode days?
      const conflicts = allClosures.filter((c) => {
        const d = new Date((c.date?.substring?.(0, 10) || c.date) + 'T12:00:00');
        const dow = d.getDay(); // 0=Sun, 6=Sat
        const isWeekend = dow === 0 || dow === 6;
        return isWeekend ? hasOfficeWeekend : hasOfficeWeekday;
      });
      if (conflicts.length > 0) {
        closureConflict.value = { show: true, closures: conflicts };
        showGenerateModal.value = false;
        return; // Wait for confirmation
      }
    } catch {
      // Not "no closures": say the check didn't run and let the manager decide.
      showGenerateModal.value = false;
      const ok = await confirm({
        title: "Couldn't check office closures",
        message:
          'The closed dates for this month could not be loaded, so office shifts may land on days the office is closed. Generate anyway?',
        confirmLabel: 'Generate anyway',
      });
      if (!ok) return;
    }
  }

  await doGenerateMonth();
}

function cancelClosureConflict() {
  closureConflict.value = { show: false, closures: [] };
}

async function proceedGenerate() {
  closureConflict.value = { show: false, closures: [] };
  await doGenerateMonth();
}

async function proceedGenerateAsRemote() {
  const closedDates = closureConflict.value.closures.map(
    (c) => c.date?.substring?.(0, 10) || c.date,
  );
  closureConflict.value = { show: false, closures: [] };
  await doGenerateMonth();
  // Now switch all shifts on closed dates to remote
  let switched = 0;
  for (const dateStr in schedule.value) {
    if (closedDates.includes(dateStr)) {
      for (const shift of schedule.value[dateStr]) {
        if (shift.work_mode === 'office') {
          try {
            await api.put(`/api/schedule/shifts/${shift.id}/work-mode`, { work_mode: 'remote' });
            shift.work_mode = 'remote';
            switched++;
          } catch {
            /* skip */
          }
        }
      }
    }
  }
  if (switched > 0)
    showToast(`Switched ${switched} shift${switched > 1 ? 's' : ''} to remote on closed dates.`);
}

function formatClosureDate(dateStr) {
  const d = new Date((dateStr?.substring?.(0, 10) || dateStr) + 'T12:00:00');
  return d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}

async function doGenerateMonth() {
  generating.value = true;
  showGenerateModal.value = false;
  try {
    const shiftTimes = genConfig.value.shifts.map((s) => ({
      start_time: s.start_time,
      end_time: s.end_time,
      color: s.color || null,
      color_label: s.color_label || null,
      shift_type: s.shift_type?.trim() || null,
      checklists: (s.checklists || [])
        .filter((c) => c.checklist_template_id)
        .map((c) => ({
          checklist_template_id: c.checklist_template_id,
          is_required: c.is_required || false,
          deadline_minutes: c.deadline_minutes || null,
          deadline_reference: c.deadline_reference || 'shift_start',
        })),
    }));
    const response = await api.post('/api/schedule/generate', {
      month: monthKey.value,
      shift_group_id: activeGroupId.value,
      shifts_per_day: genConfig.value.shiftsPerDay,
      shift_times: shiftTimes,
      work_mode_weekday: genConfig.value.workModeWeekday,
      work_mode_weekend: genConfig.value.workModeWeekend,
    });
    showToast(response.data.message);
    await fetchSchedule();
  } catch (err) {
    showToast('Failed to generate month.', 'error');
  } finally {
    generating.value = false;
  }
}

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

function cloneEmployee(original) {
  return {
    user_id: original.id || original.user_id,
    user_name: original.name || original.user_name,
    id: null, // assignment id, to be filled by server
  };
}

const wasDragging = ref(false);

function onDragStart(evt) {
  wasDragging.value = true;
  // Auto-highlight availability for the employee being dragged
  const emp = sortedEmployeePool.value[evt.oldIndex];
  if (emp && (!selectedEmployee.value || selectedEmployee.value.id !== emp.id)) {
    selectedEmployee.value = emp;
    fetchAvailability(emp.id);
  }
}

function onDragEnd() {
  // Clear the flag after a small delay so the click event (which fires after mouseup) sees it
  setTimeout(() => {
    wasDragging.value = false;
  }, 50);
}

function handleEmployeeClick(emp) {
  if (wasDragging.value) return; // Skip click after drag
  toggleEmployeeSelect(emp);
}

// Find adjacent shift conflicts for a user

function getAdjacentConflicts(userId, targetShift, dateStr) {
  const conflicts = [];
  const dayShifts = schedule.value[dateStr] || [];
  const shiftIdx = dayShifts.findIndex((s) => s.id === targetShift.id);

  // Check previous shift on same day
  if (shiftIdx > 0) {
    const prevShift = dayShifts[shiftIdx - 1];
    const isAssigned = prevShift.assignments.some((a) => a.user_id === userId);
    if (isAssigned) {
      conflicts.push({
        date: dateStr,
        shiftNumber: prevShift.shift_number,
        startTime: prevShift.start_time ? prevShift.start_time.substring(0, 5) : null,
        endTime: prevShift.end_time ? prevShift.end_time.substring(0, 5) : null,
        position: isOvernightShift(prevShift)
          ? 'overnight shift before (spills into next day)'
          : 'shift before',
      });
    }
  }

  // Check next shift on same day
  if (shiftIdx >= 0 && shiftIdx < dayShifts.length - 1) {
    const nextShift = dayShifts[shiftIdx + 1];
    const isAssigned = nextShift.assignments.some((a) => a.user_id === userId);
    if (isAssigned) {
      conflicts.push({
        date: dateStr,
        shiftNumber: nextShift.shift_number,
        startTime: nextShift.start_time ? nextShift.start_time.substring(0, 5) : null,
        endTime: nextShift.end_time ? nextShift.end_time.substring(0, 5) : null,
        position: 'shift after',
      });
    }
  }

  // If first shift of the day, check previous day's last shift
  if (shiftIdx === 0) {
    const prevDate = new Date(dateStr + 'T12:00:00');
    prevDate.setDate(prevDate.getDate() - 1);
    const prevDateStr = localDateStr(prevDate);
    const prevDayShifts = schedule.value[prevDateStr] || [];
    if (prevDayShifts.length > 0) {
      const lastShift = prevDayShifts[prevDayShifts.length - 1];
      const isAssigned = lastShift.assignments.some((a) => a.user_id === userId);
      if (isAssigned) {
        conflicts.push({
          date: prevDateStr,
          shiftNumber: lastShift.shift_number,
          startTime: lastShift.start_time ? lastShift.start_time.substring(0, 5) : null,
          endTime: lastShift.end_time ? lastShift.end_time.substring(0, 5) : null,
          position: isOvernightShift(lastShift)
            ? 'overnight shift from previous day (ends ' + lastShift.end_time?.substring(0, 5) + ')'
            : 'last shift previous day',
        });
      }
    }
  }

  // If target shift is overnight, also check next day's FIRST shift
  // (because an overnight shift from today reaches into tomorrow)
  if (isOvernightShift(targetShift)) {
    const nextDate = new Date(dateStr + 'T12:00:00');
    nextDate.setDate(nextDate.getDate() + 1);
    const nextDateStr = localDateStr(nextDate);
    const nextDayShifts = schedule.value[nextDateStr] || [];
    if (nextDayShifts.length > 0) {
      const firstShift = nextDayShifts[0];
      const isAssigned = firstShift.assignments.some((a) => a.user_id === userId);
      if (isAssigned) {
        conflicts.push({
          date: nextDateStr,
          shiftNumber: firstShift.shift_number,
          startTime: firstShift.start_time ? firstShift.start_time.substring(0, 5) : null,
          endTime: firstShift.end_time ? firstShift.end_time.substring(0, 5) : null,
          position: 'first shift next day (your shift extends overnight)',
        });
      }
    }
  }

  // If last shift of the day (and NOT overnight), check next day's first shift
  if (shiftIdx === dayShifts.length - 1 && !isOvernightShift(targetShift)) {
    const nextDate = new Date(dateStr + 'T12:00:00');
    nextDate.setDate(nextDate.getDate() + 1);
    const nextDateStr = localDateStr(nextDate);
    const nextDayShifts = schedule.value[nextDateStr] || [];
    if (nextDayShifts.length > 0) {
      const firstShift = nextDayShifts[0];
      const isAssigned = firstShift.assignments.some((a) => a.user_id === userId);
      if (isAssigned) {
        conflicts.push({
          date: nextDateStr,
          shiftNumber: firstShift.shift_number,
          startTime: firstShift.start_time ? firstShift.start_time.substring(0, 5) : null,
          endTime: firstShift.end_time ? firstShift.end_time.substring(0, 5) : null,
          position: 'first shift next day',
        });
      }
    }
  }

  return conflicts;
}

// Proceed to adjacent check (called after availability is cleared)
async function proceedWithAdjacentCheck(shift, item, index, dateStr) {
  const adjConflicts = getAdjacentConflicts(item.user_id, shift, dateStr);
  if (adjConflicts.length > 0) {
    adjacentConfirm.value = {
      show: true,
      shift,
      item,
      itemIndex: index,
      userName: item.user_name || item.name || 'User',
      dateStr,
      conflicts: adjConflicts,
    };
    return; // Wait for user confirmation
  }
  await doAssign(shift, item, index);
}

// A chip dragged from another shift is a move: the server removes the old assignment and creates the
// new one in one transaction (move_from_assignment_id), so a cancelled or refused move leaves the
// employee where they were. Keyed by the dropped chip; read by every assign attempt for that drop.
// Taken from the drop event itself rather than the source list's `remove` event, which Sortable
// fires after `add`.
const pendingMoves = new WeakMap();

async function onDrop(evt, shift, dateStr) {
  const item = shift.assignments[evt.newIndex];
  if (!item || !item.user_id) return;
  // Roster cards are clones without an id; a chip from another shift still carries its assignment id.
  if (evt.from !== evt.to && evt.from?.dataset?.shiftId && item.id) {
    pendingMoves.set(item, item.id);
  }

  // Check if user has unavailable entries for this date
  // For overnight shifts, also check the next day
  const userId = item.user_id;
  const datesToCheck = [dateStr];
  if (isOvernightShift(shift)) {
    const nextDate = new Date(dateStr + 'T12:00:00');
    nextDate.setDate(nextDate.getDate() + 1);
    datesToCheck.push(localDateStr(nextDate));
  }

  try {
    const res = await api.get(`/api/user-availabilities/${userId}/month`, {
      params: { month: monthKey.value },
    });
    const unavailEntries = (res.data || []).filter(
      (e) =>
        e.type === 'unavailable' && datesToCheck.some((d) => d >= e.start_date && d <= e.end_date),
    );

    if (unavailEntries.length > 0) {
      // Show unavailability confirmation modal
      availConfirm.value = {
        show: true,
        shift,
        item,
        itemIndex: evt.newIndex,
        userName: item.user_name || item.name || 'User',
        dateStr,
        entries: unavailEntries,
      };
      return; // Wait for user confirmation
    }
  } catch {
    // Don't block the drop, but don't pretend the check passed either.
    showToast("Couldn't check this employee's availability.", 'warning');
  }

  // No unavailability conflict — check delegation conflict
  await proceedWithDelegationCheck(shift, item, evt.newIndex, dateStr);
}

// Takes a dropped chip back off its target. For a move, the source chip (already gone from its list
// on screen) comes back with a quiet reload; the server never removed it.
function abandonDrop(shift, item) {
  const i = shift.assignments.indexOf(item);
  if (i !== -1) shift.assignments.splice(i, 1);
  if (pendingMoves.delete(item)) fetchSchedule({ quiet: true });
}

// Resolves to { status: 'assigned' | 'prompted' (waiting on the rest-day dialog) | 'failed', updatedCount }.
// laterRestPolicy goes with the ids of the later shifts the manager was shown; if the server's list
// differs by now, it asks again.
async function doAssign(
  shift,
  item,
  index,
  restPolicy = null,
  laterRestPolicy = null,
  expectedAffectedIds = null,
) {
  const moveFrom = pendingMoves.get(item);
  try {
    const response = await api.post('/api/schedule/assign', {
      shift_id: shift.id,
      user_id: item.user_id,
      ...(moveFrom ? { move_from_assignment_id: moveFrom } : {}),
      ...(restPolicy ? { rest_policy: restPolicy } : {}),
      ...(laterRestPolicy
        ? { later_rest_policy: laterRestPolicy, expected_affected_ids: expectedAffectedIds || [] }
        : {}),
    });
    pendingMoves.delete(item);
    item.id = response.data.id;
    item.user_name = response.data.user_name;
    item.rest_policy = response.data.rest_policy;
    const updatedIds = response.data.updated_assignment_ids || [];
    // Any assignment can start, move or end a rest window, so other chips' ⚠ / ⏸ / resting-until
    // can change: reload them from the server.
    await fetchSchedule({ quiet: true });
    return { status: 'assigned', updatedCount: updatedIds.length };
  } catch (err) {
    // A rest day needs the manager's decision; keep the chip while asking.
    const code = err.response?.data?.code;
    if (
      err.response?.status === 409 &&
      (code === 'rest_window_conflict' || code === 'creates_rest_conflict')
    ) {
      restPrompt.value = { shift, item, index, conflict: err.response.data, restPolicy };
      return { status: 'prompted', updatedCount: 0 };
    }
    abandonDrop(shift, item);
    const msg = err.response?.data?.message || 'Failed to assign user.';
    showToast(msg, 'error');
    return { status: 'failed', updatedCount: 0 };
  }
}

const restPrompt = ref(null); // { shift, item, index, conflict, restPolicy }

async function chooseRestPolicy(policy) {
  const { shift, item, index, conflict, restPolicy } = restPrompt.value;
  restPrompt.value = null;
  // A later-shift question carries the answer to the dropped day's own question, if it was asked.
  const { status, updatedCount } =
    conflict.code === 'creates_rest_conflict'
      ? await doAssign(
          shift,
          item,
          index,
          restPolicy,
          policy,
          (conflict.affected || []).map((a) => a.assignment_id),
        )
      : await doAssign(shift, item, index, policy);
  if (status === 'assigned') {
    showToast(
      updatedCount === 0
        ? conflict.code === 'rest_window_conflict' || restPolicy
          ? 'Assigned during rest.'
          : 'Assigned.'
        : updatedCount === 1
          ? 'Assigned; later shift updated.'
          : 'Assigned; later shifts updated.',
      'success',
    );
  }
}

function cancelRestPolicy() {
  const { shift, item } = restPrompt.value;
  restPrompt.value = null;
  abandonDrop(shift, item);
}

function cancelAvailConfirm() {
  const { shift, item } = availConfirm.value;
  if (shift && item) abandonDrop(shift, item);
  availConfirm.value = {
    show: false,
    shift: null,
    item: null,
    itemIndex: null,
    userName: '',
    dateStr: '',
    entries: [],
  };
}

async function confirmAvailAssign() {
  const { shift, item, itemIndex, dateStr } = availConfirm.value;
  availConfirm.value.show = false;
  if (shift && item) {
    // After confirming unavailability, check delegation conflict
    await proceedWithDelegationCheck(shift, item, itemIndex, dateStr);
  }
  availConfirm.value = {
    show: false,
    shift: null,
    item: null,
    itemIndex: null,
    userName: '',
    dateStr: '',
    entries: [],
  };
}

async function proceedWithDelegationCheck(shift, item, itemIndex, dateStr) {
  if (delegationsFailed.value) {
    showToast("Couldn't check delegations: the month's delegations failed to load.", 'warning');
  }
  // Check delegation conflict
  try {
    const matchingDelegations = monthDelegations.value.filter((d) => {
      const start = d.start_datetime.substring(0, 10);
      const end = d.end_datetime.substring(0, 10);
      if (dateStr < start || dateStr > end) return false;
      return d.users.some((u) => u.id === item.user_id);
    });
    if (matchingDelegations.length > 0) {
      delegConfirm.value = {
        show: true,
        shift,
        item,
        itemIndex,
        userName: item.user_name || item.name || 'User',
        dateStr,
        delegations: matchingDelegations,
      };
      return;
    }
  } catch {
    /* proceed */
  }

  await proceedWithAdjacentCheck(shift, item, itemIndex, dateStr);
}

function cancelDelegConfirm() {
  const { shift, item } = delegConfirm.value;
  if (shift && item) abandonDrop(shift, item);
  delegConfirm.value = {
    show: false,
    shift: null,
    item: null,
    itemIndex: null,
    userName: '',
    dateStr: '',
    delegations: [],
  };
}

async function confirmDelegAssign() {
  const { shift, item, itemIndex, dateStr } = delegConfirm.value;
  delegConfirm.value.show = false;
  if (shift && item) {
    await proceedWithAdjacentCheck(shift, item, itemIndex, dateStr);
  }
  delegConfirm.value = {
    show: false,
    shift: null,
    item: null,
    itemIndex: null,
    userName: '',
    dateStr: '',
    delegations: [],
  };
}

function cancelAdjacentConfirm() {
  const { shift, item } = adjacentConfirm.value;
  if (shift && item) abandonDrop(shift, item);
  adjacentConfirm.value = {
    show: false,
    shift: null,
    item: null,
    itemIndex: null,
    userName: '',
    dateStr: '',
    conflicts: [],
  };
}

async function confirmAdjacentAssign() {
  const { shift, item, itemIndex } = adjacentConfirm.value;
  adjacentConfirm.value.show = false;
  if (shift && item) {
    const { status } = await doAssign(shift, item, itemIndex);
    if (status === 'assigned') showToast('Assigned to back-to-back shift.', 'success');
  }
  adjacentConfirm.value = {
    show: false,
    shift: null,
    item: null,
    itemIndex: null,
    userName: '',
    dateStr: '',
    conflicts: [],
  };
}

async function removeAssignment(assignment, shift) {
  if (!assignment.id) {
    // Not yet saved, just remove from UI
    const idx = shift.assignments.findIndex((a) => a === assignment);
    if (idx !== -1) shift.assignments.splice(idx, 1);
    return;
  }
  try {
    await api.delete(`/api/schedule/unassign/${assignment.id}`);
    const idx = shift.assignments.findIndex((a) => a.id === assignment.id);
    if (idx !== -1) shift.assignments.splice(idx, 1);
  } catch {
    showToast('Failed to remove assignment.', 'error');
    return;
  }
  // Removing a shift can end or move a rest window: refresh the other chips' ⚠ / ⏸.
  await fetchSchedule({ quiet: true });
}

function getInitials(name) {
  if (!name) return '';
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
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

  await fetchSchedule();
  fetchTemplates();
  try {
    const res = await api.get('/api/current-shift');
    if (res.data.active_shift) activeShiftId.value = res.data.active_shift.id;
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
  if (highlightUserId || scrollDate) {
    router.replace({ name: 'schedule', params: { month: monthKey.value } });
  } else if (!route.params.month) {
    router.replace({ name: 'schedule', params: { month: monthKey.value } });
  }
});

// Availability highlighting. Clicking employees (or changing month) faster than their availability
// loads overlaps requests; only the latest may paint the calendar. Deselecting drops the in-flight
// one so it can't repopulate the highlight for whoever is selected next.
const availabilityRequests = useLatestRequest();

function toggleEmployeeSelect(emp) {
  if (selectedEmployee.value?.id === emp.id) {
    deselectEmployee();
  } else {
    selectedEmployee.value = emp;
    fetchAvailability(emp.id);
  }
}

function deselectEmployee() {
  availabilityRequests.invalidate();
  selectedEmployee.value = null;
  selectedAvailability.value = [];
}

async function fetchAvailability(userId) {
  const token = availabilityRequests.begin();
  try {
    const res = await api.get(`/api/user-availabilities/${userId}/month`, {
      params: { month: monthKey.value },
    });
    if (!availabilityRequests.isCurrent(token)) return;
    selectedAvailability.value = res.data;
  } catch {
    if (availabilityRequests.isCurrent(token)) selectedAvailability.value = [];
  }
}

function getDayAvailStyle(dateStr) {
  if (!selectedEmployee.value || !dateStr) return {};
  const entries = selectedAvailability.value.filter(
    (e) => dateStr >= e.start_date && dateStr <= e.end_date,
  );
  if (entries.length === 0) return {};

  const hasUnavailableFullDay = entries.some((e) => e.type === 'unavailable' && !e.start_time);
  const hasUnavailableHours = entries.some((e) => e.type === 'unavailable' && e.start_time);
  const hasPreferredFullDay = entries.some((e) => e.type === 'preferred' && !e.start_time);
  const hasPreferredHours = entries.some((e) => e.type === 'preferred' && e.start_time);

  if (hasUnavailableFullDay)
    return {
      backgroundColor: 'rgba(239, 68, 68, 0.18)',
      boxShadow: 'inset 0 0 0 2px rgba(239, 68, 68, 0.3)',
    };
  if (hasPreferredFullDay)
    return {
      backgroundColor: 'rgba(16, 185, 129, 0.18)',
      boxShadow: 'inset 0 0 0 2px rgba(16, 185, 129, 0.3)',
    };
  if (hasUnavailableHours && hasPreferredHours)
    return {
      backgroundColor: 'rgba(245, 158, 11, 0.12)',
      boxShadow: 'inset 0 0 0 1px rgba(245, 158, 11, 0.25)',
    };
  if (hasUnavailableHours)
    return {
      backgroundColor: 'rgba(239, 68, 68, 0.1)',
      boxShadow: 'inset 0 0 0 1px rgba(239, 68, 68, 0.2)',
    };
  if (hasPreferredHours)
    return {
      backgroundColor: 'rgba(16, 185, 129, 0.1)',
      boxShadow: 'inset 0 0 0 1px rgba(16, 185, 129, 0.2)',
    };
  return {};
}

// Check if the selected employee is assigned to a specific shift
function isSelectedEmployeeAssigned(shift) {
  if (!selectedEmployee.value || !shift.assignments) return false;
  return shift.assignments.some((a) => a.user_id === selectedEmployee.value.id);
}

// Get highlight style for shifts the selected employee is assigned to
function getShiftAssignedStyle(shift) {
  if (!isSelectedEmployeeAssigned(shift)) return {};
  return {
    backgroundColor: 'rgba(14, 165, 233, 0.12)',
    boxShadow: 'inset 0 0 0 1px rgba(14, 165, 233, 0.25)',
  };
}

function getShiftCellStyle(shift) {
  const base = getShiftAssignedStyle(shift);
  if (showShiftColors.value && shift.color && shiftColorById.value[shift.color]) {
    const hex = shiftColorById.value[shift.color].hex;
    // Convert hex to rgba at 15% opacity for a subtle background
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return {
      ...base,
      backgroundColor: `rgba(${r}, ${g}, ${b}, 0.15)`,
      boxShadow: base.boxShadow || `inset 0 0 0 1px rgba(${r}, ${g}, ${b}, 0.25)`,
    };
  }
  return base;
}

// Shift change requests
const changeRequestModal = ref({
  show: false,
  assignmentId: null,
  shiftNumber: null,
  dateLabel: '',
  reason: '',
  error: '',
  submitting: false,
});

function isShiftFuture(shift, dateStr) {
  if (!dateStr) return false;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const shiftDate = new Date(dateStr + 'T00:00:00');
  return shiftDate >= today;
}

function openChangeRequestModal(assignment, shift, dateStr) {
  const d = new Date(dateStr + 'T12:00:00');
  changeRequestModal.value = {
    show: true,
    assignmentId: assignment.id,
    shiftNumber: shift.shift_number,
    dateLabel: d.toLocaleDateString('en-US', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    }),
    reason: '',
    error: '',
    submitting: false,
  };
}

async function submitChangeRequest() {
  const modal = changeRequestModal.value;
  if (!modal.reason.trim()) {
    modal.error = 'Please provide a reason.';
    return;
  }
  modal.submitting = true;
  modal.error = '';
  try {
    const response = await api.post('/api/shift-change-requests', {
      shift_assignment_id: modal.assignmentId,
      reason: modal.reason.trim(),
    });
    modal.show = false;
    showToast('Day-off request submitted successfully.', 'success');
    // Update locally instead of refreshing entire schedule
    for (const dateStr in schedule.value) {
      for (const shift of schedule.value[dateStr]) {
        const a = shift.assignments.find((a) => a.id === modal.assignmentId);
        if (a) {
          a.has_change_request = true;
          a.change_request_reason = modal.reason.trim();
          a.change_request_id = response.data?.id || null;
          break;
        }
      }
    }
  } catch (err) {
    modal.error = err.response?.data?.message || 'Failed to submit request.';
  } finally {
    modal.submitting = false;
  }
}
</script>

