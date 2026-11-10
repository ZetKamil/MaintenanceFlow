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
  <div class="min-h-screen bg-slate-50/70 text-slate-900">
    <!-- Authenticated Workspace Layout -->
    <div v-if="isAuthenticated" class="min-h-screen flex flex-col lg:flex-row">
      <!-- Desktop Left Sidebar -->
      <aside class="hidden lg:flex lg:w-60 lg:flex-col lg:fixed lg:inset-y-0 bg-white border-r border-slate-200/80">
        <div class="h-16 px-5 flex items-center gap-3 border-b border-slate-100">
          <div class="w-8 h-8 rounded-lg bg-slate-900 text-white flex items-center justify-center text-xs font-bold tracking-tight">
            MF
          </div>
          <div class="leading-tight">
            <div class="text-sm font-semibold text-slate-900">MaintenanceFlow</div>
            <div class="text-[11px] text-slate-400">Verlinde Beveiliging</div>
          </div>
        </div>

        <nav class="flex-1 px-3 py-4 space-y-1 text-sm font-medium">
          <RouterLink
            to="/"
            class="flex items-center gap-3 px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100/70 transition"
            active-class="bg-slate-100 text-slate-900 font-semibold"
          >
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
            </svg>
            <span>Overview</span>
          </RouterLink>

          <RouterLink
            to="/planning"
            class="flex items-center gap-3 px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100/70 transition"
            active-class="bg-slate-100 text-slate-900 font-semibold"
          >
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <span>Planning</span>
          </RouterLink>

          <RouterLink
            v-if="isOwner"
            to="/installations"
            class="flex items-center gap-3 px-3 py-2 rounded-lg text-slate-600 hover:text-slate-900 hover:bg-slate-100/70 transition"
            active-class="bg-slate-100 text-slate-900 font-semibold"
          >
            <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
            <span>Installations</span>
          </RouterLink>
        </nav>

        <div class="p-3 border-t border-slate-100">
          <div class="px-3 py-2 rounded-lg bg-slate-50 flex items-center justify-between gap-2">
            <div class="min-w-0">
              <div class="text-xs font-semibold text-slate-900 truncate">{{ user?.name }}</div>
              <div class="text-[11px] text-slate-500">{{ isOwner ? 'Owner' : 'Technician' }}</div>
            </div>
            <button
              @click="handleLogout"
              title="Sign out"
              class="p-1.5 rounded-md text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 transition cursor-pointer"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
              </svg>
            </button>
          </div>
        </div>
      </aside>

      <!-- Mobile Top Bar -->
      <header class="lg:hidden bg-white border-b border-slate-200 px-4 h-14 flex items-center justify-between sticky top-0 z-30">
        <RouterLink to="/" class="flex items-center gap-2 font-semibold text-sm text-slate-900">
          <span class="w-7 h-7 rounded-md bg-slate-900 text-white flex items-center justify-center text-xs font-bold">MF</span>
          <span>MaintenanceFlow</span>
        </RouterLink>
        <div class="flex items-center gap-3">
          <nav class="flex items-center gap-2 text-xs font-medium">
            <RouterLink to="/" class="px-2.5 py-1.5 rounded-md text-slate-600" active-class="bg-slate-100 text-slate-900">Overview</RouterLink>
            <RouterLink to="/planning" class="px-2.5 py-1.5 rounded-md text-slate-600" active-class="bg-slate-100 text-slate-900">Planning</RouterLink>
            <RouterLink v-if="isOwner" to="/installations" class="px-2.5 py-1.5 rounded-md text-slate-600" active-class="bg-slate-100 text-slate-900">Sites</RouterLink>
          </nav>
          <button @click="handleLogout" class="text-xs text-slate-500 hover:text-slate-900 cursor-pointer">Out</button>
        </div>
      </header>

      <!-- Main Workspace Canvas -->
      <main class="flex-1 min-w-0 lg:pl-60">
        <div
          class="mx-auto px-4 sm:px-8 py-6 sm:py-8"
          :class="$route.name === 'planning' ? 'max-w-7xl' : 'max-w-6xl'"
        >
          <RouterView />
        </div>
      </main>
    </div>

    <!-- Guest View (Login) -->
    <RouterView v-else />
  </div>
</template>