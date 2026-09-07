<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h1 class="text-2xl font-bold text-white">My Availability</h1>
          <p class="text-slate-400 mt-1">Mark your preferred and unavailable work periods</p>
        </div>
      </div>

      <AvailabilityEditor v-if="currentUser" :target-user-id="currentUser.id" />

      <!-- Delegation markers kept from the original view: shown separately below the editor -->
      <div v-if="delegations.length" class="mt-6 bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-4">
        <h3 class="text-sm font-semibold text-slate-300 mb-3">Delegations this month</h3>
        <div class="flex flex-wrap gap-2">
          <span v-for="del in delegations" :key="del.id"
            class="px-2 py-1 text-xs font-medium rounded bg-purple-500/20 text-purple-300 border border-purple-500/25">
            📍 {{ del.name }} ({{ del.destination }})
          </span>
        </div>
      </div>
    </main>
  </AppLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import api from '../axios.js';
import AppLayout from '../components/AppLayout.vue';
import AvailabilityEditor from '../components/AvailabilityEditor.vue';
import { currentUser } from '../router/index.js';

const delegations = ref([]);

async function fetchDelegations() {
  try {
    const month = new Date().toISOString().slice(0, 7);
    const res = await api.get('/api/my-delegations', { params: { month } });
    delegations.value = res.data;
  } catch {
    delegations.value = [];
  }
}

onMounted(fetchDelegations);
</script>
