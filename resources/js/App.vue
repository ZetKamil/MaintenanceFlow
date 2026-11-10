<script setup>
import { useRouter } from 'vue-router';
import { useAuth } from '@/composables/useAuth';

const router = useRouter();
const { user, isAuthenticated, isOwner, logout } = useAuth();

async function handleLogout() {
    await logout();
    router.push({ name: 'login' });
}
</script>

<template>
  <div class="min-h-screen bg-slate-50 text-slate-900">
    <header v-if="isAuthenticated" class="bg-white border-b border-slate-200 sticky top-0 z-30">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
        <div class="flex items-center gap-6">
          <RouterLink to="/" class="flex items-center gap-2.5 font-bold text-slate-900">
            <span class="w-8 h-8 rounded-lg bg-blue-600 text-white flex items-center justify-center text-sm">MF</span>
            <span class="hidden sm:inline">MaintenanceFlow</span>
          </RouterLink>

          <nav class="flex items-center gap-1 sm:gap-2 text-sm font-medium">
            <RouterLink
              to="/"
              class="px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
              active-class="bg-blue-50 text-blue-700 font-semibold"
            >
              Dashboard
            </RouterLink>
            <RouterLink
              v-if="isOwner"
              to="/installations"
              class="px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
              active-class="bg-blue-50 text-blue-700 font-semibold"
            >
              Installations
            </RouterLink>
            <RouterLink
              to="/planning"
              class="px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
              active-class="bg-blue-50 text-blue-700 font-semibold"
            >
              Planning
            </RouterLink>
          </nav>
        </div>

        <div class="flex items-center gap-3">
          <div class="text-right hidden sm:block">
            <div class="text-xs font-semibold text-slate-900">{{ user?.name }}</div>
            <div class="text-[11px] font-medium" :class="isOwner ? 'text-blue-600' : 'text-emerald-600'">
              {{ isOwner ? 'Owner / Dispatcher' : 'Technician' }}
            </div>
          </div>
          <button
            @click="handleLogout"
            class="px-3 py-2 rounded-lg text-xs font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 transition cursor-pointer"
          >
            Sign Out
          </button>
        </div>
      </div>
    </header>

    <main :class="isAuthenticated ? 'max-w-7xl mx-auto px-4 sm:px-6 py-6' : ''">
      <RouterView />
    </main>
  </div>
</template>