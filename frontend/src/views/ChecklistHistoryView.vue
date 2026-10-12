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
          <button @click="exportCSV" v-if="total > 0" :disabled="exporting"
            class="px-4 py-2 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all flex items-center gap-2 disabled:opacity-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            {{ exporting ? 'Exporting…' : 'Export CSV' }}
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
        <p class="text-xs text-slate-500" data-testid="results-count">
          <template v-if="total > 0">
            Showing <span class="text-slate-300 font-medium">{{ rangeStart }}–{{ rangeEnd }}</span> of <span class="text-slate-300 font-medium">{{ total }}</span> results
          </template>
          <template v-else>No results</template>
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

      <!-- Failed to load: say so, not "no submissions" -->
      <div v-else-if="loadError" role="alert" class="text-center py-20">
        <p class="text-red-300 text-sm mb-4">{{ loadError }}</p>
        <button @click="fetchPage"
          class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all">Retry</button>
      </div>

      <!-- Empty -->
      <div v-else-if="submissions.length === 0" class="text-center py-20">
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
              <tr v-for="sub in submissions" :key="sub.id"
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
      <nav v-if="totalPages > 1" aria-label="Pagination" class="flex items-center justify-between mt-4">
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
            <button v-if="p !== '...'" @click="currentPage = p" :aria-label="`Page ${p}`" :aria-current="p === currentPage ? 'page' : undefined"
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
      </nav>

      <!-- Detail Modal -->
      <Transition
        enter-active-class="transition-all duration-300 ease-out"
        leave-active-class="transition-all duration-200 ease-in"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <BaseModal v-if="detailData"
          overlay-class="fixed inset-0 z-50 flex items-start justify-center pt-8 px-4"
          backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
          panel-class="relative w-full max-w-3xl max-h-[85vh] overflow-y-auto bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl shadow-black/50"
          @close="closeDetail"
          v-slot="{ titleId }">
          <!-- Modal header -->
          <div class="sticky top-0 z-10 bg-slate-800/95 backdrop-blur-xl border-b border-white/10 px-6 py-4 flex items-center justify-between">
            <div>
              <h2 :id="titleId" class="text-lg font-bold text-white">{{ detailData.template?.name }}</h2>
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
              <!-- Only when someone (an admin) filed it on the outgoing employee's behalf. -->
              <p v-if="detailData.submitted_by && detailData.submitted_by.id !== detailData.outgoing_user_id"
                class="text-xs text-slate-400 mt-1" data-testid="submitted-by">
                Submitted by <span class="text-slate-300">{{ detailData.submitted_by.name }}</span>
              </p>
            </div>
            <button @click="closeDetail" class="text-slate-400 hover:text-white transition-colors p-1">
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
        </BaseModal>
      </Transition>

      <!-- Procedure Preview Modal (stacks on top of detail modal) -->
      <Transition enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
        enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <BaseModal v-if="showProcedureModal"
          overlay-class="fixed inset-0 z-[70] flex items-start justify-center pt-4 px-4"
          backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
          panel-class="relative w-full max-w-4xl max-h-[90vh] overflow-y-auto bg-slate-800/95 backdrop-blur-xl border border-white/10 rounded-2xl shadow-2xl"
          @close="closeProcedureModal"
          v-slot="{ titleId }">
          <!-- Header -->
          <div class="sticky top-0 z-10 bg-slate-800/95 backdrop-blur-xl border-b border-white/10 px-6 py-4 flex items-center justify-between">
            <div v-if="procedurePreview" class="flex items-center gap-3 min-w-0">
              <span class="px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-indigo-500/15 text-indigo-400 border border-indigo-500/20 rounded-full shrink-0">
                {{ procedurePreview.identifier }}
              </span>
              <h2 :id="titleId" class="text-lg font-bold text-white truncate">{{ procedurePreview.title }}</h2>
              <span class="text-[10px] text-slate-500 tabular-nums shrink-0">v{{ procedurePreview.version }}</span>
            </div>
            <div v-else :id="titleId" class="text-slate-400 text-sm">Loading...</div>
            <div class="flex items-center gap-2 shrink-0 ml-3">
              <router-link v-if="procedurePreview" :to="`/procedures/${procedurePreview.id}`"
                class="p-2 text-slate-400 hover:text-violet-400 hover:bg-violet-500/10 rounded-lg transition-all" title="Open full page">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
              </router-link>
              <button @click="closeProcedureModal" aria-label="Close procedure preview" class="p-2 text-slate-400 hover:text-white transition-colors">
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
        </BaseModal>
      </Transition>
    </main>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { describeApiError, readBlobErrorBody } from '../apiErrors.js';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import BaseModal from '../components/BaseModal.vue';
