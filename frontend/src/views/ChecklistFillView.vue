<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <!-- STEP 1: Setup — choose template, date, users -->
      <div v-if="step === 'setup'">
        <h1 class="text-2xl font-bold text-white mb-1">Fill Shift Checklist</h1>
        <p class="text-slate-400 mb-6">Select a template and configure the handover.</p>

        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 space-y-5">
          <!-- Template -->
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Template</label>
            <select v-model="setup.templateId"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 cursor-pointer">
              <option value="" disabled class="bg-slate-800 text-slate-400">Choose a template...</option>
              <option v-for="t in activeTemplates" :key="t.id" :value="t.id" class="bg-slate-800">{{ t.name }}</option>
            </select>
          </div>

          <!-- Date -->
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Date</label>
            <input v-model="setup.date" type="date" @change="fetchShiftsForDate"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50" />
          </div>

          <!-- Shift (optional) -->
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Shift <span class="text-slate-500 font-normal">(optional)</span></label>
            <select v-model="setup.shiftId" @change="onShiftSelected"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 cursor-pointer">
              <option :value="null" class="bg-slate-800 text-slate-400">No shift selected</option>
              <option v-for="s in availableShifts" :key="s.id" :value="s.id" class="bg-slate-800">
                Shift {{ s.shift_number }} {{ s.start_time ? '(' + s.start_time.substring(0,5) + ' – ' + s.end_time.substring(0,5) + ')' : '' }}
                {{ s.assignments?.map(a => a.user?.name).filter(Boolean).join(', ') }}
              </option>
            </select>
          </div>

          <!-- Shift Checklists (shown when a shift with checklists is selected) -->
          <div v-if="selectedShiftChecklists.length" class="bg-white/[0.03] border border-white/10 rounded-xl p-3">
            <p class="text-xs text-slate-500 uppercase tracking-wide font-medium mb-2">Assigned Checklists</p>
            <div class="space-y-1.5">
              <button v-for="sc in selectedShiftChecklists" :key="sc.id" @click="setup.templateId = sc.checklist_template_id"
                :class="['w-full text-left px-3 py-2 rounded-lg border transition-all flex items-center justify-between',
                  setup.templateId === sc.checklist_template_id
                    ? 'bg-indigo-500/15 border-indigo-500/30 text-indigo-300'
                    : 'bg-white/[0.02] border-white/10 text-slate-300 hover:bg-white/5']">
                <div class="flex items-center gap-2">
                  <span :class="['w-2 h-2 rounded-full shrink-0', sc.is_required ? 'bg-amber-400' : 'bg-sky-400']"></span>
                  <span class="text-sm font-medium">{{ sc.template?.name || sc.checklist_template_name }}</span>
                  <span v-if="sc.is_required" class="text-[9px] font-bold uppercase text-amber-400 bg-amber-500/10 px-1.5 py-0.5 rounded">Required</span>
                </div>
                <span v-if="sc.deadline_minutes" class="text-[10px] text-slate-500">
                  {{ sc.deadline_minutes }} min {{ sc.deadline_reference === 'shift_end' ? 'before end' : 'after start' }}
                </span>
              </button>
            </div>
          </div>

          <!-- Outgoing User -->
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Outgoing Employee <span class="text-slate-500 font-normal">(ending shift)</span></label>
            <select v-model="setup.outgoingUserId"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 cursor-pointer">
              <option value="" disabled class="bg-slate-800 text-slate-400">Select...</option>
              <option v-for="u in users" :key="u.id" :value="u.id" class="bg-slate-800">{{ u.name }}</option>
            </select>
          </div>

          <!-- Incoming User -->
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Incoming Employee <span class="text-slate-500 font-normal">(starting shift — optional)</span></label>
            <select v-model="setup.incomingUserId"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/50 cursor-pointer">
              <option value="" class="bg-slate-800 text-slate-400">None (single user)</option>
              <option v-for="u in users.filter(u => u.id !== setup.outgoingUserId)" :key="u.id" :value="u.id" class="bg-slate-800">{{ u.name }}</option>
            </select>
          </div>

          <button @click="startFilling" :disabled="!setup.templateId || !setup.date || !setup.outgoingUserId"
            class="w-full px-5 py-3 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-40 disabled:cursor-not-allowed">
            Start Filling Checklist
          </button>
        </div>
      </div>

      <!-- STEP 2: Fill the form -->
      <div v-else-if="step === 'fill'">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h1 class="text-2xl font-bold text-white">{{ selectedTemplate.name }}</h1>
            <p class="text-slate-400 text-sm mt-1">
              {{ setup.date }} · Outgoing: <span class="text-indigo-400">{{ getUserName(setup.outgoingUserId) }}</span>
              <template v-if="setup.incomingUserId"> · Incoming: <span class="text-amber-400">{{ getUserName(setup.incomingUserId) }}</span></template>
            </p>
          </div>
          <button @click="step = 'setup'" class="text-sm text-slate-400 hover:text-white transition-colors">← Back</button>
        </div>

        <!-- Shift Info Card -->
        <div v-if="shiftInfo" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-xl p-4 mb-4">
          <div class="flex items-center gap-4 flex-wrap">
            <div class="flex items-center gap-2">
              <span class="w-7 h-7 rounded-lg bg-indigo-500/20 flex items-center justify-center text-xs text-indigo-400 font-bold">S{{ shiftInfo.shift_number }}</span>
              <div>
                <p class="text-white text-sm font-medium">Shift {{ shiftInfo.shift_number }}</p>
                <p v-if="shiftInfo.start_time" class="text-[10px] text-slate-500">{{ shiftInfo.start_time?.substring(0,5) }} – {{ shiftInfo.end_time?.substring(0,5) }}</p>
              </div>
            </div>
            <div class="w-px h-8 bg-white/10 hidden sm:block"></div>
            <div v-if="shiftColleagues.length">
              <p class="text-[10px] text-slate-500 uppercase tracking-wider mb-0.5">Also on this shift</p>
              <div class="flex items-center flex-wrap gap-1.5">
                <span v-for="name in shiftColleagues" :key="name"
                  class="px-2 py-0.5 text-[10px] font-medium bg-slate-500/15 text-slate-300 border border-slate-500/20 rounded-full">
                  {{ name }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Progress Bar -->
        <div class="bg-white/5 border border-white/10 rounded-xl px-4 py-3 mb-4">
          <div class="flex items-center justify-between mb-1.5">
            <span class="text-xs text-slate-400 font-medium">Progress</span>
            <span class="text-xs font-semibold" :class="progressPercent === 100 ? 'text-emerald-400' : 'text-slate-300'">{{ filledCount }} / {{ totalItems }} items</span>
          </div>
          <div class="w-full h-2 bg-white/10 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500 ease-out"
              :class="progressPercent === 100 ? 'bg-gradient-to-r from-emerald-500 to-teal-500' : 'bg-gradient-to-r from-indigo-500 to-violet-500'"
              :style="{ width: progressPercent + '%' }"></div>
          </div>
        </div>

        <!-- Unread Procedures Warning -->
        <div v-if="unreadProcedures.length" class="bg-red-500/10 border border-red-500/20 rounded-xl p-4 mb-4">
          <div class="flex items-center gap-2 mb-2">
            <svg class="w-5 h-5 text-red-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
            <p class="text-sm font-semibold text-red-400">You must read all linked procedures before submitting</p>
          </div>
          <p class="text-xs text-slate-400 mb-3">The following procedures require you to read their latest version:</p>
          <div class="space-y-1.5">
            <div v-for="proc in unreadProcedures" :key="proc.id" class="flex items-center gap-2">
              <span class="px-1.5 py-0.5 text-[9px] font-bold bg-red-500/15 text-red-400 border border-red-500/20 rounded-full">Unread</span>
              <router-link :to="'/procedures/' + proc.id" target="_blank"
                class="text-sm text-indigo-400 hover:text-indigo-300 underline transition-colors">
                {{ proc.identifier }} — {{ proc.title }}
                <span class="text-slate-500 no-underline">(v{{ proc.version }})</span>
              </router-link>
            </div>
          </div>
          <button @click="recheckProcedures" :disabled="recheckingProcedures"
            class="mt-3 px-4 py-1.5 text-xs font-medium text-white bg-indigo-500/20 border border-indigo-500/30 rounded-lg hover:bg-indigo-500/30 transition-all disabled:opacity-50 flex items-center gap-2">
            <svg v-if="recheckingProcedures" class="animate-spin w-3.5 h-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            Recheck Procedure Reads
          </button>
        </div>

        <div class="space-y-4">
          <!-- Groups -->
          <div v-for="(group, gi) in selectedTemplate.groups" :key="group.id"
            class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
            <h3 class="text-white font-semibold text-sm mb-4 flex items-center gap-2">
              <span class="w-6 h-6 rounded-lg bg-indigo-500/20 flex items-center justify-center text-[10px] text-indigo-400 font-bold">{{ gi + 1 }}</span>
              {{ group.name }}
            </h3>

            <div class="space-y-3">
              <div v-for="item in group.items" :key="item.id"
                :class="[
                  'flex items-start gap-3 rounded-xl px-2 py-1.5 -mx-2 transition-colors duration-150',
                  rangeErrors[item.id]
                    ? 'bg-red-500/[0.07] ring-1 ring-inset ring-red-500/25'
                    : 'hover:bg-white/[0.05] focus-within:bg-indigo-500/[0.07] focus-within:ring-1 focus-within:ring-inset focus-within:ring-indigo-500/20'
                ]">
                <!-- Checkbox type -->
                <template v-if="item.type === 'checkbox'">
                  <button @click="toggleCheckbox(item.id)"
                    :class="[
                      'mt-0.5 w-5 h-5 rounded-md border flex items-center justify-center transition-all shrink-0',
                      responses[item.id]?.value === 'true'
                        ? 'bg-indigo-500 border-indigo-500'
                        : 'bg-white/5 border-white/20 hover:border-indigo-400/50'
                    ]">
                    <svg v-if="responses[item.id]?.value === 'true'" class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                    </svg>
                  </button>
                  <div class="flex-1">
                    <span class="text-sm text-slate-300">{{ item.label }}</span>
                    <span v-if="item.requires_confirmation" class="ml-2 px-1.5 py-0.5 text-[8px] font-semibold uppercase bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">2-User</span>
                    <button v-if="item.procedure" @click.stop="openProcedureModal(item.procedure.id)"
                      :class="['inline-flex items-center ml-1.5 px-1.5 py-0.5 text-[9px] font-medium rounded transition-all cursor-pointer',
                        isProcedureUnread(item.procedure_id)
                          ? 'bg-orange-500/15 text-orange-400 border border-orange-500/25 hover:bg-orange-500/25 animate-pulse'
                          : 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 hover:bg-indigo-500/20']"
                      :title="isProcedureUnread(item.procedure_id) ? `⚠ Unread! ${item.procedure.identifier} — ${item.procedure.title}` : `Procedure: ${item.procedure.identifier} — ${item.procedure.title}`">
                      {{ isProcedureUnread(item.procedure_id) ? '⚠️' : '📋' }} {{ item.procedure.identifier }}
                    </button>
                  </div>
                </template>

                <!-- Text type -->
                <template v-else-if="item.type === 'text'">
                  <div class="flex-1">
                    <label class="text-sm text-slate-300 mb-1 block">
                      {{ item.label }}
                      <span v-if="item.requires_confirmation" class="ml-2 px-1.5 py-0.5 text-[8px] font-semibold uppercase bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">2-User</span>
                      <button v-if="item.procedure" @click.stop="openProcedureModal(item.procedure.id)"
                        :class="['inline-flex items-center ml-1.5 px-1.5 py-0.5 text-[9px] font-medium rounded transition-all cursor-pointer',
                          isProcedureUnread(item.procedure_id)
                            ? 'bg-orange-500/15 text-orange-400 border border-orange-500/25 hover:bg-orange-500/25 animate-pulse'
                            : 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 hover:bg-indigo-500/20']"
                        :title="isProcedureUnread(item.procedure_id) ? `⚠ Unread! ${item.procedure.identifier} — ${item.procedure.title}` : `Procedure: ${item.procedure.identifier} — ${item.procedure.title}`">
                        {{ isProcedureUnread(item.procedure_id) ? '⚠️' : '📋' }} {{ item.procedure.identifier }}
                      </button>
                    </label>
                    <input v-model="responses[item.id].value" type="text"
                      class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-indigo-500/50 text-sm" />
                  </div>
                </template>

                <!-- Number type -->
                <template v-else-if="item.type === 'number'">
                  <div class="flex-1 flex items-center gap-3 min-w-0">
                    <div class="flex-1 min-w-0">
                      <span class="text-sm text-slate-300 flex items-center gap-1.5 flex-wrap leading-snug">
                        {{ item.label }}
                        <span v-if="item.requires_confirmation" class="px-1.5 py-0.5 text-[8px] font-semibold uppercase bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">2-User</span>
                        <button v-if="item.procedure" @click.stop="openProcedureModal(item.procedure.id)"
                          :class="['inline-flex items-center px-1.5 py-0.5 text-[9px] font-medium rounded transition-all cursor-pointer',
                            isProcedureUnread(item.procedure_id)
                              ? 'bg-orange-500/15 text-orange-400 border border-orange-500/25 hover:bg-orange-500/25 animate-pulse'
                              : 'bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 hover:bg-indigo-500/20']"
                          :title="isProcedureUnread(item.procedure_id) ? `⚠ Unread! ${item.procedure.identifier} — ${item.procedure.title}` : `Procedure: ${item.procedure.identifier} — ${item.procedure.title}`">
                          {{ isProcedureUnread(item.procedure_id) ? '⚠️' : '📋' }} {{ item.procedure.identifier }}
                        </button>
                      </span>
                      <p v-if="rangeErrors[item.id]" class="text-[10px] text-red-400 mt-0.5">{{ rangeErrors[item.id] }}</p>
                    </div>
                    <div class="shrink-0 flex flex-col items-end gap-0.5">
                      <input v-model="responses[item.id].value" type="number"
                        @input="validateNumberRange(item)"
                        :class="[
                          'w-24 px-3 py-1.5 text-sm text-right rounded-lg focus:outline-none focus:ring-1 transition-colors [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none',
                          rangeErrors[item.id]
                            ? 'bg-red-500/10 border border-red-500/40 text-red-300 focus:ring-red-500/40'
                            : 'bg-white/5 border border-white/10 text-white focus:ring-indigo-500/50'
                        ]" />
                      <span v-if="item.min_value !== null || item.max_value !== null"
                        class="text-[9px] text-slate-600 tabular-nums">
                        {{ item.min_value ?? '…' }} – {{ item.max_value ?? '…' }}
                      </span>
                    </div>
                  </div>
                </template>
              </div>
            </div>

            <!-- Group Comment -->
            <div class="mt-4 pt-3 border-t border-white/5">
              <label class="text-xs text-slate-500 mb-1 block">Comments for {{ group.name }}</label>
              <textarea v-model="groupComments[group.id]" rows="2" placeholder="Optional comments..."
                class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-indigo-500/50 text-xs resize-none"></textarea>
            </div>
          </div>

          <!-- General Comments -->
          <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
            <h3 class="text-white font-semibold text-sm mb-3">General Comments</h3>
            <textarea v-model="generalComments" rows="3" placeholder="Any additional notes..."
              class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-indigo-500/50 text-sm resize-none"></textarea>
          </div>

          <!-- Submit -->
          <button @click="submitChecklist" :disabled="submitting || unreadProcedures.length > 0"
            :title="unreadProcedures.length ? 'Read all linked procedures first' : ''"
            class="w-full px-5 py-3.5 bg-violet-600 hover:bg-violet-700 text-white font-bold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
            <svg v-if="submitting" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <template v-if="unreadProcedures.length">📋 Read {{ unreadProcedures.length }} procedure{{ unreadProcedures.length > 1 ? 's' : '' }} first</template>
            <template v-else>Submit Checklist</template>
          </button>
        </div>
      </div>

      <!-- STEP 3: Confirm (incoming user) -->
      <div v-else-if="step === 'confirm'">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h1 class="text-2xl font-bold text-white">Confirm Handover</h1>
            <p class="text-slate-400 text-sm mt-1">
              Review and confirm items marked by the outgoing employee.
            </p>
          </div>
        </div>

        <!-- Pending submissions -->
        <div v-if="pendingSubmissions.length === 0" class="text-center py-20">
          <svg class="w-16 h-16 text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p class="text-slate-400 text-lg">No checklists awaiting your confirmation.</p>
        </div>

        <div v-else class="space-y-3">
          <div v-for="sub in pendingSubmissions" :key="sub.id"
            class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5 cursor-pointer hover:bg-white/[0.07] transition-all"
            @click="openConfirmation(sub)">
            <div class="flex items-center justify-between">
              <div>
                <h3 class="text-white font-semibold text-sm">{{ sub.template?.name || 'Checklist' }}</h3>
                <p class="text-slate-500 text-xs mt-0.5">
                  {{ sub.date }} · Filled by <span class="text-indigo-400">{{ sub.outgoing_user?.name }}</span>
                </p>
              </div>
              <span class="px-2.5 py-1 text-[10px] font-semibold uppercase bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">
                Pending
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- STEP 4: Confirm detail view -->
      <div v-else-if="step === 'confirm-detail' && confirmSubmission">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h1 class="text-2xl font-bold text-white">{{ confirmSubmission.template?.name }}</h1>
            <p class="text-slate-400 text-sm mt-1">
              {{ confirmSubmission.date }} · Filled by <span class="text-indigo-400">{{ confirmSubmission.outgoing_user?.name }}</span>
            </p>
          </div>
          <button @click="step = 'confirm'" class="text-sm text-slate-400 hover:text-white transition-colors">← Back</button>
        </div>

        <div class="space-y-4">
          <div v-for="(group, gi) in confirmSubmission.template?.groups || []" :key="group.id"
            class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
            <h3 class="text-white font-semibold text-sm mb-4 flex items-center gap-2">
              <span class="w-6 h-6 rounded-lg bg-indigo-500/20 flex items-center justify-center text-[10px] text-indigo-400 font-bold">{{ gi + 1 }}</span>
              {{ group.name }}
            </h3>

            <div class="space-y-3">
              <div v-for="item in group.items" :key="item.id" class="flex items-start gap-3">
                <div class="flex-1">
                  <div class="flex items-center gap-2 mb-1">
                    <span class="text-sm text-slate-300">{{ item.label }}</span>
                    <span v-if="item.requires_confirmation" class="px-1.5 py-0.5 text-[8px] font-semibold uppercase bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">Needs Confirmation</span>
                    <button v-if="item.procedure" @click.stop="openProcedureModal(item.procedure.id)"
                      class="inline-flex items-center ml-1 px-1.5 py-0.5 text-[9px] font-medium bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 rounded hover:bg-indigo-500/20 transition-all cursor-pointer"
                      :title="`Procedure: ${item.procedure.identifier} — ${item.procedure.title}`">
                      📋 {{ item.procedure.identifier }}
                    </button>
                  </div>
                  <!-- Show filled value -->
                  <div class="text-sm text-indigo-300 bg-indigo-500/10 px-3 py-1.5 rounded-lg inline-block">
                    {{ getResponseDisplay(item) }}
                  </div>
                </div>
                <!-- Confirm checkbox for flagged items -->
                <div v-if="item.requires_confirmation" class="shrink-0">
                  <button @click="toggleConfirm(getResponseId(item.id))"
                    :class="[
                      'mt-1 w-6 h-6 rounded-md border-2 flex items-center justify-center transition-all',
                      confirmIds.has(getResponseId(item.id))
                        ? 'bg-emerald-500 border-emerald-500'
                        : 'bg-white/5 border-amber-500/50 hover:border-emerald-400/50'
                    ]">
                    <svg v-if="confirmIds.has(getResponseId(item.id))" class="w-3.5 h-3.5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>

            <!-- Group comment from outgoing user -->
            <div v-if="getGroupComment(group.id)" class="mt-3 pt-3 border-t border-white/5">
              <p class="text-xs text-slate-500 mb-1">Comment from outgoing employee:</p>
              <p class="text-xs text-slate-400 italic">{{ getGroupComment(group.id) }}</p>
            </div>
          </div>

          <!-- General Comments -->
          <div v-if="confirmSubmission.general_comments" class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5">
            <h3 class="text-white font-semibold text-sm mb-2">General Comments</h3>
            <p class="text-sm text-slate-400">{{ confirmSubmission.general_comments }}</p>
          </div>

          <!-- Confirm Button -->
          <button @click="submitConfirmation" :disabled="confirming"
            class="w-full px-5 py-3.5 bg-violet-600 hover:bg-violet-700 text-white font-bold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
            <svg v-if="confirming" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            Confirm & Complete Checklist
          </button>
        </div>
      </div>

      <!-- Step selector at bottom -->
      <div v-if="step === 'setup'" class="mt-8 flex justify-center">
        <button @click="loadPendingConfirmations"
          class="px-5 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          View Pending Confirmations
        </button>
      </div>
    </main>

    <!-- Toast -->
    <Transition
      enter-active-class="transition-all duration-400 ease-out"
      leave-active-class="transition-all duration-300 ease-in"
      enter-from-class="translate-y-4 opacity-0 scale-95"
      enter-to-class="translate-y-0 opacity-100 scale-100"
      leave-from-class="translate-y-0 opacity-100 scale-100"
      leave-to-class="translate-y-4 opacity-0 scale-95"
    >
      <div v-if="toastMessage" class="fixed bottom-6 right-6 z-[60] max-w-sm">
        <div :class="[
          'backdrop-blur-xl border rounded-xl px-5 py-4 shadow-2xl shadow-black/40 flex items-start gap-3',
          toastType === 'error' ? 'bg-slate-800/95 border-red-500/20' : 'bg-slate-800/95 border-emerald-500/20'
        ]">
          <p :class="[
            'text-sm font-medium',
            toastType === 'error' ? 'text-red-300' : 'text-emerald-300'
          ]">{{ toastMessage }}</p>
        </div>
      </div>
    </Transition>

    <!-- Procedure Preview Modal -->
    <Transition enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="showProcedureModal" class="fixed inset-0 z-[70] flex items-start justify-center pt-4 px-4">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="showProcedureModal = false"></div>
        <div class="relative w-full max-w-4xl max-h-[90vh] overflow-y-auto bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl">
          <!-- Header -->
          <div class="sticky top-0 z-10 bg-slate-800/95 backdrop-blur-xl border-b border-white/10 px-6 py-4 flex items-center justify-between">
            <div v-if="procedurePreview" class="flex items-center gap-3 min-w-0">
              <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full shrink-0">
                {{ procedurePreview.identifier }}
              </span>
              <h2 class="text-lg font-bold text-white truncate">{{ procedurePreview.title }}</h2>
              <span class="text-[10px] text-slate-500 tabular-nums shrink-0">v{{ procedurePreview.version }}</span>
            </div>
            <div v-else class="text-slate-400 text-sm">Loading...</div>
            <div class="flex items-center gap-2 shrink-0 ml-3">
              <button v-if="procedurePreview" @click="copyProcedureLink" class="p-2 text-slate-400 hover:text-indigo-400 hover:bg-indigo-500/10 rounded-lg transition-all" title="Copy link to procedure">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
              </button>
              <router-link v-if="procedurePreview" :to="`/procedures/${procedurePreview.id}`"
                class="p-2 text-slate-400 hover:text-violet-400 hover:bg-violet-500/10 rounded-lg transition-all" title="Open full page">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
              </router-link>
              <button @click="showProcedureModal = false" class="p-2 text-slate-400 hover:text-white transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
          </div>
          <!-- Shared Content -->
          <div v-if="procedurePreview" class="px-6 py-5">
            <ProcedureContent
              ref="procedureContentRef"
              :procedure="procedurePreview"
              :user-id="currentUser?.id"
              :change-reason="procedurePreview.change_reason"
              :publisher="procedurePreview.publisher"
              :published-at="procedurePreview.published_at"
              :viewing-version="procedurePreview.version"
              @toast="msg => showToast(msg)" />
          </div>
          <!-- Loading spinner -->
          <div v-else class="flex items-center justify-center py-16">
            <svg class="animate-spin w-6 h-6 text-indigo-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
          </div>
        </div>
      </div>
    </Transition>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { currentUser } from '../router/index.js';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import ProcedureContent from '../components/procedure/ProcedureContent.vue';

