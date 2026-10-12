<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-8">

      <!-- Header -->
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">User Management</h1>
          <p class="text-slate-400 mt-1">Create, edit, and manage system users</p>
        </div>
        <button v-if="currentTab === 'users'" @click="openCreateModal"
          class="px-5 py-2.5 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all duration-200 flex items-center gap-2 text-sm">
          <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
          </svg>
          Add User
        </button>
        <button v-else @click="openCreateGroupModal"
          class="px-5 py-2.5 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all duration-200 flex items-center gap-2 text-sm">
          <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
          </svg>
          New Group
        </button>
      </div>

      <!-- Tabs -->
      <div class="flex gap-1 mb-6 bg-white/[0.03] border border-white/10 rounded-xl p-1 w-fit">
        <button @click="currentTab = 'users'"
          :class="currentTab === 'users' ? 'bg-white/10 text-white shadow-sm' : 'text-slate-400 hover:text-white'"
          class="px-4 py-2 text-sm font-medium rounded-lg transition-all duration-200 flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
          </svg>
          Users
        </button>
        <button @click="currentTab = 'groups'"
          :class="currentTab === 'groups' ? 'bg-white/10 text-white shadow-sm' : 'text-slate-400 hover:text-white'"
          class="px-4 py-2 text-sm font-medium rounded-lg transition-all duration-200 flex items-center gap-2">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
          </svg>
          Groups
          <span v-if="groups.length" class="px-1.5 py-0.5 text-[10px] bg-violet-500/20 text-violet-300 rounded-md leading-none">{{ groups.length }}</span>
        </button>
      </div>

      <!-- ── USERS TAB ── -->
      <div v-if="currentTab === 'users'">
        <label class="flex items-center gap-2 mb-3 text-sm text-slate-400 w-fit cursor-pointer select-none">
          <input v-model="showDeactivated" type="checkbox"
            class="w-4 h-4 rounded border-white/20 bg-white/5 text-violet-500 focus:ring-violet-500/40" />
          Show deactivated
        </label>
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
          <!-- Loading -->
          <div v-if="loading" class="p-12 text-center">
            <svg class="animate-spin w-8 h-8 text-indigo-400 mx-auto" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <p class="text-slate-400 mt-3 text-sm">Loading users...</p>
          </div>

          <!-- Table -->
          <table v-else class="w-full">
            <thead>
              <tr class="border-b border-white/10">
                <th class="text-left text-xs font-medium text-slate-400 uppercase tracking-wider px-6 py-4">Name</th>
                <th class="text-left text-xs font-medium text-slate-400 uppercase tracking-wider px-6 py-4">Email</th>
                <th class="text-left text-xs font-medium text-slate-400 uppercase tracking-wider px-6 py-4 hidden md:table-cell">Created</th>
                <th class="text-right text-xs font-medium text-slate-400 uppercase tracking-wider px-6 py-4">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr v-for="user in visibleUsers" :key="user.id" class="hover:bg-white/5 transition-colors duration-150"
                :class="{ 'opacity-60': user.deactivated_at }">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white font-semibold text-xs shrink-0">
                      {{ getInitials(user.name) }}
                    </div>
                    <div>
                      <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-white font-medium text-sm">{{ user.name }}</span>
                        <span v-if="isCurrentNewbie(user.id)" class="text-yellow-400 text-xs shrink-0" title="Active Newbie">⭐</span>
                        <span v-else-if="hasAnyNewbiePeriod(user.id)" class="text-gray-500 text-xs shrink-0" title="Former Newbie (expired)">⭐</span>
                        <span v-if="canManageSchedule(user)" data-testid="role-badge"
                          :class="isAdmin(user) ? 'bg-amber-500/15 text-amber-400 border-amber-500/20' : 'bg-emerald-500/15 text-emerald-400 border-emerald-500/20'"
                          class="px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider border rounded-full shrink-0">{{ roleLabel(user.role) }}</span>
                        <span v-if="user.deactivated_at" class="px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider bg-slate-500/15 text-slate-300 border border-slate-500/30 rounded-full shrink-0">Deactivated</span>
                      </div>
                      <div v-if="user.groups?.length" class="flex items-center gap-1 mt-1 flex-wrap">
                        <span v-for="group in user.groups" :key="group.id"
                          class="px-1.5 py-0.5 text-[9px] font-mono bg-sky-500/15 text-sky-300 border border-sky-500/20 rounded-md leading-none">
                          {{ group.enum }}
                        </span>
                      </div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 text-slate-300 text-sm">{{ user.email }}</td>
                <td class="px-6 py-4 text-slate-400 text-sm hidden md:table-cell">{{ formatDate(user.created_at) }}</td>
                <td class="px-6 py-4">
                  <div class="flex items-center justify-end gap-1">
                    <!-- A deactivated user can only be reactivated. -->
                    <template v-if="!user.deactivated_at">
                    <button @click="openEditModal(user)" title="Edit"
                      class="p-2 text-slate-400 hover:text-indigo-400 hover:bg-indigo-500/10 rounded-lg transition-all duration-200">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                      </svg>
                    </button>
                    <button @click="openPasswordModal(user)" title="Change Password"
                      class="p-2 text-slate-400 hover:text-amber-400 hover:bg-amber-500/10 rounded-lg transition-all duration-200">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                      </svg>
                    </button>
                    <button @click="openDelegationModal(user)" title="Delegation"
                      class="p-2 text-slate-400 hover:text-purple-400 hover:bg-purple-500/10 rounded-lg transition-all duration-200">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                      </svg>
                    </button>
                    <button @click="openNewbieModal(user)" title="Mark as Newbie"
                      class="p-2 text-slate-400 hover:text-yellow-400 hover:bg-yellow-500/10 rounded-lg transition-all duration-200">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                      </svg>
                    </button>
                    </template>
                    <button v-if="user.deactivated_at" @click="reactivateUser(user)" :disabled="reactivatingId === user.id"
                      title="Reactivate"
                      class="px-2.5 py-1.5 text-xs font-medium text-emerald-400 hover:text-emerald-300 bg-emerald-500/10 hover:bg-emerald-500/20 border border-emerald-500/20 rounded-lg transition-all duration-200 disabled:opacity-50">
                      Reactivate
                    </button>
                    <button v-else @click="confirmDeactivate(user)" title="Deactivate" aria-label="Deactivate"
                      class="p-2 text-slate-400 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-all duration-200">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="visibleUsers.length === 0">
                <td colspan="4" class="px-6 py-12 text-center text-slate-500 text-sm">No users found.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ── GROUPS TAB ── -->
      <div v-if="currentTab === 'groups'" :class="selectedGroup ? 'grid lg:grid-cols-5 gap-6' : ''">

        <!-- Groups List -->
        <div :class="selectedGroup ? 'lg:col-span-2' : ''">
          <div class="bg-white/5 border border-white/10 rounded-2xl overflow-hidden">

            <!-- Loading -->
            <div v-if="groupsLoading" class="p-10 text-center">
              <svg class="animate-spin w-6 h-6 text-indigo-400 mx-auto" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
            </div>

            <!-- Empty state -->
            <div v-else-if="groups.length === 0" class="py-14 px-6 text-center">
              <div class="w-12 h-12 rounded-2xl bg-white/5 flex items-center justify-center mx-auto mb-4">
                <svg class="w-6 h-6 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </div>
              <p class="text-slate-400 text-sm font-medium">No groups yet</p>
              <p class="text-slate-500 text-xs mt-1">Create your first group to start organising users.</p>
              <button @click="openCreateGroupModal"
                class="mt-4 px-4 py-2 text-sm font-medium text-violet-300 bg-violet-500/10 hover:bg-violet-500/20 border border-violet-500/20 rounded-xl transition-all duration-200">
                New Group
              </button>
            </div>

            <!-- Groups list -->
            <div v-else class="divide-y divide-white/5">
              <button v-for="group in groups" :key="group.id"
                @click="selectGroup(group)"
                class="w-full flex items-center gap-3 px-4 py-3.5 hover:bg-white/[0.04] transition-all duration-150 text-left group/item border-l-2"
                :class="selectedGroup?.id === group.id ? 'border-violet-500 bg-white/[0.06]' : 'border-transparent'">
                <!-- Icon -->
                <div class="w-8 h-8 rounded-lg bg-violet-500/15 flex items-center justify-center shrink-0">
                  <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                </div>
                <!-- Name + enum -->
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-white truncate">{{ group.name }}</p>
                  <p class="text-[10px] font-mono text-sky-400 truncate">{{ group.enum }}</p>
                </div>
                <!-- Member count -->
                <span class="text-xs text-slate-500 shrink-0">{{ group.users?.length ?? 0 }}</span>
                <!-- Actions -->
                <div class="flex items-center gap-0.5 shrink-0 opacity-0 group-hover/item:opacity-100 transition-opacity duration-150">
                  <button type="button" @click.stop="openEditGroupModal(group)" title="Edit group"
                    class="p-1.5 text-slate-400 hover:text-indigo-400 hover:bg-indigo-500/10 rounded-md transition-all duration-150">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </button>
                  <button type="button" @click.stop="confirmDeleteGroup(group)" title="Delete group"
                    class="p-1.5 text-slate-400 hover:text-red-400 hover:bg-red-500/10 rounded-md transition-all duration-150">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </button>
            </div>
          </div>
        </div>

        <!-- Members Panel -->
        <div v-if="selectedGroup" class="lg:col-span-3">
          <div class="bg-white/5 border border-white/10 rounded-2xl overflow-hidden flex flex-col" style="max-height: calc(100vh - 280px);">

            <!-- Panel header -->
            <div class="px-5 py-4 border-b border-white/10 flex items-center justify-between shrink-0">
              <div>
                <h3 class="text-white font-semibold text-base">{{ selectedGroup.name }}</h3>
                <span class="text-[10px] font-mono text-sky-400">{{ selectedGroup.enum }}</span>
              </div>
              <div class="flex items-center gap-3">
                <span class="px-2.5 py-1 bg-violet-500/15 text-violet-300 text-xs border border-violet-500/20 rounded-full">
                  {{ selectedGroup.users?.length ?? 0 }} members
                </span>
                <button @click="selectedGroup = null" title="Close panel"
                  class="p-1.5 text-slate-400 hover:text-white hover:bg-white/10 rounded-lg transition-all duration-200">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
            </div>

            <!-- Search -->
            <div class="px-4 py-3 border-b border-white/10 shrink-0">
              <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input v-model="memberSearch" type="text" placeholder="Search users..."
                  class="w-full pl-9 pr-3 py-2 bg-white/5 border border-white/10 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 transition-all duration-200" />
              </div>
            </div>

            <!-- Users list -->
            <div class="overflow-y-auto flex-1 divide-y divide-white/5">
              <div v-if="filteredGroupUsers.length === 0" class="py-10 text-center text-slate-500 text-sm">
                No users match your search.
              </div>
              <div v-for="user in filteredGroupUsers" :key="user.id"
                class="flex items-center gap-3 px-4 py-3 hover:bg-white/[0.03] transition-colors duration-150">
                <!-- Avatar -->
                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white font-semibold text-xs shrink-0">
                  {{ getInitials(user.name) }}
                </div>
                <!-- Name + email -->
                <div class="flex-1 min-w-0">
                  <p class="text-sm text-white font-medium truncate">{{ user.name }}</p>
                  <p class="text-xs text-slate-500 truncate">{{ user.email }}</p>
                </div>
                <!-- Member badge / Toggle -->
                <button
                  @click="toggleMember(user.id)"
                  :disabled="togglingUserId !== null"
                  class="shrink-0 px-3 py-1.5 text-xs font-medium rounded-lg transition-all duration-200 flex items-center gap-1.5 disabled:opacity-50 disabled:cursor-not-allowed"
                  :class="user._isMember
                    ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 hover:bg-red-500/15 hover:text-red-400 hover:border-red-500/20'
                    : 'bg-white/5 text-slate-400 border border-white/10 hover:bg-violet-500/15 hover:text-violet-300 hover:border-violet-500/20'">
                  <svg v-if="togglingUserId === user.id" class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                  </svg>
                  <template v-else>
                    <svg v-if="user._isMember" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                    </svg>
                    <svg v-else class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                  </template>
                  {{ user._isMember ? 'Member' : 'Add' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Hint when no group selected and groups exist -->
        <div v-if="!selectedGroup && groups.length > 0"
          class="mt-4 text-center text-slate-600 text-sm">
          Select a group to manage its members.
        </div>
      </div>

    </main>

    <!-- ── MODALS ── -->

    <!-- Create/Edit User Modal -->
    <BaseModal v-if="showUserModal"
      overlay-class="fixed inset-0 z-50 flex items-center justify-center p-4"
      backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
      panel-class="relative bg-slate-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-md p-6"
      :busy="saving"
      @close="closeUserModal"
      v-slot="{ titleId }">
      <div class="flex items-center justify-between mb-6">
        <h2 :id="titleId" class="text-lg font-semibold text-white">{{ editingUser ? 'Edit User' : 'Create New User' }}</h2>
        <button @click="closeUserModal" aria-label="Close" class="text-slate-400 hover:text-white transition-colors">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <form @submit.prevent="saveUser" class="space-y-4">
        <div v-if="formError" class="bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3">
          <span class="text-red-300 text-sm">{{ formError }}</span>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1.5">Full Name</label>
          <input v-model="userForm.name" type="text" required
            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all duration-200 text-sm"
            placeholder="John Doe" />
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1.5">Email Address</label>
          <input v-model="userForm.email" type="email" required
            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all duration-200 text-sm"
            placeholder="john@example.com" />
        </div>

        <template v-if="!editingUser">
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Password</label>
            <input v-model="userForm.password" type="password" required minlength="8"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all duration-200 text-sm"
              placeholder="Minimum 8 characters" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Confirm Password</label>
            <input v-model="userForm.password_confirmation" type="password" required minlength="8"
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all duration-200 text-sm"
              placeholder="Re-enter password" />
          </div>
        </template>

        <div>
          <label for="user-role" class="block text-sm font-medium text-slate-300 mb-1.5">Role</label>
          <!-- An admin can't change their own role (the server refuses it too). -->
          <select id="user-role" v-model="userForm.role" :disabled="editingOwnAccount"
            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all duration-200 text-sm disabled:opacity-50 disabled:cursor-not-allowed">
            <option v-for="option in ROLE_OPTIONS" :key="option.value" :value="option.value" class="bg-slate-800">{{ option.label }}</option>
          </select>
          <p class="mt-1 text-xs text-slate-500">{{ editingOwnAccount ? 'You cannot change your own role.' : roleHint }}</p>
        </div>

        <div class="flex items-center gap-3 pt-2">
          <button type="button" @click="closeUserModal"
            class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all duration-200">
            Cancel
          </button>
          <button type="submit" :disabled="saving"
            class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-violet-600 hover:bg-violet-700 rounded-xl shadow-lg shadow-violet-500/25 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
            <svg v-if="saving" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            {{ saving ? 'Saving...' : (editingUser ? 'Update' : 'Create') }}
          </button>
        </div>
      </form>
    </BaseModal>

    <!-- Password Change Modal -->
    <BaseModal v-if="showPasswordModal"
      overlay-class="fixed inset-0 z-50 flex items-center justify-center p-4"
      backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
      panel-class="relative bg-slate-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-md p-6"
      :busy="savingPassword"
      @close="closePasswordModal"
      v-slot="{ titleId }">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h2 :id="titleId" class="text-lg font-semibold text-white">Change Password</h2>
          <p class="text-slate-400 text-sm mt-1">For: {{ passwordUser?.name }}</p>
        </div>
        <button @click="closePasswordModal" aria-label="Close" class="text-slate-400 hover:text-white transition-colors">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <form @submit.prevent="changePassword" class="space-y-4">
        <div v-if="passwordError" class="bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3">
          <span class="text-red-300 text-sm">{{ passwordError }}</span>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1.5">New Password</label>
          <input v-model="passwordForm.password" type="password" required minlength="8"
            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all duration-200 text-sm"
            placeholder="Minimum 8 characters" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-1.5">Confirm New Password</label>
          <input v-model="passwordForm.password_confirmation" type="password" required minlength="8"
            class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all duration-200 text-sm"
            placeholder="Re-enter new password" />
        </div>

        <div class="flex items-center gap-3 pt-2">
          <button type="button" @click="closePasswordModal"
            class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all duration-200">
            Cancel
          </button>
          <button type="submit" :disabled="savingPassword"
            class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-violet-600 hover:bg-violet-700 rounded-xl shadow-lg shadow-violet-500/25 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
            <svg v-if="savingPassword" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            {{ savingPassword ? 'Changing...' : 'Change Password' }}
          </button>
        </div>
      </form>
    </BaseModal>

    <!-- Deactivate User Modal -->
    <BaseModal v-if="showDeactivateModal"
      role="alertdialog"
      described
      overlay-class="fixed inset-0 z-50 flex items-center justify-center p-4"
      backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
      panel-class="relative bg-slate-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-sm p-6"
      :busy="deactivating"
      @close="closeDeactivateModal"
      v-slot="{ titleId, descriptionId }">
      <div class="text-center mb-6">
        <div class="w-12 h-12 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-4">
          <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
        </div>
        <h2 :id="titleId" class="text-lg font-semibold text-white mb-2">Deactivate User</h2>
        <p :id="descriptionId" class="text-slate-400 text-sm">Deactivate <span class="text-white font-medium">{{ deactivateTarget?.name }}</span>? They will no longer be able to log in or be picked for shifts. Their history (shifts worked, handovers, procedures they own) is kept, and they can be reactivated later.</p>
      </div>

      <div v-if="futureWork === null" class="mb-4 text-center text-slate-500 text-sm">Checking upcoming shifts…</div>
      <label v-else-if="futureWorkTotal > 0" class="mb-4 flex items-start gap-2 text-sm text-slate-300 cursor-pointer select-none">
        <input v-model="removeFutureAssignments" type="checkbox"
          class="mt-0.5 w-4 h-4 rounded border-white/20 bg-white/5 text-violet-500 focus:ring-violet-500/40" />
        <span>Also remove them from these {{ futureWorkLabel }} from tomorrow on</span>
      </label>
      <p v-else class="mb-4 text-center text-slate-500 text-sm">They have no shifts or on-call duties from tomorrow on.</p>

      <div v-if="deactivateError" class="mb-4 bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3">
        <span class="text-red-300 text-sm">{{ deactivateError }}</span>
      </div>

      <div class="flex items-center gap-3">
        <button @click="closeDeactivateModal"
          class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all duration-200">
          Cancel
        </button>
        <button @click="deactivateUserConfirmed" :disabled="deactivating || futureWork === null"
          class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl transition-all duration-200 disabled:opacity-50 flex items-center justify-center gap-2">
          <svg v-if="deactivating" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
          </svg>
          {{ deactivating ? 'Deactivating...' : 'Deactivate' }}
        </button>
      </div>
    </BaseModal>

    <!-- Create/Edit Group Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100"
      leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
      <BaseModal v-if="showGroupModal"
        overlay-class="fixed inset-0 z-50 flex items-center justify-center p-4"
        backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
        panel-class="relative bg-slate-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-sm p-6"
        :busy="savingGroup"
        @close="closeGroupModal"
        v-slot="{ titleId }">
        <div class="flex items-center justify-between mb-5">
          <h2 :id="titleId" class="text-base font-semibold text-white">{{ editingGroup ? 'Edit Group' : 'New Group' }}</h2>
          <button @click="closeGroupModal" aria-label="Close" class="text-slate-400 hover:text-white transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form @submit.prevent="saveGroup" class="space-y-4">
          <div v-if="groupFormError" class="bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3">
            <span class="text-red-300 text-sm">{{ groupFormError }}</span>
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Group Name</label>
            <input v-model="groupForm.name" type="text" required
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 transition-all duration-200 text-sm"
              placeholder="e.g. Operators" />
          </div>

          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1">
              Enum
              <span class="text-slate-500 font-normal ml-1">(URL suffix)</span>
            </label>
            <input v-model="groupForm.enum" type="text" required
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500/50 transition-all duration-200 text-sm font-mono"
              placeholder="e.g. operators" />
            <p class="text-xs text-slate-500 mt-1.5">Lowercase letters, numbers, hyphens and underscores only.</p>
          </div>

          <div class="flex items-center gap-3 pt-1">
            <button type="button" @click="closeGroupModal"
              class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all duration-200">
              Cancel
            </button>
            <button type="submit" :disabled="savingGroup"
              class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-violet-600 hover:bg-violet-700 rounded-xl shadow-lg shadow-violet-500/25 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
              <svg v-if="savingGroup" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
              </svg>
              {{ savingGroup ? 'Saving...' : (editingGroup ? 'Update' : 'Create') }}
            </button>
          </div>
        </form>
      </BaseModal>
    </Transition>

    <!-- Delete Group Modal -->
    <BaseModal v-if="showGroupDeleteModal"
      role="alertdialog"
      described
      overlay-class="fixed inset-0 z-50 flex items-center justify-center p-4"
      backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
      panel-class="relative bg-slate-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-sm p-6"
      :busy="deletingGroupLoading"
      @close="showGroupDeleteModal = false"
      v-slot="{ titleId, descriptionId }">
      <div class="text-center mb-6">
        <div class="w-12 h-12 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-4">
          <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
        </div>
        <h2 :id="titleId" class="text-lg font-semibold text-white mb-2">Delete Group</h2>
        <p :id="descriptionId" class="text-slate-400 text-sm">Delete <span class="text-white font-medium">{{ deletingGroup?.name }}</span>? All member assignments will be removed.</p>
      </div>
      <div class="flex items-center gap-3">
        <button @click="showGroupDeleteModal = false"
          class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all duration-200">
          Cancel
        </button>
        <button @click="deleteGroupConfirmed" :disabled="deletingGroupLoading"
          class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl transition-all duration-200 disabled:opacity-50 flex items-center justify-center gap-2">
          <svg v-if="deletingGroupLoading" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
          </svg>
          {{ deletingGroupLoading ? 'Deleting...' : 'Delete' }}
        </button>
      </div>
    </BaseModal>

    <AppToast />

    <!-- Delegation Modal -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0">
      <BaseModal v-if="showDelegationModal"
        overlay-class="fixed inset-0 z-50 flex items-center justify-center p-4"
        backdrop-class="fixed inset-0 bg-black/60 backdrop-blur-sm"
        panel-class="relative bg-slate-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto"
        :busy="savingDelegation || assigningDelegationId !== null"
        @close="closeDelegationModal"
        v-slot="{ titleId }">
        <div class="flex items-center justify-between mb-4">
          <h2 :id="titleId" class="text-white font-bold text-lg flex items-center gap-2">
            <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            Delegation — {{ delegationUser?.name }}
          </h2>
          <button @click="closeDelegationModal" aria-label="Close" class="p-1 text-slate-400 hover:text-white transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>

        <div class="flex gap-1 mb-5 bg-white/5 rounded-xl p-1">
          <button @click="delegationMode = 'create'"
            :class="['flex-1 px-3 py-2 text-sm font-medium rounded-lg transition-all',
              delegationMode === 'create' ? 'bg-purple-500/20 text-purple-300 shadow-sm' : 'text-slate-400 hover:text-white']">
            Create New
          </button>
          <button @click="delegationMode = 'assign'"
            :class="['flex-1 px-3 py-2 text-sm font-medium rounded-lg transition-all',
              delegationMode === 'assign' ? 'bg-purple-500/20 text-purple-300 shadow-sm' : 'text-slate-400 hover:text-white']">
            Assign Existing
          </button>
        </div>

        <p v-if="delegationError" class="text-red-400 text-sm mb-3">{{ delegationError }}</p>

        <!-- v-show, not v-if: a look at "Assign Existing" must not lose what was typed here. -->
        <DelegationForm v-show="delegationMode === 'create'"
          :initial="delegationInitial"
          :saving="savingDelegation"
          :server-errors="delegationFieldErrors"
          :show-details="false"
          submit-label="Create & Assign"
          @submit="createDelegation"
          @cancel="closeDelegationModal" />

        <div v-if="delegationMode === 'assign'" class="space-y-4">
          <div v-if="allDelegations.length === 0" class="text-center py-8 text-slate-500 text-sm">No delegations exist yet. Create one first.</div>
          <div v-else class="space-y-2 max-h-[300px] overflow-y-auto">
            <div v-for="del in allDelegations" :key="del.id"
              class="flex items-center justify-between p-3 bg-white/5 border border-white/10 rounded-xl hover:border-purple-500/30 transition-all">
              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-white truncate">{{ del.name }}</p>
                <p class="text-xs text-slate-400">📍 {{ del.destination }}</p>
                <p class="text-[10px] text-slate-500">{{ formatCalendarDate(del.start_datetime) }} → {{ formatCalendarDate(del.end_datetime) }}</p>
                <div v-if="del.users?.length" class="flex items-center gap-1 mt-1 flex-wrap">
                  <span v-for="u in del.users" :key="u.id"
                    class="px-1.5 py-0.5 text-[9px] bg-purple-500/15 text-purple-300 rounded-md">{{ u.name }}</span>
                </div>
              </div>
              <button @click="assignToExisting(del)"
                :disabled="del.users?.some(u => u.id === delegationUser?.id) || assigningDelegationId !== null"
                :class="['px-3 py-1.5 text-xs font-medium rounded-lg transition-all shrink-0 ml-3',
                  del.users?.some(u => u.id === delegationUser?.id)
                    ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 cursor-default'
                    : 'bg-purple-500/15 text-purple-300 border border-purple-500/20 hover:bg-purple-500/25']">
                {{ del.users?.some(u => u.id === delegationUser?.id) ? '✓ Assigned' : 'Assign' }}
              </button>
            </div>
          </div>
        </div>
      </BaseModal>
    </Transition>

    <!-- Newbie Period Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <BaseModal v-if="showNewbieModal"
        overlay-class="fixed inset-0 z-50 flex items-center justify-center px-4"
        backdrop-class="fixed inset-0 bg-black/50 backdrop-blur-sm"
        panel-class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-yellow-500/25 rounded-2xl shadow-2xl shadow-yellow-500/10 p-6"
        :busy="savingNewbie"
        @close="closeNewbieModal()"
        v-slot="{ titleId }">
        <div class="flex items-center gap-3 mb-5">
          <div class="w-10 h-10 rounded-xl bg-yellow-500/15 flex items-center justify-center text-yellow-400 text-lg">⭐</div>
          <div>
            <h3 :id="titleId" class="text-white font-bold text-lg">Newbie Period</h3>
            <p class="text-slate-400 text-sm">{{ newbieUser?.name }}</p>
          </div>
        </div>

        <div v-if="userNewbiePeriods.length" class="mb-4 space-y-1.5">
          <p class="text-xs text-slate-500 uppercase tracking-wider font-semibold mb-2">Current periods</p>
          <div v-for="p in userNewbiePeriods" :key="p.id"
            class="flex items-center justify-between px-3 py-2 bg-yellow-500/8 border border-yellow-500/15 rounded-lg">
            <span class="text-sm text-yellow-300">{{ p.start_date?.substring(0,10) }} → {{ p.end_date?.substring(0,10) }}</span>
            <button @click="deleteNewbiePeriod(p)" class="text-slate-500 hover:text-red-400 text-xs px-2 py-1 transition-colors">✕</button>
          </div>
        </div>

        <form @submit.prevent="createNewbiePeriod" class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs text-slate-500 uppercase tracking-wide mb-1">Start Date</label>
              <input v-model="newbieForm.start_date" type="date" required
                class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500/50" />
            </div>
            <div>
              <label class="block text-xs text-slate-500 uppercase tracking-wide mb-1">End Date</label>
              <input v-model="newbieForm.end_date" type="date" required :min="newbieForm.start_date"
                class="w-full px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-yellow-500/50" />
            </div>
          </div>
          <div class="flex items-center justify-end gap-3">
            <button type="button" @click="closeNewbieModal()" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Close</button>
            <button type="submit" :disabled="savingNewbie || !newbieForm.start_date || !newbieForm.end_date"
              class="px-5 py-2 bg-gradient-to-r from-yellow-500 to-amber-500 hover:from-yellow-600 hover:to-amber-600 text-white font-semibold rounded-xl shadow-lg shadow-yellow-500/25 transition-all text-sm disabled:opacity-50">Add Period</button>
          </div>
        </form>
      </BaseModal>
    </Transition>
  </AppLayout>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { describeApiError } from '../apiErrors.js';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AppToast from '../components/AppToast.vue';
