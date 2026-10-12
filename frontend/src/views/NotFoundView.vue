<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-16">
      <div class="max-w-md mx-auto text-center p-8 bg-white/5 border border-white/10 rounded-2xl">
        <p class="text-5xl font-bold text-indigo-400 mb-3" aria-hidden="true">404</p>
        <h1 class="text-xl font-bold text-white mb-2">Page not found</h1>
        <p class="text-sm text-slate-400 mb-6">
          There's nothing at <code class="text-slate-300 break-all">{{ route.fullPath }}</code>. The link may be wrong or out of date.
        </p>
        <router-link to="/dashboard"
          class="inline-block px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-xl text-sm shadow-lg shadow-indigo-500/25 transition-all">
          Go to the dashboard
        </router-link>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { useRoute } from 'vue-router';
import AppLayout from '../components/AppLayout.vue';

const route = useRoute();
</script>