const router = useRouter();
const route = useRoute();

// State
const step = ref('setup'); // setup, fill, confirm, confirm-detail
const activeTemplates = ref([]);
const users = ref([]);
const selectedTemplate = ref(null);
const shiftInfo = ref(null);
const availableShifts = ref([]);

const shiftColleagues = computed(() => {
  if (!shiftInfo.value?.assignments) return [];
  const currentIds = [setup.outgoingUserId, setup.incomingUserId].filter(Boolean);
  return shiftInfo.value.assignments
    .map(a => a.user)
    .filter(u => u && !currentIds.includes(u.id))
    .map(u => u.name);
});

// Progress tracking
const totalItems = computed(() => {
  if (!selectedTemplate.value) return 0;
  return selectedTemplate.value.groups.reduce((sum, g) => sum + g.items.length, 0);
});

const filledCount = computed(() => {
  if (!selectedTemplate.value) return 0;
  let count = 0;
  for (const group of selectedTemplate.value.groups) {
    for (const item of group.items) {
      const r = responses.value[item.id];
      if (!r) continue;
      if (item.type === 'checkbox' && r.value === 'true') count++;
      else if (item.type !== 'checkbox' && r.value?.toString().trim()) count++;
    }
  }
  return count;
});

const progressPercent = computed(() => {
  return totalItems.value ? Math.round((filledCount.value / totalItems.value) * 100) : 0;
});

