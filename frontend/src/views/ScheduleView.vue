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

            <!-- Archive group button (admin only, non-default) -->
            <button v-if="isAdmin(currentUser) && !group.is_default && activeGroupId === group.id"
              @click.stop="archiveShiftGroup(group)"
              class="w-4 h-4 flex items-center justify-center text-slate-500 hover:text-amber-400 transition-colors ml-1"
              title="Archive group">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
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

          <!-- Archived groups (admin only) -->
          <button v-if="isAdmin(currentUser) && archivedGroups.length > 0" @click="showArchivedGroups = !showArchivedGroups"
            :aria-expanded="showArchivedGroups ? 'true' : 'false'"
            class="flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-xl border border-white/10 text-slate-500 hover:text-slate-300 hover:bg-white/5 transition-all">
            Archived ({{ archivedGroups.length }})
          </button>
        </div>

        <!-- Archived groups list: their history stays; restoring brings the tab back -->
        <div v-if="showArchivedGroups && isAdmin(currentUser) && archivedGroups.length > 0"
          class="mt-3 p-3 bg-white/[0.03] border border-white/10 rounded-xl">
          <p class="text-xs text-slate-500 mb-2">Archived groups keep their past shifts, hours and on-call duties. Restore one to plan in it again.</p>
          <ul class="flex flex-col gap-1.5" aria-label="Archived shift groups">
            <li v-for="group in archivedGroups" :key="group.id" class="flex items-center gap-2 text-sm text-slate-300">
              <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ backgroundColor: group.color || '#64748b' }"></span>
              <span>{{ group.name }}</span>
              <button @click="restoreShiftGroup(group)" :disabled="restoringGroupId === group.id"
                class="ml-auto px-2.5 py-1 text-xs font-medium text-indigo-300 hover:text-white bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/30 rounded-lg transition-all disabled:opacity-40">
                Restore {{ group.name }}
              </button>
            </li>
          </ul>
        </div>

        <!-- New group inline form -->
        <Transition enter-active-class="transition-all duration-200 ease-out" enter-from-class="opacity-0 -translate-y-2" enter-to-class="opacity-100 translate-y-0"
          leave-active-class="transition-all duration-150 ease-in" leave-from-class="opacity-100" leave-to-class="opacity-0">
          <div v-if="showNewGroupInput && isAdmin(currentUser)" class="mt-3 flex items-center gap-2">
            <input v-model="newGroupColor" type="color" class="w-8 h-8 rounded-lg border border-white/10 cursor-pointer bg-transparent shrink-0" />
            <input v-model="newGroupName" type="text" placeholder="Group name (e.g. Event Coverage)"
              class="flex-1 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 placeholder-slate-500"
              @keyup.enter="createShiftGroup" />
            <button @click="createShiftGroup" :disabled="creatingGroup || !newGroupName.trim()"
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
import AutoFillPanel from '../components/AutoFillPanel.vue';
import RestDayPolicyModal from '../components/RestDayPolicyModal.vue';
import ShiftTypeBulkEditor from '../components/ShiftTypeBulkEditor.vue';
import AdjacentShiftConfirmModal from '../components/schedule/AdjacentShiftConfirmModal.vue';
import ChangeRequestModal from '../components/schedule/ChangeRequestModal.vue';
import ClosureConflictModal from '../components/schedule/ClosureConflictModal.vue';
import DelegationConfirmModal from '../components/schedule/DelegationConfirmModal.vue';
import DeleteScheduleModal from '../components/schedule/DeleteScheduleModal.vue';
import GenerateMonthModal from '../components/schedule/GenerateMonthModal.vue';
import GroupSettingsModal from '../components/schedule/GroupSettingsModal.vue';
import ShiftChecklistsModal from '../components/schedule/ShiftChecklistsModal.vue';
import ShiftTimesModal from '../components/schedule/ShiftTimesModal.vue';
import UnavailableConfirmModal from '../components/schedule/UnavailableConfirmModal.vue';
import { useAssignmentFlow } from '../composables/schedule/useAssignmentFlow.js';
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
import {
  cloneEmployee,
  getInitials,
  groupShiftsByGroup,
  isOvernightShift,
} from '../schedule/helpers.js';
import { formatDuration } from '../shiftHours.js';

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

// Shift groups
const {
  shiftGroups,
  archivedGroups,
  showArchivedGroups,
  restoringGroupId,
  activeGroupId,
  showNewGroupInput,
  newGroupName,
  creatingGroup,
  newGroupColor,
  editingGroupId,
  editingGroupName,
  isAllGroupsMode,
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
} = useShiftGroups({ fetchSchedule: (...args) => fetchSchedule(...args), showToast });

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
  employees,
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
  fetchDelegations,
  getDayDelegations,
  showNewbies,
  monthNewbies,
  fetchNewbies,
  getDayNewbies,
  isCurrentNewbie,
  showClosures,
  monthClosures,
  fetchClosures,
  getClosureForDate,
  showShiftColors,
  shiftColors,
  shiftColorById,
  fetchShiftColors,
  fetchSchedule,
} = useScheduleMonth({
  activeGroupId,
  isAllGroupsMode,
  canManageSchedule,
  selectedEmployee,
  restPrompt,
  showToast,
});

// The roster, the selected employee's availability and the cell styles
const {
  selectedAvailability,
  empSort,
  getEmployeeShiftCount,
  getEmployeeTotalHours,
  sortedEmployeePool,
  wasDragging,
  onDragStart,
  onDragEnd,
  handleEmployeeClick,
  toggleEmployeeSelect,
  deselectEmployee,
  fetchAvailability,
  getDayAvailStyle,
  isSelectedEmployeeAssigned,
  getShiftAssignedStyle,
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

function isShiftFuture(_shift, dateStr) {
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