import ProcedureContent from '../components/procedure/ProcedureContent.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast.js';
import { todayStr } from '../dates.js';

const { showToast } = useToast();

// The archive is paginated, filtered, searched and sorted on the server: only the current page is
// loaded, and the totals and status counts cover every matching handover (no 1000-row cap).
const loading = ref(true);
const loadError = ref('');
const submissions = ref([]);
const total = ref(0);
const lastPage = ref(1);
const statusCounts = ref({ completed: 0, pending_confirmation: 0, draft: 0 });
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
const exporting = ref(false);

const filters = reactive({
  date_from: '',
  date_to: '',
  status: '',
  template_id: '',
  user_id: '',
});
// What Apply last sent: editing the filter inputs changes nothing until Apply, and every page,
// sort and export request carries these.
const appliedFilters = ref({});

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

// Stats (every matching handover, from the server)
const stats = computed(() => ({
  total: total.value,
  completed: statusCounts.value.completed ?? 0,
  pending: statusCounts.value.pending_confirmation ?? 0,
  draft: statusCounts.value.draft ?? 0,
}));

// Pagination
const totalPages = computed(() => Math.max(1, lastPage.value));
const rangeStart = computed(() => (currentPage.value - 1) * perPage.value + 1);
const rangeEnd = computed(() => rangeStart.value + submissions.value.length - 1);

const visiblePages = computed(() => {
  const pageCount = totalPages.value;
  const current = currentPage.value;
  if (pageCount <= 7) return Array.from({ length: pageCount }, (_, i) => i + 1);
  const pages = [];
  pages.push(1);
  if (current > 3) pages.push('...');
  for (let i = Math.max(2, current - 1); i <= Math.min(pageCount - 1, current + 1); i++) {
    pages.push(i);
  }
  if (current < pageCount - 2) pages.push('...');
  pages.push(pageCount);
  return pages;
});

/** Filters, search and sort as API params (empty values left out). */
function queryParams() {
  const params = { ...appliedFilters.value };
  const search = searchQuery.value.trim();
  if (search) params.search = search;
  params.sort = sortColumn.value;
  params.direction = sortDirection.value;
  return params;
}

// Back to page 1 (and reload) when what is listed changes; a page change just loads that page.
function reloadFromFirstPage() {
  if (currentPage.value === 1) fetchPage();
  else currentPage.value = 1;
}

watch(currentPage, () => fetchPage());
watch(perPage, reloadFromFirstPage);

let searchTimer = null;
let skipSearchReload = false; // set by Reset, which reloads itself
watch(searchQuery, () => {
  clearTimeout(searchTimer);
  if (skipSearchReload) {
    skipSearchReload = false;
    return;
  }
  searchTimer = setTimeout(reloadFromFirstPage, 300);
});
onUnmounted(() => clearTimeout(searchTimer));

// Sorting
function toggleSort(key) {
  if (sortColumn.value === key) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
  } else {
    sortColumn.value = key;
    sortDirection.value = key === 'date' || key === 'submitted_at' ? 'desc' : 'asc';
  }
  reloadFromFirstPage();
}

// Apply / Reset / paging overlap fetches (and the unfiltered mount fetch can land after an early
// Apply). Only the latest may apply its rows, show its error or clear `loading`.
const submissionsRequests = useLatestRequest();

