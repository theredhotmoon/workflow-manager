<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 relative overflow-hidden">

    <!-- Background decorative blobs -->
    <div class="absolute inset-0 overflow-hidden pointer-events-none">
      <div class="absolute -top-40 -right-40 w-96 h-96 rounded-full bg-indigo-500/10 blur-3xl"></div>
      <div class="absolute -bottom-40 -left-40 w-96 h-96 rounded-full bg-violet-500/10 blur-3xl"></div>
      <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] rounded-full bg-indigo-600/5 blur-3xl"></div>
    </div>

    <div class="relative z-10 w-full max-w-md mx-4">

      <!-- Logo / App Title -->
      <div class="text-center mb-8">
        <!-- Icon — morphs with state -->
        <div :class="[
          'inline-flex items-center justify-center w-16 h-16 rounded-2xl shadow-lg mb-4 transition-all duration-500',
          state === 'success'
            ? 'bg-gradient-to-br from-emerald-500 to-teal-600 shadow-emerald-500/25'
            : state === 'error'
              ? 'bg-gradient-to-br from-red-500 to-rose-600 shadow-red-500/25'
              : 'bg-gradient-to-br from-indigo-500 to-violet-600 shadow-indigo-500/25',
        ]">
          <!-- Success check -->
          <svg v-if="state === 'success'" class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
          </svg>
          <!-- Error X -->
          <svg v-else-if="state === 'error'" class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12" />
          </svg>
          <!-- Loading spinner -->
          <svg v-else-if="state === 'loading'" class="animate-spin w-8 h-8 text-white" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
          </svg>
          <!-- Idle clock -->
          <svg v-else class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>

        <h1 class="text-3xl font-bold text-white tracking-tight">Shift Changer</h1>
        <p :class="[
          'mt-2 text-sm transition-all duration-300',
          state === 'error' ? 'text-red-400' : state === 'success' ? 'text-emerald-400' : 'text-slate-400',
        ]">
          <span v-if="state === 'loading'">Signing you in…</span>
          <span v-else-if="state === 'success'">Welcome back!</span>
          <span v-else-if="state === 'error'">Check your credentials and try again</span>
          <span v-else>Sign in to manage your team's shifts</span>
        </p>
      </div>

      <!-- Login Card — border colour reflects state -->
      <div :class="[
        'backdrop-blur-xl rounded-2xl shadow-2xl p-8 transition-all duration-500',
        state === 'success'
          ? 'bg-emerald-500/[0.04] border border-emerald-500/30 shadow-emerald-500/10'
          : state === 'error'
            ? 'bg-red-500/[0.04] border border-red-500/30 shadow-red-500/10'
            : state === 'loading'
              ? 'bg-white/5 border border-indigo-500/30'
              : 'bg-white/5 border border-white/10',
      ]">
        <form @submit.prevent="login" class="space-y-5">

          <!-- Email Field -->
          <div>
            <label for="email" class="block text-sm font-medium text-slate-300 mb-2">Email Address</label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                <svg :class="['w-5 h-5 transition-colors duration-300', state === 'error' ? 'text-red-500/70' : 'text-slate-500']"
                  fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
              </div>
              <input
                id="email"
                v-model="form.email"
                @input="onInput"
                type="email"
                required
                autocomplete="email"
                placeholder="you@example.com"
                :disabled="state === 'loading' || state === 'success'"
                :class="[
                  'w-full pl-11 pr-4 py-3 rounded-xl text-white placeholder-slate-500 transition-all duration-300',
                  'focus:outline-none focus:ring-2',
                  'disabled:opacity-50 disabled:cursor-not-allowed',
                  state === 'error'
                    ? 'bg-red-500/[0.06] border border-red-500/40 focus:ring-red-500/30 focus:border-red-500/50'
                    : state === 'success'
                      ? 'bg-emerald-500/[0.06] border border-emerald-500/30 focus:ring-emerald-500/30'
                      : 'bg-white/5 border border-white/10 focus:ring-indigo-500/50 focus:border-indigo-500/50',
                ]"
              />
            </div>
          </div>

          <!-- Password Field -->
          <div>
            <label for="password" class="block text-sm font-medium text-slate-300 mb-2">Password</label>
            <div class="relative">
              <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                <svg :class="['w-5 h-5 transition-colors duration-300', state === 'error' ? 'text-red-500/70' : 'text-slate-500']"
                  fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
              </div>
              <input
                id="password"
                v-model="form.password"
                @input="onInput"
                type="password"
                required
                autocomplete="current-password"
                placeholder="••••••••"
                :disabled="state === 'loading' || state === 'success'"
                :class="[
                  'w-full pl-11 pr-4 py-3 rounded-xl text-white placeholder-slate-500 transition-all duration-300',
                  'focus:outline-none focus:ring-2',
                  'disabled:opacity-50 disabled:cursor-not-allowed',
                  state === 'error'
                    ? 'bg-red-500/[0.06] border border-red-500/40 focus:ring-red-500/30 focus:border-red-500/50'
                    : state === 'success'
                      ? 'bg-emerald-500/[0.06] border border-emerald-500/30 focus:ring-emerald-500/30'
                      : 'bg-white/5 border border-white/10 focus:ring-indigo-500/50 focus:border-indigo-500/50',
                ]"
              />
            </div>
          </div>

          <!-- Submit Button -->
          <button
            type="submit"
            :disabled="state === 'loading' || state === 'success'"
            :class="[
              'w-full py-3 px-4 font-semibold rounded-xl shadow-lg transition-all duration-300',
              'flex items-center justify-center gap-2',
              'disabled:cursor-not-allowed',
              state === 'success'
                ? 'bg-emerald-600 shadow-emerald-500/25 text-white opacity-80'
                : state === 'error'
                  ? 'bg-red-600 hover:bg-red-700 shadow-red-500/25 text-white'
                  : 'bg-violet-600 hover:bg-violet-700 shadow-violet-500/25 text-white disabled:opacity-50',
            ]"
          >
            <!-- Loading -->
            <svg v-if="state === 'loading'" class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <!-- Success check -->
            <svg v-else-if="state === 'success'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
            </svg>

            <span>
              <template v-if="state === 'loading'">Signing in…</template>
              <template v-else-if="state === 'success'">Signed In</template>
              <template v-else-if="state === 'error'">Try Again</template>
              <template v-else>Sign In</template>
            </span>
          </button>

        </form>
      </div>

      <!-- Footer -->
      <p class="text-center text-slate-600 text-xs mt-6">&copy; 2026 Shift Changer. All rights reserved.</p>
    </div>

    <!-- Toast lives outside the card so it renders above everything -->
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../axios.js';
import { useToast } from '../composables/useToast.js';
import { safeRedirect } from '../sessionExpiry.js';

const router = useRouter();
const route = useRoute();
const { showToast } = useToast();

// 'idle' | 'loading' | 'success' | 'error'
const state = ref('idle');

const form = reactive({
  email: '',
  password: '',
});

// Clear error state as soon as the user starts correcting their input
function onInput() {
  if (state.value === 'error') state.value = 'idle';
}

async function login() {
  if (state.value === 'loading' || state.value === 'success') return;

  state.value = 'loading';

  try {
    await api.get('/sanctum/csrf-cookie');
    await api.post('/api/login', {
      email: form.email,
      password: form.password,
    });

    state.value = 'success';
    showToast('Signed in successfully. Redirecting…', 'success');

    // Back to where the session ran out (sessionExpiry.js / the router guard pass `redirect`).
    const target = safeRedirect(route.query.redirect) ?? { name: 'dashboard' };
    // Brief pause so the success state is visible before navigating
    setTimeout(() => router.push(target), 800);
  } catch (err) {
    state.value = 'error';
    const message = err.response?.data?.message || 'Invalid credentials. Please try again.';
    showToast(message, 'error');
  }
}
</script>