const setup = reactive({
  templateId: '',
  shiftId: null,
  date: new Date().toISOString().split('T')[0],
  outgoingUserId: '',
  incomingUserId: '',
});

const responses = ref({}); // { [itemId]: { value, checklist_item_id } }
const rangeErrors = ref({}); // { [itemId]: errorMessage }
const groupComments = ref({}); // { [groupId]: string }
const generalComments = ref('');
const submitting = ref(false);

// Confirm state
const pendingSubmissions = ref([]);
const confirmSubmission = ref(null);
const confirmIds = ref(new Set());
const confirming = ref(false);

// Procedure modal
const showProcedureModal = ref(false);
const procedurePreview = ref(null);
const procedureContentRef = ref(null);

// Procedure read status
const unreadProcedures = ref([]);
const recheckingProcedures = ref(false);

// Toast
const toastMessage = ref('');
const toastType = ref('success');
let toastTimer = null;

function showToast(message, type = 'success') {
  toastMessage.value = message;
  toastType.value = type;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toastMessage.value = ''; }, 4000);
}
onUnmounted(() => clearTimeout(toastTimer));

// Load initial data
async function loadData() {
  try {
    const [templatesRes, usersRes] = await Promise.all([
      api.get('/api/checklist-templates/active'),
      api.get('/api/employees'),
    ]);
    activeTemplates.value = templatesRes.data;
    users.value = usersRes.data;

    // Auto-select from query params (from dashboard alert links)
    if (route.query.template_id) {
      setup.templateId = Number(route.query.template_id);
    }
    const queryShiftId = route.query.shift_id ? Number(route.query.shift_id) : null;
    if (queryShiftId) {
      setup.shiftId = queryShiftId;
    }
    if (route.query.date) {
      setup.date = route.query.date;
    }
    // Auto-select current user as outgoing
    if (currentUser.value?.id && !setup.outgoingUserId) {
      setup.outgoingUserId = currentUser.value.id;
    }

    // Load shifts for initial date
    await fetchShiftsForDate(queryShiftId);

    // Auto-start filling if requested and all required fields are set
    if (route.query.auto_start === '1' && setup.templateId && setup.date && setup.outgoingUserId) {
      await startFilling();
    }
  } catch {
    showToast('Failed to load data.', 'error');
  }
}

