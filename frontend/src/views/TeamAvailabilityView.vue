<template>
  <AppLayout>
    <main class="max-w-[1800px] mx-auto px-4 sm:px-6 lg:px-8 py-6">
      <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Team Availability</h1>
        <p class="text-slate-400 mt-1">Set preferences and unavailable periods for people on your team</p>
      </div>

      <div v-if="loading" class="p-10 text-center text-slate-400">Loading your teams…</div>

      <div v-else-if="teams.length === 0" class="p-10 text-center text-slate-500">
        You don't manage any teams yet.
      </div>

      <div v-else class="flex gap-6">
        <div class="w-1/4 min-w-[220px]">
          <div v-for="team in teams" :key="team.id" class="mb-4">
            <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">{{ team.name }}</h3>
            <button v-for="member in team.users" :key="member.id"
              @click="selectedMember = member"
              :class="['w-full text-left px-3 py-2 rounded-lg text-sm mb-1 transition-all',
                selectedMember?.id === member.id ? 'bg-indigo-500/20 text-indigo-300' : 'text-slate-300 hover:bg-white/5']">
              {{ member.name }}
            </button>
          </div>
        </div>

        <div class="flex-1 min-w-0">
          <AvailabilityEditor v-if="selectedMember" :target-user-id="selectedMember.id" :target-user-name="selectedMember.name" />
          <div v-else class="p-10 text-center text-slate-500">Select a team member to manage their availability.</div>
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

const teams = ref([]);
const loading = ref(true);
const selectedMember = ref(null);

async function fetchTeams() {
  loading.value = true;
  try {
    const res = await api.get('/api/my-managed-teams');
    teams.value = res.data;
  } catch {
    teams.value = [];
  } finally {
    loading.value = false;
  }
}

onMounted(fetchTeams);
</script>
