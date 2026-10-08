<template>
  <!-- A route can name a param in meta.remountOn: the view is rebuilt when that param changes
       (e.g. another procedure), but kept when other params change (e.g. its version). -->
  <router-view v-slot="{ Component, route }">
    <component :is="Component" :key="route.meta.remountOn ? route.params[route.meta.remountOn] : undefined" />
  </router-view>
  <ConfirmModal />
</template>

<script setup>
import ConfirmModal from './components/ConfirmModal.vue';
</script>
