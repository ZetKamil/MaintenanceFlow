<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '@/composables/useAuth';

const router = useRouter();
const { login } = useAuth();

const email = ref('');
const password = ref('');
const errorMessage = ref('');
const isSubmitting = ref(false);

async function handleSubmit() {
    errorMessage.value = '';
    isSubmitting.value = true;

    try {
        await login(email.value, password.value);
        router.push({ name: 'dashboard' });
    } catch (err) {
        errorMessage.value = err.errors?.email?.[0] || err.message || 'Sign in failed.';
    } finally {
        isSubmitting.value = false;
    }
}

async function quickLogin(demoEmail) {
    email.value = demoEmail;
    password.value = 'secret123';
    await handleSubmit();
}
</script>

<template>
  <div class="min-h-screen bg-slate-100 flex flex-col justify-center items-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-lg border border-slate-200 p-6 sm:p-8">
      <div class="flex items-center gap-3 mb-6">
        <div class="w-10 h-10 rounded-xl bg-blue-600 text-white font-bold flex items-center justify-center text-lg">
          MF
        </div>
        <div>
          <h1 class="text-xl font-bold text-slate-900">MaintenanceFlow</h1>
          <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Verlinde Beveiliging BV</p>
        </div>
      </div>

      <div v-if="errorMessage" class="mb-4 p-3 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">
        {{ errorMessage }}
      </div>

      <form @submit.prevent="handleSubmit" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Email Address</label>
          <input
            v-model="email"
            type="email"
            required
            autocomplete="email"
            placeholder="dirk@verlinde.be"
            class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
          <input
            v-model="password"
            type="password"
            required
            autocomplete="current-password"
            placeholder="••••••••"
            class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>

        <button
          type="submit"
          :disabled="isSubmitting"
          class="w-full py-3 px-4 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-semibold text-sm transition cursor-pointer"
        >
          {{ isSubmitting ? 'Signing in...' : 'Sign In' }}
        </button>
      </form>

      <div class="mt-6 pt-5 border-t border-slate-200">
        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">
          Quick Demo Sign-In (Presentation)
        </p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
          <button
            type="button"
            :disabled="isSubmitting"
            @click="quickLogin('dirk@verlinde.be')"
            class="py-2.5 px-3 rounded-lg border border-slate-300 hover:bg-slate-50 text-left transition cursor-pointer"
          >
            <div class="text-xs font-bold text-slate-900">Dirk Verlinde</div>
            <div class="text-[11px] text-slate-500">Owner / Dispatcher</div>
          </button>
          <button
            type="button"
            :disabled="isSubmitting"
            @click="quickLogin('tom@verlinde.be')"
            class="py-2.5 px-3 rounded-lg border border-slate-300 hover:bg-slate-50 text-left transition cursor-pointer"
          >
            <div class="text-xs font-bold text-slate-900">Tom De Coninck</div>
            <div class="text-[11px] text-slate-500">Field Technician</div>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>