async function fetchPage() {
  const token = submissionsRequests.begin();
  loading.value = true;
  try {
    const res = await api.get('/api/checklist-submissions', {
      params: { ...queryParams(), page: currentPage.value, per_page: perPage.value },
    });
    if (!submissionsRequests.isCurrent(token)) return;
    submissions.value = res.data.data ?? [];
    total.value = res.data.total ?? 0;
    lastPage.value = res.data.last_page ?? 1;
    statusCounts.value = res.data.status_counts ?? {};
    loadError.value = '';
    // The list shrank under us (e.g. on the last page): show its new last page.
    if (currentPage.value > lastPage.value) currentPage.value = lastPage.value;
  } catch (err) {
    if (!submissionsRequests.isCurrent(token)) return;
    submissions.value = [];
    loadError.value = describeApiError(err, 'Could not load the archive').message;
  } finally {
    if (submissionsRequests.isCurrent(token)) loading.value = false;
  }
}

// The filter dropdowns' options, once.
async function fetchFilterOptions() {
  try {
    const [tplRes, empRes] = await Promise.all([
      // History filters also offer archived templates and deactivated users.
      api.get('/api/checklist-templates', { params: { include_archived: 1 } }),
      api.get('/api/employees', { params: { include_deactivated: 1 } }),
    ]);
    templates.value = tplRes.data || [];
    employees.value = empRes.data || [];
  } catch (err) {
    const e = describeApiError(err, 'Could not load the filter options');
    if (!e.silent) showToast(e.message, 'error', 6000);
  }
}

function applyFilters() {
  appliedFilters.value = Object.fromEntries(
    Object.entries(filters).filter(([, value]) => value !== '' && value !== null),
  );
  reloadFromFirstPage();
}

function resetFilters() {
  filters.date_from = '';
  filters.date_to = '';
  filters.status = '';
  filters.template_id = '';
  filters.user_id = '';
  appliedFilters.value = {};
  clearTimeout(searchTimer);
  if (searchQuery.value !== '') {
    skipSearchReload = true;
    searchQuery.value = '';
  }
  reloadFromFirstPage();
}

// Detail: clicking a second row before the first detail arrives must show the second.
const detailRequests = useLatestRequest();

async function viewDetail(id) {
  const token = detailRequests.begin();
  try {
    const response = await api.get(`/api/checklist-submissions/${id}`);
    if (detailRequests.isCurrent(token)) detailData.value = response.data;
  } catch {}
}

// Closing invalidates a still-loading detail so it cannot reopen the panel.
function closeDetail() {
  detailRequests.invalidate();
  detailData.value = null;
}

// Helpers
function getInitials(name) {
  if (!name) return '?';
  return name
    .split(' ')
    .map((w) => w[0])
    .join('')
    .toUpperCase()
    .substring(0, 2);
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
  return (
    d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) +
    ' ' +
    d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
  );
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
  return detailData.value?.responses?.find((r) => r.checklist_item_id === item.id);
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

// CSV export: built by the server from every handover matching the current filters, search and
// sort (not just the loaded page), with the same visibility rules as the list.
async function exportCSV() {
  if (exporting.value) return;
  exporting.value = true;
  try {
    const res = await api.get('/api/checklist-submissions/export', {
      params: queryParams(),
      responseType: 'blob',
    });
    const url = URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = url;
    a.download = `checklist-archive-${todayStr()}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  } catch (err) {
    // The error body came back as a Blob too; read it so the server's message can show.
    await readBlobErrorBody(err);
    const e = describeApiError(err, 'Could not export the archive');
    if (!e.silent) showToast(e.message, 'error', 6000);
  } finally {
    exporting.value = false;
  }
}

onMounted(() => {
  fetchFilterOptions();
  fetchPage();
});

// Opening another procedure before the previous preview arrived supersedes it; a stale failure
// must not close the preview the user is now looking at.
const previewRequests = useLatestRequest();

function closeProcedureModal() {
  previewRequests.invalidate();
  showProcedureModal.value = false;
}

async function openProcedureModal(procedureId) {
  const token = previewRequests.begin();
  showProcedureModal.value = true;
  procedurePreview.value = null;
  try {
    const res = await api.get(`/api/procedures/${procedureId}`);
    if (!previewRequests.isCurrent(token)) return;
    procedurePreview.value = res.data;
    setTimeout(() => procedureContentRef.value?.resetTab(), 0);
  } catch {
    if (previewRequests.isCurrent(token)) showProcedureModal.value = false;
  }
}
</script>