import BaseModal from '../components/BaseModal.vue';
import DelegationForm from '../components/DelegationForm.vue';
import { useLatestRequest } from '../composables/useLatestRequest.js';
import { useToast } from '../composables/useToast.js';
import { formatCalendarDate, todayStr } from '../dates.js';
import { canManageSchedule, isAdmin, ROLE_OPTIONS, roleLabel } from '../roles.js';
import { currentUser } from '../router/index.js';

const { showToast } = useToast();

// ── Tab ──────────────────────────────────────────────
const currentTab = ref('users');

// ── Users ─────────────────────────────────────────────
const users = ref([]);
const loading = ref(true);

const showUserModal = ref(false);
const editingUser = ref(null);
const saving = ref(false);
const formError = ref('');
const userForm = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  role: 'employee',
});

const ROLE_HINTS = {
  admin: 'Manages users, settings, procedures and the schedule.',
  schedule_maintainer: 'Manages the schedule.',
  employee: 'Fills checklists and sees their own shifts.',
};
const roleHint = computed(() => ROLE_HINTS[userForm.role] ?? '');
const editingOwnAccount = computed(() => editingUser.value?.id === currentUser.value?.id);

const showPasswordModal = ref(false);
const passwordUser = ref(null);
const savingPassword = ref(false);
const passwordError = ref('');
const passwordForm = reactive({ password: '', password_confirmation: '' });