async function fetchShiftsForDate(preserveShiftId = null) {
  availableShifts.value = [];
  if (!preserveShiftId) setup.shiftId = null;
  if (!setup.date) return;
  try {
    const res = await api.get('/api/shifts-by-date', { params: { date: setup.date } });
    availableShifts.value = res.data;
    // Restore shiftId if preserved from query params
    if (preserveShiftId && res.data.some(s => s.id === preserveShiftId)) {
      setup.shiftId = preserveShiftId;
    }
  } catch {}
}

function onShiftSelected() {
  const shift = availableShifts.value.find(s => s.id === setup.shiftId);
  if (!shift) return;
  // Auto-select the first checklist's template if shift has checklists
  const checklists = shift.shift_checklists || [];
  if (checklists.length > 0 && !setup.templateId) {
    setup.templateId = checklists[0].checklist_template_id;
  }
  // Auto-select current user as outgoing if they're on this shift
  const myAssignment = shift.assignments?.find(a => a.user_id === currentUser.value?.id);
  if (myAssignment) {
    setup.outgoingUserId = currentUser.value.id;
  }
}

const selectedShiftChecklists = computed(() => {
  const shift = availableShifts.value.find(s => s.id === setup.shiftId);
  if (!shift) return [];
  return shift.shift_checklists || [];
});

