<template>
  <AppLayout>
    <!-- Main Content -->
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Header -->
      <div class="flex items-center justify-between mb-8">
        <div>
          <h1 class="text-2xl font-bold text-white">User Management</h1>
          <p class="text-slate-400 mt-1">Create, edit, and manage system users</p>
        </div>
        <button @click="openCreateModal"
          class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-500/25 transition-all duration-200 flex items-center gap-2 text-sm">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
          </svg>
          Add User
        </button>
      </div>



      <!-- Users Table -->
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
            <tr v-for="user in users" :key="user.id" class="hover:bg-white/5 transition-colors duration-150">
              <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                  <div class="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-400 to-violet-500 flex items-center justify-center text-white font-semibold text-xs">
                    {{ getInitials(user.name) }}
                  </div>
                  <span class="text-white font-medium text-sm">{{ user.name }}</span>
                  <span v-if="isCurrentNewbie(user.id)" class="text-yellow-400 text-xs" title="Active Newbie">⭐</span>
                  <span v-else-if="hasAnyNewbiePeriod(user.id)" class="text-gray-500 text-xs" title="Former Newbie (expired)">⭐</span>
                  <span v-if="user.is_admin" class="px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider bg-amber-500/15 text-amber-400 border border-amber-500/20 rounded-full">Admin</span>
                </div>
              </td>
              <td class="px-6 py-4 text-slate-300 text-sm">{{ user.email }}</td>
              <td class="px-6 py-4 text-slate-400 text-sm hidden md:table-cell">{{ formatDate(user.created_at) }}</td>
              <td class="px-6 py-4">
                <div class="flex items-center justify-end gap-1">
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
                  <button @click="confirmDelete(user)" title="Delete"
                    class="p-2 text-slate-400 hover:text-red-400 hover:bg-red-500/10 rounded-lg transition-all duration-200">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="users.length === 0">
              <td colspan="4" class="px-6 py-12 text-center text-slate-500 text-sm">No users found.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </main>

    <!-- Create/Edit Modal -->
    <div v-if="showUserModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="closeUserModal">
      <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
      <div class="relative bg-slate-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-lg font-semibold text-white">{{ editingUser ? 'Edit User' : 'Create New User' }}</h2>
          <button @click="closeUserModal" class="text-slate-400 hover:text-white">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form @submit.prevent="saveUser" class="space-y-4">
          <!-- Error -->
          <div v-if="formError" class="bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3">
            <span class="text-red-300 text-sm">{{ formError }}</span>
          </div>

          <!-- Name -->
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Full Name</label>
            <input v-model="userForm.name" type="text" required
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all duration-200 text-sm"
              placeholder="John Doe" />
          </div>

          <!-- Email -->
          <div>
            <label class="block text-sm font-medium text-slate-300 mb-1.5">Email Address</label>
            <input v-model="userForm.email" type="email" required
              class="w-full px-4 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50 focus:border-indigo-500/50 transition-all duration-200 text-sm"
              placeholder="john@example.com" />
          </div>

          <!-- Password (only for create) -->
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

          <!-- Admin Toggle -->
          <div class="flex items-center justify-between px-4 py-3 bg-white/5 border border-white/10 rounded-xl">
            <div>
              <p class="text-sm font-medium text-slate-300">Administrator</p>
              <p class="text-xs text-slate-500">Can manage users and system settings</p>
            </div>
            <button type="button" @click="userForm.is_admin = !userForm.is_admin"
              :class="userForm.is_admin ? 'bg-amber-500' : 'bg-white/10'"
              class="relative w-11 h-6 rounded-full transition-colors duration-200 focus:outline-none">
              <span
                :class="userForm.is_admin ? 'translate-x-5' : 'translate-x-0.5'"
                class="inline-block w-5 h-5 bg-white rounded-full shadow-sm transform transition-transform duration-200" />
            </button>
          </div>

          <!-- Actions -->
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
      </div>
    </div>

    <!-- Password Change Modal -->
    <div v-if="showPasswordModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="closePasswordModal">
      <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
      <div class="relative bg-slate-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h2 class="text-lg font-semibold text-white">Change Password</h2>
            <p class="text-slate-400 text-sm mt-1">For: {{ passwordUser?.name }}</p>
          </div>
          <button @click="closePasswordModal" class="text-slate-400 hover:text-white">
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
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="closeDeleteModal">
      <div class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>
      <div class="relative bg-slate-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-sm p-6">
        <div class="text-center mb-6">
          <div class="w-12 h-12 rounded-full bg-red-500/10 flex items-center justify-center mx-auto mb-4">
            <svg class="w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
            </svg>
          </div>
          <h2 class="text-lg font-semibold text-white mb-2">Delete User</h2>
          <p class="text-slate-400 text-sm">Are you sure you want to delete <span class="text-white font-medium">{{ deleteUser?.name }}</span>? This action cannot be undone.</p>
        </div>

        <div v-if="deleteError" class="mb-4 bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3">
          <span class="text-red-300 text-sm">{{ deleteError }}</span>
        </div>

        <div class="flex items-center gap-3">
          <button @click="closeDeleteModal"
            class="flex-1 px-4 py-2.5 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all duration-200">
            Cancel
          </button>
          <button @click="deleteUserConfirmed" :disabled="deleting"
            class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl transition-all duration-200 disabled:opacity-50 flex items-center justify-center gap-2">
            <svg v-if="deleting" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            {{ deleting ? 'Deleting...' : 'Delete' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Toast Notification -->
    <Transition
      enter-active-class="transition-all duration-400 ease-out"
      leave-active-class="transition-all duration-300 ease-in"
      enter-from-class="translate-y-4 opacity-0 scale-95"
      enter-to-class="translate-y-0 opacity-100 scale-100"
      leave-from-class="translate-y-0 opacity-100 scale-100"
      leave-to-class="translate-y-4 opacity-0 scale-95"
    >
      <div v-if="toastMessage" class="fixed bottom-6 right-6 z-[60] max-w-sm">
        <div class="bg-slate-800/95 backdrop-blur-xl border border-emerald-500/20 rounded-xl px-5 py-4 shadow-2xl shadow-black/40 flex items-start gap-3">
          <div class="w-8 h-8 rounded-lg bg-emerald-500/15 flex items-center justify-center shrink-0 mt-0.5">
            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <div class="flex-1 min-w-0">
            <p class="text-emerald-300 text-sm font-medium">{{ toastMessage }}</p>
          </div>
          <button @click="toastMessage = ''" class="text-slate-500 hover:text-slate-300 shrink-0 mt-0.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    </Transition>

    <!-- Delegation Modal -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0">
      <div v-if="showDelegationModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" @click.self="closeDelegationModal">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="closeDelegationModal"></div>
        <div class="relative bg-slate-900 border border-white/10 rounded-2xl shadow-2xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-white font-bold text-lg flex items-center gap-2">
              <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              Delegation — {{ delegationUser?.name }}
            </h2>
            <button @click="closeDelegationModal" class="p-1 text-slate-400 hover:text-white transition-colors">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>

          <!-- Mode tabs -->
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

          <!-- Create New -->
          <form v-if="delegationMode === 'create'" @submit.prevent="createDelegation" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Delegation name</label>
              <input v-model="delForm.name" type="text" required
                class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-purple-500/50 placeholder-slate-500"
                placeholder="e.g. Client meeting Warsaw" />
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-300 mb-1.5">Destination</label>
              <input v-model="delForm.destination" type="text" required
                class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-purple-500/50 placeholder-slate-500"
                placeholder="e.g. Warsaw, Poland" />
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-slate-300 mb-1.5">Start</label>
                <input v-model="delForm.start_datetime" type="datetime-local" required
                  class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-purple-500/50" />
              </div>
              <div>
                <label class="block text-sm font-medium text-slate-300 mb-1.5">End</label>
                <input v-model="delForm.end_datetime" type="datetime-local" required
                  class="w-full px-3 py-2.5 bg-white/5 border border-white/10 rounded-xl text-white text-sm focus:outline-none focus:ring-2 focus:ring-purple-500/50" />
              </div>
            </div>
            <div class="flex justify-end gap-3 pt-2">
              <button type="button" @click="closeDelegationModal" class="px-5 py-2 text-sm text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all font-medium">Cancel</button>
              <button type="submit" :disabled="savingDelegation"
                class="px-5 py-2 bg-violet-600 hover:bg-violet-700 text-white font-semibold rounded-xl shadow-lg shadow-violet-500/25 transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2">
                <svg v-if="savingDelegation" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Create & Assign
              </button>
            </div>
          </form>

          <!-- Assign Existing -->
          <div v-if="delegationMode === 'assign'" class="space-y-4">
            <div v-if="allDelegations.length === 0" class="text-center py-8 text-slate-500 text-sm">No delegations exist yet. Create one first.</div>
            <div v-else class="space-y-2 max-h-[300px] overflow-y-auto">
              <div v-for="del in allDelegations" :key="del.id"
                class="flex items-center justify-between p-3 bg-white/5 border border-white/10 rounded-xl hover:border-purple-500/30 transition-all">
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-medium text-white truncate">{{ del.name }}</p>
                  <p class="text-xs text-slate-400">📍 {{ del.destination }}</p>
                  <p class="text-[10px] text-slate-500">{{ formatDT(del.start_datetime) }} → {{ formatDT(del.end_datetime) }}</p>
                  <div v-if="del.users?.length" class="flex items-center gap-1 mt-1 flex-wrap">
                    <span v-for="u in del.users" :key="u.id"
                      class="px-1.5 py-0.5 text-[9px] bg-purple-500/15 text-purple-300 rounded-md">{{ u.name }}</span>
                  </div>
                </div>
                <button @click="assignToExisting(del)"
                  :disabled="del.users?.some(u => u.id === delegationUser?.id)"
                  :class="['px-3 py-1.5 text-xs font-medium rounded-lg transition-all shrink-0 ml-3',
                    del.users?.some(u => u.id === delegationUser?.id)
                      ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/20 cursor-default'
                      : 'bg-purple-500/15 text-purple-300 border border-purple-500/20 hover:bg-purple-500/25']">
                  {{ del.users?.some(u => u.id === delegationUser?.id) ? '✓ Assigned' : 'Assign' }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Newbie Period Modal -->
    <Transition
      enter-active-class="transition-all duration-200 ease-out" leave-active-class="transition-all duration-150 ease-in"
      enter-from-class="opacity-0" enter-to-class="opacity-100" leave-from-class="opacity-100" leave-to-class="opacity-0">
      <div v-if="showNewbieModal" class="fixed inset-0 z-50 flex items-center justify-center px-4">
        <div class="fixed inset-0 bg-black/50 backdrop-blur-sm" @click="showNewbieModal = false"></div>
        <div class="relative w-full max-w-md bg-slate-800/95 backdrop-blur-xl border border-yellow-500/25 rounded-2xl shadow-2xl shadow-yellow-500/10 p-6">
          <div class="flex items-center gap-3 mb-5">
            <div class="w-10 h-10 rounded-xl bg-yellow-500/15 flex items-center justify-center text-yellow-400 text-lg">⭐</div>
            <div>
              <h3 class="text-white font-bold text-lg">Newbie Period</h3>
              <p class="text-slate-400 text-sm">{{ newbieUser?.name }}</p>
            </div>
          </div>

          <!-- Existing periods -->
          <div v-if="userNewbiePeriods.length" class="mb-4 space-y-1.5">
            <p class="text-xs text-slate-500 uppercase tracking-wider font-semibold mb-2">Current periods</p>
            <div v-for="p in userNewbiePeriods" :key="p.id"
              class="flex items-center justify-between px-3 py-2 bg-yellow-500/8 border border-yellow-500/15 rounded-lg">
              <span class="text-sm text-yellow-300">{{ p.start_date?.substring(0,10) }} → {{ p.end_date?.substring(0,10) }}</span>
              <button @click="deleteNewbiePeriod(p)" class="text-slate-500 hover:text-red-400 text-xs px-2 py-1 transition-colors">✕</button>
            </div>
          </div>

          <!-- Add new period -->
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
              <button type="button" @click="showNewbieModal = false" class="px-4 py-2 text-sm text-slate-300 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl transition-all">Close</button>
              <button type="submit" :disabled="savingNewbie || !newbieForm.start_date || !newbieForm.end_date"
                class="px-5 py-2 bg-gradient-to-r from-yellow-500 to-amber-500 hover:from-yellow-600 hover:to-amber-600 text-white font-semibold rounded-xl shadow-lg shadow-yellow-500/25 transition-all text-sm disabled:opacity-50">Add Period</button>
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </AppLayout>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../axios.js';

import AppLayout from '../components/AppLayout.vue';

const router = useRouter();

// State
const users = ref([]);
const loading = ref(true);

// Toast
const toastMessage = ref('');
let toastTimer = null;

function showToast(message) {
  toastMessage.value = message;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { toastMessage.value = ''; }, 4000);
}

onUnmounted(() => clearTimeout(toastTimer));

// User form modal
const showUserModal = ref(false);
const editingUser = ref(null);
const saving = ref(false);
const formError = ref('');
const userForm = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  is_admin: false,
});