// Users are deactivated, never deleted; deactivated ones are hidden unless asked for.
const showDeactivated = ref(false);
const visibleUsers = computed(() =>
  showDeactivated.value ? users.value : users.value.filter((u) => !u.deactivated_at),
);
const activeUsers = computed(() => users.value.filter((u) => !u.deactivated_at));

const showDeactivateModal = ref(false);
const deactivateTarget = ref(null);
const deactivating = ref(false);
const deactivateError = ref('');
// Shifts and on-call duties the user has from tomorrow on (today's may already be worked and stay).
const futureWork = ref(null); // { shifts, onCall }; null while loading
const futureWorkTotal = computed(() =>
  futureWork.value ? futureWork.value.shifts + futureWork.value.onCall : 0,
);
const futureWorkLabel = computed(() => {
  if (!futureWork.value) return '';
  const { shifts, onCall } = futureWork.value;
  const parts = [];
  if (shifts) parts.push(`${shifts} shift${shifts === 1 ? '' : 's'}`);
  if (onCall) parts.push(`${onCall} on-call dut${onCall === 1 ? 'y' : 'ies'}`);
  return parts.join(' and ');
});
const removeFutureAssignments = ref(true);
const reactivatingId = ref(null);

// ── Groups ────────────────────────────────────────────
const groups = ref([]);
const groupsLoading = ref(false);
const selectedGroup = ref(null);
const togglingUserId = ref(null);
const memberSearch = ref('');