function getUserName(id) {
  const u = users.value.find(u => u.id === id);
  return u?.name || 'Unknown';
}

async function startFilling() {
  const tpl = activeTemplates.value.find(t => t.id === Number(setup.templateId));
  if (!tpl) return;
  selectedTemplate.value = tpl;

  // Initialize responses
  const r = {};
  const gc = {};
  for (const group of tpl.groups) {
    gc[group.id] = '';
    for (const item of group.items) {
      r[item.id] = {
        checklist_item_id: item.id,
        value: item.type === 'checkbox' ? 'false' : '',
        group_comment: null,
      };
    }
  }
  responses.value = r;
  groupComments.value = gc;
  generalComments.value = '';
  step.value = 'fill';

  // Check procedure reads
  await checkProcedureReads(tpl);

  // Fetch shift info if available
  if (setup.shiftId) {
    try {
      shiftInfo.value = (await api.get(`/api/shifts/${setup.shiftId}`)).data;
    } catch { shiftInfo.value = null; }
  } else {
    shiftInfo.value = null;
  }
}

async function checkProcedureReads(tpl) {
  const procIds = [];
  for (const group of tpl.groups) {
    for (const item of group.items) {
      if (item.procedure_id && !procIds.includes(item.procedure_id)) {
        procIds.push(item.procedure_id);
      }
    }
  }
  if (!procIds.length) {
    unreadProcedures.value = [];
    return;
  }
  try {
    const res = await api.post('/api/procedures/check-read-status', { procedure_ids: procIds });
    unreadProcedures.value = res.data;
  } catch {
    unreadProcedures.value = [];
  }
}