// Password modal
const showPasswordModal = ref(false);
const passwordUser = ref(null);
const savingPassword = ref(false);
const passwordError = ref('');
const passwordForm = reactive({
  password: '',
  password_confirmation: '',
});

// Delete modal
const showDeleteModal = ref(false);
const deleteUser = ref(null);
const deleting = ref(false);
const deleteError = ref('');

// Delegation modal
const showDelegationModal = ref(false);
const delegationUser = ref(null);
const delegationMode = ref('create');
const savingDelegation = ref(false);
const delegationError = ref('');
const allDelegations = ref([]);
const delForm = reactive({
  name: '',
  destination: '',
  start_datetime: '',
  end_datetime: '',
});

// Newbie modal
const showNewbieModal = ref(false);
const newbieUser = ref(null);
const savingNewbie = ref(false);
const userNewbiePeriods = ref([]);
const newbieForm = reactive({ start_date: '', end_date: '' });
const allNewbiePeriods = ref([]);

// Load users
async function fetchUsers() {
  loading.value = true;
  try {
    const response = await api.get('/api/users');
    users.value = response.data;
  } catch {
    // handle error silently
  } finally {
    loading.value = false;
  }
}

async function fetchAllNewbiePeriods() {
  try {
    const res = await api.get('/api/newbie-periods/all');
    allNewbiePeriods.value = res.data;
  } catch { allNewbiePeriods.value = []; }
}