const showGroupModal = ref(false);
const editingGroup = ref(null);
const savingGroup = ref(false);
const groupFormError = ref('');
const groupForm = reactive({ name: '', enum: '' });

const showGroupDeleteModal = ref(false);
const deletingGroup = ref(null);
const deletingGroupLoading = ref(false);
const groupsLoaded = ref(false);

// ── Delegation ─────────────────────────────────────────
const showDelegationModal = ref(false);
const delegationUser = ref(null);
const delegationMode = ref('create');
const savingDelegation = ref(false);
const delegationError = ref('');
const allDelegations = ref([]);
const delegationInitial = ref({});
const delegationFieldErrors = ref({});
const assigningDelegationId = ref(null);

// ── Newbie ─────────────────────────────────────────────
const showNewbieModal = ref(false);
const newbieUser = ref(null);
const savingNewbie = ref(false);
const userNewbiePeriods = ref([]);
const newbieForm = reactive({ start_date: '', end_date: '' });
const allNewbiePeriods = ref([]);

// ── Computed ───────────────────────────────────────────
const filteredGroupUsers = computed(() => {
  if (!selectedGroup.value) return [];
  const q = memberSearch.value.toLowerCase();
  const memberIds = new Set(selectedGroup.value.users?.map((u) => u.id) ?? []);
  const list = q
    ? activeUsers.value.filter(
        (u) => u.name.toLowerCase().includes(q) || (u.email ?? '').toLowerCase().includes(q),
      )
    : activeUsers.value;
  return [...list]
    .sort((a, b) => a.name.localeCompare(b.name))
    .map((u) => ({ ...u, _isMember: memberIds.has(u.id) }));
});