function isProcedureUnread(procedureId) {
  return unreadProcedures.value.some(p => p.id === procedureId);
}

async function recheckProcedures() {
  recheckingProcedures.value = true;
  try {
    await checkProcedureReads(selectedTemplate.value);
    if (!unreadProcedures.value.length) {
      showToast('All procedures read! You can now submit.', 'success');
    }
  } finally {
    recheckingProcedures.value = false;
  }
}

function toggleCheckbox(itemId) {
  const current = responses.value[itemId].value;
  responses.value[itemId].value = current === 'true' ? 'false' : 'true';
}

function validateNumberRange(item) {
  const raw = responses.value[item.id]?.value;
  if (raw === '' || raw === null || raw === undefined) {
    delete rangeErrors.value[item.id];
    return;
  }
  const val = parseFloat(raw);
  if (isNaN(val)) { delete rangeErrors.value[item.id]; return; }

  const hasMin = item.min_value !== null && item.min_value !== undefined;
  const hasMax = item.max_value !== null && item.max_value !== undefined;

  if (hasMin && hasMax && (val < item.min_value || val > item.max_value)) {
    rangeErrors.value[item.id] = `Must be between ${item.min_value} and ${item.max_value}`;
  } else if (hasMin && val < item.min_value) {
    rangeErrors.value[item.id] = `Must be at least ${item.min_value}`;
  } else if (hasMax && val > item.max_value) {
    rangeErrors.value[item.id] = `Must be at most ${item.max_value}`;
  } else {
    delete rangeErrors.value[item.id];
  }
}

