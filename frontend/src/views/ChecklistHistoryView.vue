<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">Checklist Archive</h1>
          <p class="text-slate-400 mt-1">Browse, sort, and filter all submitted checklists.</p>
        </div>
        <div class="flex items-center gap-3">
          <button @click="exportCSV" v-if="submissions.length"
            class="px-4 py-2 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Export CSV
          </button>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-xl px-4 py-3">
          <p class="text-xs text-slate-500 uppercase tracking-wider">Total</p>
          <p class="text-2xl font-bold text-white mt-0.5">{{ stats.total }}</p>
        </div>
        <div class="bg-white/5 backdrop-blur-xl border border-emerald-500/10 rounded-xl px-4 py-3">
          <p class="text-xs text-emerald-500/70 uppercase tracking-wider">Completed</p>
          <p class="text-2xl font-bold text-emerald-400 mt-0.5">{{ stats.completed }}</p>
        </div>
        <div class="bg-white/5 backdrop-blur-xl border border-amber-500/10 rounded-xl px-4 py-3">
          <p class="text-xs text-amber-500/70 uppercase tracking-wider">Pending</p>
          <p class="text-2xl font-bold text-amber-400 mt-0.5">{{ stats.pending }}</p>
        </div>
        <div class="bg-white/5 backdrop-blur-xl border border-slate-500/10 rounded-xl px-4 py-3">
          <p class="text-xs text-slate-500 uppercase tracking-wider">Draft</p>
          <p class="text-2xl font-bold text-slate-400 mt-0.5">{{ stats.draft }}</p>
        </div>
      </div>

      <!-- Filters Bar -->
      <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4 mb-4">
        <div class="flex flex-wrap gap-3 items-end">
          <!-- Search -->
          <div class="flex-1 min-w-[180px]">
            <label class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1">Search</label>
            <div class="relative">
              <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
              </svg>
              <input v-model="searchQuery" type="text" placeholder="Search by template or user..."
                class="w-full pl-9 pr-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white placeholder-slate-500 focus:outline-none focus:ring-1 focus:ring-indigo-500/50 text-sm" />
            </div>
          </div>

          <!-- Date From -->
          <div>
            <label class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1">From</label>
            <input v-model="filters.date_from" type="date"
              class="px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500/50 w-[150px]" />
          </div>

          <!-- Date To -->
          <div>
            <label class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1">To</label>
            <input v-model="filters.date_to" type="date"
              class="px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500/50 w-[150px]" />
          </div>

          <!-- Template Filter -->
          <div>
            <label class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1">Template</label>
            <select v-model="filters.template_id"
              class="px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500/50 cursor-pointer min-w-[140px]">
              <option value="" class="bg-slate-800">All templates</option>
              <option v-for="t in templates" :key="t.id" :value="t.id" class="bg-slate-800">{{ t.name }}</option>
            </select>
          </div>

          <!-- Status Filter -->
          <div>
            <label class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1">Status</label>
            <select v-model="filters.status"
              class="px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500/50 cursor-pointer min-w-[130px]">
              <option value="" class="bg-slate-800">All statuses</option>
              <option value="draft" class="bg-slate-800">Draft</option>
              <option value="pending_confirmation" class="bg-slate-800">Pending</option>
              <option value="completed" class="bg-slate-800">Completed</option>
            </select>
          </div>

          <!-- User Filter -->
          <div>
            <label class="block text-[10px] text-slate-500 uppercase tracking-wider mb-1">Employee</label>
            <select v-model="filters.user_id"
              class="px-3 py-2 bg-white/5 border border-white/10 rounded-lg text-white text-sm focus:outline-none focus:ring-1 focus:ring-indigo-500/50 cursor-pointer min-w-[140px]">
              <option value="" class="bg-slate-800">All employees</option>
              <option v-for="u in employees" :key="u.id" :value="u.id" class="bg-slate-800">{{ u.name }}</option>
            </select>
          </div>

          <!-- Action buttons -->
          <div class="flex gap-2">
            <button @click="applyFilters"
              class="px-4 py-2 bg-indigo-500/15 hover:bg-indigo-500/25 text-indigo-400 border border-indigo-500/20 rounded-lg text-sm font-medium transition-all">
              Apply
            </button>
            <button @click="resetFilters"
              class="px-3 py-2 text-slate-500 hover:text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-lg text-sm transition-all">
              Reset
            </button>
          </div>
        </div>
      </div>

      <!-- Results count -->
      <div class="flex items-center justify-between mb-3">
        <p class="text-xs text-slate-500">
          Showing <span class="text-slate-300 font-medium">{{ filteredSubmissions.length }}</span> of <span class="text-slate-300 font-medium">{{ submissions.length }}</span> results
        </p>
        <div class="flex items-center gap-2 text-xs text-slate-500">
          <span>Per page:</span>
          <select v-model="perPage" class="bg-white/5 border border-white/10 rounded px-2 py-1 text-slate-300 text-xs cursor-pointer">
            <option :value="10" class="bg-slate-800">10</option>
            <option :value="25" class="bg-slate-800">25</option>
            <option :value="50" class="bg-slate-800">50</option>
            <option :value="100" class="bg-slate-800">100</option>
          </select>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20">
        <svg class="animate-spin w-8 h-8 text-indigo-400" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
      </div>

      <!-- Empty -->
      <div v-else-if="filteredSubmissions.length === 0" class="text-center py-20">
        <svg class="w-16 h-16 text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
        </svg>
        <p class="text-slate-400 text-lg mb-1">No submissions found</p>
        <p class="text-slate-500 text-sm">Try adjusting your filters or date range.</p>
      </div>

      <!-- Sortable Table -->
      <div v-else class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="border-b border-white/10">
                <th v-for="col in columns" :key="col.key"
                  :class="[
                    'px-5 py-3.5 text-xs font-semibold uppercase tracking-wider cursor-pointer select-none hover:bg-white/5 transition-colors',
                    col.align === 'right' ? 'text-right' : 'text-left',
                    sortColumn === col.key ? 'text-indigo-400' : 'text-slate-400'
                  ]"
                  @click="col.sortable !== false && toggleSort(col.key)">
                  <div class="flex items-center gap-1" :class="col.align === 'right' ? 'justify-end' : ''">
                    {{ col.label }}
                    <template v-if="col.sortable !== false">
                      <svg v-if="sortColumn === col.key && sortDirection === 'asc'" class="w-3 h-3 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                      </svg>
                      <svg v-else-if="sortColumn === col.key && sortDirection === 'desc'" class="w-3 h-3 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                      </svg>
                      <svg v-else class="w-3 h-3 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                      </svg>
                    </template>
                  </div>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="sub in paginatedSubmissions" :key="sub.id"
                class="border-b border-white/5 hover:bg-white/[0.03] transition-colors cursor-pointer group"
                @click="viewDetail(sub.id)">
                <td class="px-5 py-3.5">
                  <span class="text-sm text-white font-medium">{{ formatDate(sub.date) }}</span>
                  <p class="text-[10px] text-slate-500 mt-0.5">{{ getDayOfWeek(sub.date) }}</p>
                </td>
                <td class="px-5 py-3.5">
                  <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg bg-indigo-500/15 flex items-center justify-center shrink-0">
                      <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                      </svg>
                    </div>
                    <span class="text-sm text-white font-medium">{{ sub.template?.name || '—' }}</span>
                  </div>
                </td>
                <td class="px-5 py-3.5">
                  <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white text-[9px] font-bold shrink-0">
                      {{ getInitials(sub.outgoing_user?.name) }}
                    </div>
                    <span class="text-sm text-slate-300">{{ sub.outgoing_user?.name || '—' }}</span>
                  </div>
                </td>
                <td class="px-5 py-3.5">
                  <div v-if="sub.incoming_user" class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white text-[9px] font-bold shrink-0">
                      {{ getInitials(sub.incoming_user?.name) }}
                    </div>
                    <span class="text-sm text-slate-300">{{ sub.incoming_user?.name }}</span>
                  </div>
                  <span v-else class="text-sm text-slate-600">—</span>
                </td>
                <td class="px-5 py-3.5">
                  <span :class="statusBadgeClass(sub.status)">
                    {{ statusLabel(sub.status) }}
                  </span>
                </td>
                <td class="px-5 py-3.5">
                  <span v-if="sub.submitted_on_time === true"
                    class="px-2 py-0.5 text-[10px] font-bold uppercase bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 rounded-full">On time</span>
                  <span v-else-if="sub.submitted_on_time === false"
                    class="px-2 py-0.5 text-[10px] font-bold uppercase bg-red-500/15 text-red-400 border border-red-500/20 rounded-full">Late</span>
                  <span v-else class="text-xs text-slate-600">—</span>
                </td>
                <td class="px-5 py-3.5">
                  <span class="text-xs text-slate-500">{{ formatDateTime(sub.submitted_at) }}</span>
                </td>
                <td class="px-5 py-3.5 text-right">
                  <button @click.stop="viewDetail(sub.id)"
                    class="px-3 py-1.5 text-xs font-medium text-indigo-400 hover:text-indigo-300 bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/20 rounded-lg transition-all opacity-0 group-hover:opacity-100">
                    Details
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="flex items-center justify-between mt-4">
        <p class="text-xs text-slate-500">
          Page {{ currentPage }} of {{ totalPages }}
        </p>
        <div class="flex items-center gap-1">
          <button @click="currentPage = 1" :disabled="currentPage === 1"
            class="w-8 h-8 rounded-lg text-xs text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 disabled:opacity-30 transition-all flex items-center justify-center">
            ««
          </button>
          <button @click="currentPage--" :disabled="currentPage === 1"
            class="w-8 h-8 rounded-lg text-xs text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 disabled:opacity-30 transition-all flex items-center justify-center">
            «
          </button>
          <template v-for="p in visiblePages" :key="p">
            <button v-if="p !== '...'" @click="currentPage = p"
              :class="[
                'w-8 h-8 rounded-lg text-xs font-medium transition-all',
                p === currentPage ? 'bg-indigo-500 text-white shadow-lg shadow-indigo-500/25' : 'bg-white/5 text-slate-400 hover:bg-white/10 hover:text-white'
              ]">
              {{ p }}
            </button>
            <span v-else class="w-8 h-8 flex items-center justify-center text-xs text-slate-600">…</span>
          </template>
          <button @click="currentPage++" :disabled="currentPage === totalPages"
            class="w-8 h-8 rounded-lg text-xs text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 disabled:opacity-30 transition-all flex items-center justify-center">
            »
          </button>
          <button @click="currentPage = totalPages" :disabled="currentPage === totalPages"
            class="w-8 h-8 rounded-lg text-xs text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 disabled:opacity-30 transition-all flex items-center justify-center">
            »»
          </button>
        </div>
      </div>

      <!-- Detail Modal -->
      <Transition
        enter-active-class="transition-all duration-300 ease-out"
        leave-active-class="transition-all duration-200 ease-in"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-if="detailData" class="fixed inset-0 z-50 flex items-start justify-center pt-8 px-4" @click.self="detailData = null">
          <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="detailData = null"></div>
          <div class="relative w-full max-w-3xl max-h-[85vh] overflow-y-auto bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50">
            <!-- Modal header -->
            <div class="sticky top-0 z-10 bg-slate-800/95 backdrop-blur-xl border-b border-white/10 px-6 py-4 flex items-center justify-between">
              <div>
                <h2 class="text-lg font-bold text-white">{{ detailData.template?.name }}</h2>
                <div class="flex items-center gap-3 mt-1">
                  <span class="text-xs text-slate-400">{{ formatDate(detailData.date) }}</span>
                  <span class="text-xs text-slate-600">·</span>
                  <div class="flex items-center gap-1">
                    <div class="w-4 h-4 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white text-[7px] font-bold">
                      {{ getInitials(detailData.outgoing_user?.name) }}
                    </div>
                    <span class="text-xs text-indigo-400">{{ detailData.outgoing_user?.name }}</span>
                  </div>
                  <template v-if="detailData.incoming_user">
                    <span class="text-xs text-slate-600">→</span>
                    <div class="flex items-center gap-1">
                      <div class="w-4 h-4 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-white text-[7px] font-bold">
                        {{ getInitials(detailData.incoming_user?.name) }}
                      </div>
                      <span class="text-xs text-amber-400">{{ detailData.incoming_user?.name }}</span>
                    </div>
                  </template>
                  <span class="text-xs text-slate-600">·</span>
                  <span :class="statusBadgeClass(detailData.status)">{{ statusLabel(detailData.status) }}</span>
                </div>
              </div>
              <button @click="detailData = null" class="text-slate-400 hover:text-white transition-colors p-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>

            <!-- Modal body -->
            <div class="px-6 py-5 space-y-4">
              <!-- Timestamps bar -->
              <div class="flex flex-wrap gap-4 text-xs text-slate-500 bg-white/[0.02] rounded-lg px-4 py-2.5">
                <div v-if="detailData.submitted_at">
                  <span class="text-slate-600">Submitted:</span>
                  <span class="text-slate-400 ml-1">{{ formatDateTime(detailData.submitted_at) }}</span>
                </div>
                <div v-if="detailData.confirmed_at">
                  <span class="text-slate-600">Confirmed:</span>
                  <span class="text-emerald-400/70 ml-1">{{ formatDateTime(detailData.confirmed_at) }}</span>
                </div>
              </div>

              <!-- Groups -->
              <div v-for="(group, gi) in detailData.template?.groups || []" :key="group.id"
                class="bg-white/[0.03] border border-white/10 rounded-xl p-4">
                <h3 class="text-white font-semibold text-sm mb-3 flex items-center gap-2">
                  <span class="w-5 h-5 rounded bg-indigo-500/20 flex items-center justify-center text-[9px] text-indigo-400 font-bold">{{ gi + 1 }}</span>
                  {{ group.name }}
                </h3>
                <div class="space-y-2">
                  <div v-for="item in group.items" :key="item.id" class="flex items-center gap-3 text-sm py-1">
                    <!-- Value indicator -->
                    <div class="shrink-0 w-5 h-5 flex items-center justify-center">
                      <template v-if="item.type === 'checkbox'">
                        <div v-if="getDetailValue(item) === '✓ Yes'"
                          class="w-5 h-5 rounded bg-emerald-500/20 flex items-center justify-center">
                          <svg class="w-3 h-3 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                          </svg>
                        </div>
                        <div v-else class="w-5 h-5 rounded bg-red-500/15 flex items-center justify-center">
                          <svg class="w-3 h-3 text-red-400/70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                          </svg>
                        </div>
                      </template>
                      <template v-else>
                        <div class="w-1.5 h-1.5 rounded-full bg-slate-500"></div>
                      </template>
                    </div>

                    <div class="flex-1 text-slate-300">
                      {{ item.label }}
                      <span v-if="item.requires_confirmation" class="ml-1.5 px-1.5 py-0.5 text-[8px] font-semibold uppercase bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">2-User</span>
                      <button v-if="item.procedure" @click.stop="openProcedureModal(item.procedure.id)"
                        class="inline-flex items-center ml-1.5 px-1.5 py-0.5 text-[9px] font-medium bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 rounded hover:bg-indigo-500/20 transition-all cursor-pointer"
                        :title="`Procedure: ${item.procedure.identifier} — ${item.procedure.title}`">
                        📋 {{ item.procedure.identifier }}
                      </button>
                    </div>

                    <!-- Value display -->
                    <div class="text-right flex items-center gap-1.5">
                      <span v-if="item.type !== 'checkbox'" class="text-indigo-300 text-sm">{{ getDetailRawValue(item) || '—' }}</span>
                      <span v-if="item.requires_confirmation && getDetailConfirmed(item)"
                        class="px-1.5 py-0.5 text-[8px] font-semibold uppercase bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 rounded-full">
                        Confirmed
                      </span>
                      <span v-else-if="item.requires_confirmation && !getDetailConfirmed(item)"
                        class="px-1.5 py-0.5 text-[8px] font-semibold uppercase bg-slate-500/15 text-slate-500 border border-slate-500/20 rounded-full">
                        Not confirmed
                      </span>
                    </div>
                  </div>
                </div>
                <div v-if="getDetailGroupComment(group)" class="mt-3 pt-3 border-t border-white/5">
                  <p class="text-[10px] text-slate-600 uppercase tracking-wider mb-1">Group Comment</p>
                  <p class="text-xs text-slate-400 italic">{{ getDetailGroupComment(group) }}</p>
                </div>
              </div>

              <!-- General Comments -->
              <div v-if="detailData.general_comments" class="bg-white/[0.03] border border-white/10 rounded-xl p-4">
                <h3 class="text-white font-semibold text-sm mb-2">General Comments</h3>
                <p class="text-sm text-slate-400 leading-relaxed">{{ detailData.general_comments }}</p>
              </div>
            </div>
          </div>
        </div>
      </Transition>

      <!-- Procedure Preview Modal (stacks on top of detail modal) -->
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
                <router-link v-if="procedurePreview" :to="`/procedures/${procedurePreview.id}`"
                  class="p-2 text-slate-400 hover:text-violet-400 hover:bg-violet-500/10 rounded-lg transition-all" title="Open full page">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                </router-link>
                <button @click="showProcedureModal = false" class="p-2 text-slate-400 hover:text-white transition-colors">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
            </div>
            <!-- Content -->
            <div v-if="procedurePreview" class="px-6 py-5">
              <ProcedureContent
                ref="procedureContentRef"
                :procedure="procedurePreview"
                :change-reason="procedurePreview.change_reason"
                :publisher="procedurePreview.publisher"
                :published-at="procedurePreview.published_at"
                :viewing-version="procedurePreview.version" />
            </div>
            <div v-else class="flex items-center justify-center py-16">
              <svg class="animate-spin w-6 h-6 text-indigo-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            </div>
          </div>
        </div>
      </Transition>
    </main>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import ProcedureContent from '../components/procedure/ProcedureContent.vue';