// ── Fetch ──────────────────────────────────────────────
async function fetchUsers() {
  loading.value = true;
  try {
    const res = await api.get('/api/users', { params: { include_deactivated: 1 } });
    users.value = res.data;
  } catch {
    showToast('Failed to load users. Please refresh.', 'error');
  } finally {
    loading.value = false;
  }
}

async function fetchGroups() {
  groupsLoading.value = true;
  try {
    const res = await api.get('/api/user-groups');
    groups.value = res.data;
    if (selectedGroup.value) {
      selectedGroup.value = groups.value.find((g) => g.id === selectedGroup.value.id) ?? null;
    }
  } catch {
    showToast('Failed to load groups. Please refresh.', 'error');
  } finally {
    groupsLoading.value = false;
  }
}

async function fetchAllNewbiePeriods() {
  try {
    const res = await api.get('/api/newbie-periods/all');
    allNewbiePeriods.value = res.data;
  } catch {
    allNewbiePeriods.value = [];
  }
}

onMounted(() => {
  fetchUsers();
  fetchAllNewbiePeriods();
});

watch(currentTab, (tab) => {
  if (tab === 'groups' && !groupsLoaded.value) {
    groupsLoaded.value = true;
    fetchGroups();
  }
});

// ── Helpers ────────────────────────────────────────────
function getInitials(name) {
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
}

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