async function submitChecklist() {
  const errorCount = Object.keys(rangeErrors.value).length;
  if (errorCount > 0) {
    showToast(`${errorCount} item${errorCount > 1 ? 's have' : ' has'} an out-of-range value. Please correct before submitting.`, 'error');
    return;
  }
  submitting.value = true;
  try {
    // Attach group comments to first item of each group
    const tpl = selectedTemplate.value;
    for (const group of tpl.groups) {
      const comment = groupComments.value[group.id];
      if (comment && group.items.length > 0) {
        responses.value[group.items[0].id].group_comment = comment;
      }
    }

    const payload = {
      checklist_template_id: setup.templateId,
      shift_id: setup.shiftId || null,
      date: setup.date,
      outgoing_user_id: setup.outgoingUserId,
      incoming_user_id: setup.incomingUserId || null,
      general_comments: generalComments.value || null,
      responses: Object.values(responses.value),
    };

    await api.post('/api/checklist-submissions', payload);
    showToast('Checklist submitted successfully!');
    step.value = 'setup';
  } catch (err) {
    const msg = err.response?.data?.message || 'Failed to submit checklist.';
    showToast(msg, 'error');
  } finally {
    submitting.value = false;
  }
}

// Confirmation flow
async function loadPendingConfirmations() {
  try {
    const response = await api.get('/api/checklist-submissions', {
      params: {
        status: 'pending_confirmation',
        user_id: currentUser.value?.id,
      },
    });
    pendingSubmissions.value = response.data.data || [];
    step.value = 'confirm';
  } catch {
    showToast('Failed to load pending confirmations.', 'error');
  }
}