const router = useRouter();
const loading = ref(true);
const submissions = ref([]);
const templates = ref([]);
const employees = ref([]);
const detailData = ref(null);
const showProcedureModal = ref(false);
const procedurePreview = ref(null);
const procedureContentRef = ref(null);
const searchQuery = ref('');
const perPage = ref(25);
const currentPage = ref(1);
const sortColumn = ref('date');
const sortDirection = ref('desc');

const filters = reactive({
  date_from: '',
  date_to: '',
  status: '',
  template_id: '',
  user_id: '',
});

// Column definitions
const columns = [
  { key: 'date', label: 'Date', sortable: true },
  { key: 'template', label: 'Template', sortable: true },
  { key: 'outgoing', label: 'Outgoing', sortable: true },
  { key: 'incoming', label: 'Incoming', sortable: true },
  { key: 'status', label: 'Status', sortable: true },
  { key: 'timing', label: 'Timing', sortable: true },
  { key: 'submitted_at', label: 'Submitted', sortable: true },
  { key: 'actions', label: '', sortable: false, align: 'right' },
];

// Stats
const stats = computed(() => {
  const all = submissions.value;
  return {
    total: all.length,
    completed: all.filter(s => s.status === 'completed').length,
    pending: all.filter(s => s.status === 'pending_confirmation').length,
    draft: all.filter(s => s.status === 'draft').length,
  };
});