function isCurrentNewbie(userId) {
  const today = todayStr();
  return allNewbiePeriods.value.some((np) => {
    const start = np.start_date?.substring(0, 10);
    const end = np.end_date?.substring(0, 10);
    return np.user_id === userId && today >= start && today <= end;
  });
}

function hasAnyNewbiePeriod(userId) {
  return allNewbiePeriods.value.some((np) => np.user_id === userId);
}

// ── User CRUD ──────────────────────────────────────────
function openCreateModal() {
  editingUser.value = null;
  userForm.name = '';
  userForm.email = '';
  userForm.password = '';
  userForm.password_confirmation = '';
  userForm.role = 'employee';
  formError.value = '';
  showUserModal.value = true;
}

function openEditModal(user) {
  editingUser.value = user;
  userForm.name = user.name;
  userForm.email = user.email;
  userForm.password = '';
  userForm.password_confirmation = '';
  userForm.role = user.role ?? 'employee';
  formError.value = '';
  showUserModal.value = true;
}

function closeUserModal() {
  showUserModal.value = false;
  editingUser.value = null;
}

async function saveUser() {
  if (saving.value) return;
  saving.value = true;
  formError.value = '';
  try {
    if (editingUser.value) {
      await api.put(`/api/users/${editingUser.value.id}`, {
        name: userForm.name,
        email: userForm.email,
        role: userForm.role,
      });
      showToast(`User "${userForm.name}" updated successfully.`);
    } else {
      await api.post('/api/users', {
        name: userForm.name,
        email: userForm.email,
        password: userForm.password,
        password_confirmation: userForm.password_confirmation,
        role: userForm.role,
      });
      showToast(`User "${userForm.name}" created successfully.`);
    }
    closeUserModal();
    await fetchUsers();
  } catch (err) {
    const data = err.response?.data;
    formError.value = data?.errors
      ? Object.values(data.errors).flat().join(' ')
      : data?.message || 'An error occurred.';
  } finally {
    saving.value = false;
  }
}