function isCurrentNewbie(userId) {
  const today = new Date().toISOString().split('T')[0];
  return allNewbiePeriods.value.some(np => {
    const start = np.start_date?.substring(0, 10);
    const end = np.end_date?.substring(0, 10);
    return np.user_id === userId && today >= start && today <= end;
  });
}

function hasAnyNewbiePeriod(userId) {
  return allNewbiePeriods.value.some(np => np.user_id === userId);
}

onMounted(() => { fetchUsers(); fetchAllNewbiePeriods(); });

// Helpers
function getInitials(name) {
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
}

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  });
}

// Create
function openCreateModal() {
  editingUser.value = null;
  userForm.name = '';
  userForm.email = '';
  userForm.password = '';
  userForm.password_confirmation = '';
  userForm.is_admin = false;
  formError.value = '';
  showUserModal.value = true;
}

// Edit
function openEditModal(user) {
  editingUser.value = user;
  userForm.name = user.name;
  userForm.email = user.email;
  userForm.password = '';
  userForm.password_confirmation = '';
  userForm.is_admin = user.is_admin;
  formError.value = '';
  showUserModal.value = true;
}

function closeUserModal() {
  showUserModal.value = false;
  editingUser.value = null;
}

async function saveUser() {
  saving.value = true;
  formError.value = '';

  try {
    if (editingUser.value) {
      await api.put(`/api/users/${editingUser.value.id}`, {
        name: userForm.name,
        email: userForm.email,
        is_admin: userForm.is_admin,
      });
      showToast(`User "${userForm.name}" updated successfully.`);
    } else {
      await api.post('/api/users', {
        name: userForm.name,
        email: userForm.email,
        password: userForm.password,
        password_confirmation: userForm.password_confirmation,
        is_admin: userForm.is_admin,
      });
      showToast(`User "${userForm.name}" created successfully.`);
    }
    closeUserModal();
    await fetchUsers();
  } catch (err) {
    const data = err.response?.data;
    if (data?.errors) {
      formError.value = Object.values(data.errors).flat().join(' ');
    } else {
      formError.value = data?.message || 'An error occurred.';
    }
  } finally {
    saving.value = false;
  }
}