async function openConfirmation(sub) {
  try {
    const response = await api.get(`/api/checklist-submissions/${sub.id}`);
    confirmSubmission.value = response.data;
    confirmIds.value = new Set();
    step.value = 'confirm-detail';
  } catch {
    showToast('Failed to load submission.', 'error');
  }
}

function getResponseForItem(itemId) {
  return confirmSubmission.value?.responses?.find(r => r.checklist_item_id === itemId);
}

function getResponseDisplay(item) {
  const r = getResponseForItem(item.id);
  if (!r) return '—';
  if (item.type === 'checkbox') return r.value === 'true' ? '✓ Yes' : '✗ No';
  return r.value || '—';
}

function getResponseId(itemId) {
  const r = getResponseForItem(itemId);
  return r?.id;
}

function getGroupComment(groupId) {
  // Find first item in this group
  const group = confirmSubmission.value?.template?.groups?.find(g => g.id === groupId);
  if (!group || !group.items.length) return null;
  const r = getResponseForItem(group.items[0].id);
  return r?.group_comment || null;
}

function toggleConfirm(responseId) {
  if (!responseId) return;
  const newSet = new Set(confirmIds.value);
  if (newSet.has(responseId)) {
    newSet.delete(responseId);
  } else {
    newSet.add(responseId);
  }
  confirmIds.value = newSet;
}

async function submitConfirmation() {
  confirming.value = true;
  try {
    await api.put(`/api/checklist-submissions/${confirmSubmission.value.id}/confirm`, {
      confirmed_item_ids: Array.from(confirmIds.value),
    });
    showToast('Checklist confirmed and completed!');
    step.value = 'setup';
  } catch (err) {
    const msg = err.response?.data?.message || 'Failed to confirm checklist.';
    showToast(msg, 'error');
  } finally {
    confirming.value = false;
  }
}

onMounted(loadData);

async function openProcedureModal(procedureId) {
  showProcedureModal.value = true;
  procedurePreview.value = null;
  try {
    procedurePreview.value = (await api.get(`/api/procedures/${procedureId}`)).data;
    // Reset tab in ProcedureContent
    setTimeout(() => procedureContentRef.value?.resetTab(), 0);
  } catch {
    showToast('Failed to load procedure.', 'error');
    showProcedureModal.value = false;
  }
}

function copyProcedureLink() {
  if (!procedurePreview.value) return;
  const url = `${window.location.origin}/procedures/${procedurePreview.value.id}`;
  navigator.clipboard.writeText(url);
  showToast('Procedure link copied!');
}

</script>