function openPasswordModal(user) {
  passwordUser.value = user;
  passwordForm.password = '';
  passwordForm.password_confirmation = '';
  passwordError.value = '';
  showPasswordModal.value = true;
}

function closePasswordModal() {
  showPasswordModal.value = false;
  passwordUser.value = null;
}

// Cancel stays usable while a save runs, so the dialog may be closed or reopened for another user
// before the response arrives: results only touch the dialog if it still shows that user.
function passwordDialogIsFor(user) {
  return showPasswordModal.value && passwordUser.value === user;
}

async function changePassword() {
  // Captured before the request: the dialog's state must not be read after the await.
  const user = passwordUser.value;
  savingPassword.value = true;
  passwordError.value = '';
  try {
    await api.put(`/api/users/${user.id}/password`, {
      password: passwordForm.password,
      password_confirmation: passwordForm.password_confirmation,
    });
    showToast(`Password for "${user.name}" changed successfully.`);
    if (passwordDialogIsFor(user)) closePasswordModal();
  } catch (err) {
    const data = err.response?.data;
    const message = data?.errors
      ? Object.values(data.errors).flat().join(' ')
      : data?.message || 'An error occurred.';
    // Closed (or reopened for someone else) while the request ran: say so in a toast rather than
    // leave the error on a form that is no longer this request's.
    if (passwordDialogIsFor(user)) passwordError.value = message;
    else showToast(`Password for "${user.name}" was not changed: ${message}`, 'error');
  } finally {
    savingPassword.value = false;
  }
}

// Opening the dialog for another user before the count arrived supersedes the earlier load.
const futureCountRequests = useLatestRequest();

async function confirmDeactivate(user) {
  const token = futureCountRequests.begin();
  deactivateTarget.value = user;
  deactivateError.value = '';
  futureWork.value = null;
  removeFutureAssignments.value = true;
  showDeactivateModal.value = true;
  try {
    const res = await api.get(`/api/users/${user.id}`);
    if (futureCountRequests.isCurrent(token)) {
      futureWork.value = {
        shifts: res.data.future_assignments_count ?? 0,
        onCall: res.data.future_on_call_count ?? 0,
      };
    }
  } catch {
    if (!futureCountRequests.isCurrent(token)) return;
    futureWork.value = { shifts: 0, onCall: 0 };
    deactivateError.value = 'Could not check their upcoming shifts; none will be removed.';
  }
}

function closeDeactivateModal() {
  futureCountRequests.invalidate();
  showDeactivateModal.value = false;
  deactivateTarget.value = null;
}

// As for the password dialog: Cancel works mid-request, so results check the dialog still shows
// this user before touching it.
function deactivateDialogIsFor(user) {
  return showDeactivateModal.value && deactivateTarget.value === user;
}

async function deactivateUserConfirmed() {
  // Captured before the request: the dialog's state must not be read after the await.
  const target = deactivateTarget.value;
  deactivating.value = true;
  deactivateError.value = '';
  try {
    await api.post(`/api/users/${target.id}/deactivate`, {
      remove_future_assignments: futureWorkTotal.value > 0 && removeFutureAssignments.value,
    });
    showToast(`User "${target.name}" deactivated.`);
    if (deactivateDialogIsFor(target)) closeDeactivateModal();
    await fetchUsers();
  } catch (err) {
    const message = err.response?.data?.message || 'An error occurred.';
    if (deactivateDialogIsFor(target)) deactivateError.value = message;
    else showToast(`User "${target.name}" was not deactivated: ${message}`, 'error');
  } finally {
    deactivating.value = false;
  }
}

async function reactivateUser(user) {
  reactivatingId.value = user.id;
  try {
    await api.post(`/api/users/${user.id}/reactivate`);
    showToast(`User "${user.name}" reactivated.`);
    await fetchUsers();
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to reactivate user.', 'error');
  } finally {
    reactivatingId.value = null;
  }
}

// ── Group CRUD ─────────────────────────────────────────
function selectGroup(group) {
  selectedGroup.value = selectedGroup.value?.id === group.id ? null : group;
  memberSearch.value = '';
}

function openCreateGroupModal() {
  editingGroup.value = null;
  groupForm.name = '';
  groupForm.enum = '';
  groupFormError.value = '';
  showGroupModal.value = true;
}

function openEditGroupModal(group) {
  editingGroup.value = group;
  groupForm.name = group.name;
  groupForm.enum = group.enum;
  groupFormError.value = '';
  showGroupModal.value = true;
}

function closeGroupModal() {
  showGroupModal.value = false;
  editingGroup.value = null;
}

async function saveGroup() {
  if (savingGroup.value) return;
  savingGroup.value = true;
  groupFormError.value = '';
  try {
    if (editingGroup.value) {
      const res = await api.put(`/api/user-groups/${editingGroup.value.id}`, groupForm);
      const idx = groups.value.findIndex((g) => g.id === editingGroup.value.id);
      if (idx !== -1) groups.value[idx] = res.data;
      if (selectedGroup.value?.id === editingGroup.value.id) selectedGroup.value = res.data;
      showToast(`Group "${groupForm.name}" updated.`);
    } else {
      const res = await api.post('/api/user-groups', groupForm);
      groups.value.push(res.data);
      groups.value.sort((a, b) => a.name.localeCompare(b.name));
      showToast(`Group "${groupForm.name}" created.`);
    }
    closeGroupModal();
  } catch (err) {
    const data = err.response?.data;
    groupFormError.value = data?.errors
      ? Object.values(data.errors).flat().join(' ')
      : data?.message || 'An error occurred.';
  } finally {
    savingGroup.value = false;
  }
}