// Password change
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

async function changePassword() {
  savingPassword.value = true;
  passwordError.value = '';

  try {
    await api.put(`/api/users/${passwordUser.value.id}/password`, {
      password: passwordForm.password,
      password_confirmation: passwordForm.password_confirmation,
    });
    showToast(`Password for "${passwordUser.value.name}" changed successfully.`);
    closePasswordModal();
  } catch (err) {
    const data = err.response?.data;
    if (data?.errors) {
      passwordError.value = Object.values(data.errors).flat().join(' ');
    } else {
      passwordError.value = data?.message || 'An error occurred.';
    }
  } finally {
    savingPassword.value = false;
  }
}

// Delete
function confirmDelete(user) {
  deleteUser.value = user;
  deleteError.value = '';
  showDeleteModal.value = true;
}

function closeDeleteModal() {
  showDeleteModal.value = false;
  deleteUser.value = null;
}

async function deleteUserConfirmed() {
  deleting.value = true;
  deleteError.value = '';

  try {
    await api.delete(`/api/users/${deleteUser.value.id}`);
    showToast(`User "${deleteUser.value.name}" deleted successfully.`);
    closeDeleteModal();
    await fetchUsers();
  } catch (err) {
    deleteError.value = err.response?.data?.message || 'An error occurred.';
  } finally {
    deleting.value = false;
  }
}