// Client-side filtering + sorting
const filteredSubmissions = computed(() => {
  let result = [...submissions.value];

  // Search
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase();
    result = result.filter(s =>
      (s.template?.name || '').toLowerCase().includes(q) ||
      (s.outgoing_user?.name || '').toLowerCase().includes(q) ||
      (s.incoming_user?.name || '').toLowerCase().includes(q)
    );
  }

  // Sort
  result.sort((a, b) => {
    let aVal, bVal;
    switch (sortColumn.value) {
      case 'date': aVal = a.date; bVal = b.date; break;
      case 'template': aVal = a.template?.name || ''; bVal = b.template?.name || ''; break;
      case 'outgoing': aVal = a.outgoing_user?.name || ''; bVal = b.outgoing_user?.name || ''; break;
      case 'incoming': aVal = a.incoming_user?.name || ''; bVal = b.incoming_user?.name || ''; break;
      case 'status': aVal = a.status; bVal = b.status; break;
      case 'submitted_at': aVal = a.submitted_at || ''; bVal = b.submitted_at || ''; break;
      default: return 0;
    }
    if (aVal < bVal) return sortDirection.value === 'asc' ? -1 : 1;
    if (aVal > bVal) return sortDirection.value === 'asc' ? 1 : -1;
    return 0;
  });

  return result;
});