function confirmDeleteGroup(group) {
  deletingGroup.value = group;
  showGroupDeleteModal.value = true;
}

async function deleteGroupConfirmed() {
  deletingGroupLoading.value = true;
  try {
    await api.delete(`/api/user-groups/${deletingGroup.value.id}`);
    groups.value = groups.value.filter((g) => g.id !== deletingGroup.value.id);
    if (selectedGroup.value?.id === deletingGroup.value.id) selectedGroup.value = null;
    showToast(`Group "${deletingGroup.value.name}" deleted.`);
    showGroupDeleteModal.value = false;
    deletingGroup.value = null;
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to delete group.', 'error');
  } finally {
    deletingGroupLoading.value = false;
  }
}

async function toggleMember(userId) {
  if (!selectedGroup.value) return;
  togglingUserId.value = userId;
  const currentIds = selectedGroup.value.users?.map((u) => u.id) ?? [];
  const newIds = currentIds.includes(userId)
    ? currentIds.filter((id) => id !== userId)
    : [...currentIds, userId];
  try {
    const res = await api.put(`/api/user-groups/${selectedGroup.value.id}/users`, {
      user_ids: newIds,
    });
    selectedGroup.value = res.data;
    const idx = groups.value.findIndex((g) => g.id === res.data.id);
    if (idx !== -1) groups.value[idx] = res.data;
    const groupSnap = { id: res.data.id, name: res.data.name, enum: res.data.enum };
    users.value = users.value.map((u) => {
      const inGroup = res.data.users?.some((gu) => gu.id === u.id);
      const filteredGroups = (u.groups ?? []).filter((g) => g.id !== groupSnap.id);
      return { ...u, groups: inGroup ? [...filteredGroups, groupSnap] : filteredGroups };
    });
  } catch {
    showToast('Failed to update group members.', 'error');
  } finally {
    togglingUserId.value = null;
  }
}

// ── Delegations ────────────────────────────────────────
async function fetchDelegations() {
  try {
    const res = await api.get('/api/delegations');
    allDelegations.value = res.data;
  } catch (err) {
    allDelegations.value = [];
    const e = describeApiError(err, 'Could not load delegations');
    if (!e.silent) delegationError.value = e.message;
  }
}

function openDelegationModal(user) {
  delegationUser.value = user;
  delegationMode.value = 'create';
  delegationError.value = '';
  delegationInitial.value = {};
  delegationFieldErrors.value = {};
  showDelegationModal.value = true;
  fetchDelegations();
}

function closeDelegationModal() {
  showDelegationModal.value = false;
  delegationUser.value = null;
}

async function createDelegation(payload) {
  if (savingDelegation.value) return;
  savingDelegation.value = true;
  delegationError.value = '';
  delegationFieldErrors.value = {};
  try {
    await api.post('/api/delegations', { ...payload, user_ids: [delegationUser.value.id] });
    showToast(`Delegation "${payload.name}" created and ${delegationUser.value.name} assigned.`);
    closeDelegationModal();
  } catch (err) {
    const e = describeApiError(err, 'Failed to create delegation');
    if (!e.silent) delegationError.value = e.message;
    delegationFieldErrors.value = e.fieldErrors;
  } finally {
    savingDelegation.value = false;
  }
}

async function assignToExisting(del) {
  if (assigningDelegationId.value !== null) return;
  assigningDelegationId.value = del.id;
  delegationError.value = '';
  try {
    await api.post(`/api/delegations/${del.id}/add-user`, { user_id: delegationUser.value.id });
    showToast(`${delegationUser.value.name} assigned to "${del.name}".`);
    await fetchDelegations();
  } catch (err) {
    const e = describeApiError(err, 'Failed to assign');
    if (!e.silent) delegationError.value = e.message;
  } finally {
    assigningDelegationId.value = null;
  }
}

// ── Newbie ─────────────────────────────────────────────
// Closing the dialog and opening it for another user before the first user's periods arrived
// overlaps loads; only the latest may fill the list (the post-create refetch shares the channel).
const newbieRequests = useLatestRequest();

function closeNewbieModal() {
  newbieRequests.invalidate();
  showNewbieModal.value = false;
}

async function openNewbieModal(user) {
  const token = newbieRequests.begin();
  newbieUser.value = user;
  newbieForm.start_date = '';
  newbieForm.end_date = '';
  userNewbiePeriods.value = [];
  showNewbieModal.value = true;
  try {
    const res = await api.get(`/api/newbie-periods/user/${user.id}`);
    if (newbieRequests.isCurrent(token)) userNewbiePeriods.value = res.data;
  } catch {}
}

async function createNewbiePeriod() {
  if (savingNewbie.value) return;
  savingNewbie.value = true;
  try {
    await api.post('/api/newbie-periods', {
      user_id: newbieUser.value.id,
      start_date: newbieForm.start_date,
      end_date: newbieForm.end_date,
    });
    showToast(`${newbieUser.value.name} marked as newbie.`);
    const token = newbieRequests.begin();
    const res = await api.get(`/api/newbie-periods/user/${newbieUser.value.id}`);
    if (newbieRequests.isCurrent(token)) userNewbiePeriods.value = res.data;
    newbieForm.start_date = '';
    newbieForm.end_date = '';
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to create newbie period.', 'error');
  } finally {
    savingNewbie.value = false;
  }
}

async function deleteNewbiePeriod(period) {
  try {
    await api.delete(`/api/newbie-periods/${period.id}`);
    newbieRequests.invalidate();
    userNewbiePeriods.value = userNewbiePeriods.value.filter((p) => p.id !== period.id);
    showToast('Newbie period removed.');
  } catch {
    showToast('Failed to remove.', 'error');
  }
}
</script>