// Delegations
async function fetchDelegations() {
  try {
    const res = await api.get('/api/delegations');
    allDelegations.value = res.data;
  } catch { allDelegations.value = []; }
}

function openDelegationModal(user) {
  delegationUser.value = user;
  delegationMode.value = 'create';
  delegationError.value = '';
  delForm.name = '';
  delForm.destination = '';
  delForm.start_datetime = '';
  delForm.end_datetime = '';
  showDelegationModal.value = true;
  fetchDelegations();
}

function closeDelegationModal() {
  showDelegationModal.value = false;
  delegationUser.value = null;
}

function formatDT(dt) {
  if (!dt) return '';
  const d = new Date(dt);
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}

async function createDelegation() {
  savingDelegation.value = true;
  delegationError.value = '';
  try {
    await api.post('/api/delegations', {
      ...delForm,
      user_ids: [delegationUser.value.id],
    });
    showToast(`Delegation "${delForm.name}" created and ${delegationUser.value.name} assigned.`);
    closeDelegationModal();
  } catch (err) {
    delegationError.value = err.response?.data?.message || 'Failed to create delegation.';
  } finally {
    savingDelegation.value = false;
  }
}

async function assignToExisting(del) {
  try {
    await api.post(`/api/delegations/${del.id}/add-user`, { user_id: delegationUser.value.id });
    showToast(`${delegationUser.value.name} assigned to "${del.name}".`);
    await fetchDelegations();
  } catch (err) {
    delegationError.value = err.response?.data?.message || 'Failed to assign.';
  }
}

// Newbie functions
async function openNewbieModal(user) {
  newbieUser.value = user;
  newbieForm.start_date = '';
  newbieForm.end_date = '';
  userNewbiePeriods.value = [];
  showNewbieModal.value = true;
  try {
    const res = await api.get(`/api/newbie-periods/user/${user.id}`);
    userNewbiePeriods.value = res.data;
  } catch {}
}

async function createNewbiePeriod() {
  savingNewbie.value = true;
  try {
    await api.post('/api/newbie-periods', {
      user_id: newbieUser.value.id,
      start_date: newbieForm.start_date,
      end_date: newbieForm.end_date,
    });
    showToast(`${newbieUser.value.name} marked as newbie.`);
    const res = await api.get(`/api/newbie-periods/user/${newbieUser.value.id}`);
    userNewbiePeriods.value = res.data;
    newbieForm.start_date = '';
    newbieForm.end_date = '';
  } catch (err) {
    showToast(err.response?.data?.message || 'Failed to create newbie period.', 'error');
  } finally { savingNewbie.value = false; }
}

async function deleteNewbiePeriod(period) {
  try {
    await api.delete(`/api/newbie-periods/${period.id}`);
    userNewbiePeriods.value = userNewbiePeriods.value.filter(p => p.id !== period.id);
    showToast('Newbie period removed.');
  } catch { showToast('Failed to remove.', 'error'); }
}

</script>
