<script setup>
import { useAuth } from '@/composables/useAuth';

const { user, isOwner } = useAuth();
</script>

<template>
  <div class="bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
    <h1 class="text-xl font-bold text-slate-900">Welcome, {{ user?.name }}</h1>
    <p class="mt-1 text-sm text-slate-600">
      Role: <span class="font-semibold">{{ isOwner ? 'Owner / Dispatcher' : 'Field Technician' }}</span>
    </p>
  </div>
</template>