// Pagination
const totalPages = computed(() => Math.max(1, Math.ceil(filteredSubmissions.value.length / perPage.value)));

const paginatedSubmissions = computed(() => {
  const start = (currentPage.value - 1) * perPage.value;
  return filteredSubmissions.value.slice(start, start + perPage.value);
});

const visiblePages = computed(() => {
  const total = totalPages.value;
  const current = currentPage.value;
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
  const pages = [];
  pages.push(1);
  if (current > 3) pages.push('...');
  for (let i = Math.max(2, current - 1); i <= Math.min(total - 1, current + 1); i++) {
    pages.push(i);
  }
  if (current < total - 2) pages.push('...');
  pages.push(total);
  return pages;
});

// Reset page when filters/search change
watch([searchQuery, perPage], () => { currentPage.value = 1; });

// Sorting
function toggleSort(key) {
  if (sortColumn.value === key) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortColumn.value = key;
    sortDirection.value = key === 'date' || key === 'submitted_at' ? 'desc' : 'asc';
  }
}

// Fetch data
async function fetchData() {
  loading.value = true;
  try {
    const params = { per_page: 1000 };
    if (filters.date_from) params.date_from = filters.date_from;
    if (filters.date_to) params.date_to = filters.date_to;
    if (filters.status) params.status = filters.status;
    if (filters.template_id) params.template_id = filters.template_id;
    if (filters.user_id) params.user_id = filters.user_id;

    const [subRes, tplRes, empRes] = await Promise.all([
      api.get('/api/checklist-submissions', { params }),
      api.get('/api/checklist-templates'),
      api.get('/api/employees'),
    ]);
    submissions.value = subRes.data.data || subRes.data || [];
    templates.value = tplRes.data || [];
    employees.value = empRes.data || [];
  } catch {
    submissions.value = [];
  } finally {
    loading.value = false;
  }
}

function applyFilters() {
  currentPage.value = 1;
  fetchData();
}

function resetFilters() {
  filters.date_from = '';
  filters.date_to = '';
  filters.status = '';
  filters.template_id = '';
  filters.user_id = '';
  searchQuery.value = '';
  currentPage.value = 1;
  fetchData();
}

// Detail
async function viewDetail(id) {
  try {
    const response = await api.get(`/api/checklist-submissions/${id}`);
    detailData.value = response.data;
  } catch {}
}

// Helpers
function getInitials(name) {
  if (!name) return '?';
  return name.split(' ').map(w => w[0]).join('').toUpperCase().substring(0, 2);
}

function formatDate(dateStr) {
  if (!dateStr) return '—';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function getDayOfWeek(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-US', { weekday: 'short' });
}

function formatDateTime(dt) {
  if (!dt) return '—';
  const d = new Date(dt);
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) + ' ' +
    d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

function statusLabel(status) {
  const labels = { draft: 'Draft', pending_confirmation: 'Pending', completed: 'Completed' };
  return labels[status] || status;
}

function statusBadgeClass(status) {
  const base = 'px-2.5 py-1 text-[10px] font-semibold uppercase rounded-full border';
  const variants = {
    completed: 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20',
    pending_confirmation: 'bg-amber-500/15 text-amber-400 border-amber-500/20',
    draft: 'bg-slate-500/15 text-slate-400 border-slate-500/20',
  };
  return `${base} ${variants[status] || variants.draft}`;
}

// Detail helpers
function getDetailResponse(item) {
  return detailData.value?.responses?.find(r => r.checklist_item_id === item.id);
}

function getDetailValue(item) {
  const r = getDetailResponse(item);
  if (!r) return '—';
  if (item.type === 'checkbox') return r.value === 'true' ? '✓ Yes' : '✗ No';
  return r.value || '—';
}

function getDetailRawValue(item) {
  const r = getDetailResponse(item);
  return r?.value || '';
}

function getDetailConfirmed(item) {
  return getDetailResponse(item)?.confirmed;
}

function getDetailGroupComment(group) {
  if (!group.items?.length) return null;
  return getDetailResponse(group.items[0])?.group_comment || null;
}

// CSV Export
function exportCSV() {
  const rows = [['Date', 'Template', 'Outgoing Employee', 'Incoming Employee', 'Status', 'Submitted At']];
  for (const s of filteredSubmissions.value) {
    rows.push([
      s.date,
      s.template?.name || '',
      s.outgoing_user?.name || '',
      s.incoming_user?.name || '',
      statusLabel(s.status),
      s.submitted_at || '',
    ]);
  }
  const csv = rows.map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `checklist-archive-${new Date().toISOString().split('T')[0]}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

onMounted(fetchData);

async function openProcedureModal(procedureId) {
  showProcedureModal.value = true;
  procedurePreview.value = null;
  try {
    procedurePreview.value = (await api.get(`/api/procedures/${procedureId}`)).data;
    setTimeout(() => procedureContentRef.value?.resetTab(), 0);
  } catch {
    showProcedureModal.value = false;
  }
}

</script>
