<script setup>
import { onMounted, ref, watch } from 'vue';
import { api } from '@/services/api';

const loading = ref(true);
const error = ref('');
const pagination = ref({ data: [], current_page: 1, last_page: 1, total: 0 });

const search = ref('');
const typeFilter = ref('');
const dueFilter = ref('');

let debounceTimer = null;

async function loadInstallations(page = 1) {
    loading.value = true;
    error.value = '';
    try {
        const params = new URLSearchParams({ page: String(page) });
        if (search.value.trim()) params.set('search', search.value.trim());
        if (typeFilter.value) params.set('type', typeFilter.value);
        if (dueFilter.value) params.set('due', dueFilter.value);

        pagination.value = await api.get(`/api/installations?${params.toString()}`);
    } catch (err) {
        error.value = err.message || 'Unable to load installations.';
    } finally {
        loading.value = false;
    }
}

watch(search, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => loadInstallations(1), 250);
});

watch([typeFilter, dueFilter], () => loadInstallations(1));

function typeLabel(type) {
    return {
        BRAND: 'Fire Detection',
        INBR: 'Intrusion',
        CAM: 'Camera / CCTV',
        TOEG: 'Access Control',
    }[type] || 'Other';
}

function formatDate(dateStr) {
    if (!dateStr) return 'Needs review';
    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(dateStr));
}

onMounted(() => loadInstallations(1));
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">Installations</h1>
        <p class="text-xs text-slate-500 mt-0.5 tabular-nums">
          {{ pagination.total }} active &amp; legacy security sites
        </p>
      </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="bg-white rounded-xl border border-slate-200/90 p-3 flex flex-col sm:flex-row gap-3">
      <input
        v-model="search"
        type="search"
        placeholder="Search customer, installation, city, or code..."
        class="flex-1 px-3.5 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900/10"
      />
      <select v-model="typeFilter" class="px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
        <option value="">All Systems</option>
        <option value="BRAND">Fire Detection (BRAND)</option>
        <option value="INBR">Intrusion Alarm (INBR)</option>
        <option value="CAM">Camera / CCTV (CAM)</option>
        <option value="TOEG">Access Control (TOEG)</option>
      </select>
      <select v-model="dueFilter" class="px-3 py-2 rounded-lg border border-slate-200 text-sm bg-white">
        <option value="">All Due States</option>
        <option value="overdue">Overdue</option>
        <option value="due_soon">Due within 30 Days</option>
        <option value="needs_review">Needs Date / Review</option>
      </select>
    </div>

    <div v-if="error" class="p-4 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">
      {{ error }}
    </div>

    <!-- Clean List -->
    <section class="bg-white rounded-xl border border-slate-200/90 overflow-hidden">
      <div v-if="loading" class="p-8 space-y-3">
        <div v-for="n in 6" :key="n" class="h-12 bg-slate-100 rounded-lg animate-pulse"></div>
      </div>

      <div v-else-if="pagination.data.length === 0" class="p-10 text-center text-sm text-slate-500">
        No installations match your current filters.
      </div>

      <ul v-else class="divide-y divide-slate-100">
        <li
          v-for="inst in pagination.data"
          :key="inst.id"
          class="px-5 py-3.5 hover:bg-slate-50/70 transition flex flex-col sm:flex-row sm:items-center justify-between gap-3"
        >
          <div class="min-w-0 space-y-0.5">
            <div class="flex items-center gap-2 flex-wrap">
              <RouterLink :to="`/installations/${inst.id}`" class="text-sm font-medium text-slate-900 hover:underline">
                {{ inst.name }}
              </RouterLink>
              <span
                v-if="inst.is_fire_safety"
                class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-50 text-red-700 border border-red-200/80"
              >
                FIRE
              </span>
              <span v-else class="text-[11px] text-slate-400">
                {{ typeLabel(inst.installation_type) }}
              </span>
            </div>
            <div class="text-xs text-slate-500 flex items-center gap-2 flex-wrap">
              <span>{{ inst.customer_name }}</span>
              <span v-if="inst.location_city" class="text-slate-300">·</span>
              <span v-if="inst.location_city">{{ inst.location_city }}</span>
              <span class="text-slate-300">·</span>
              <span class="tabular-nums">{{ inst.components_count }} components</span>
            </div>
          </div>

          <div class="flex items-center justify-between sm:justify-end gap-4 shrink-0">
            <div class="text-left sm:text-right tabular-nums">
              <div class="text-[11px] text-slate-400">Next service</div>
              <div
                class="text-xs font-medium"
                :class="inst.plan?.is_overdue ? 'text-red-600 font-semibold' : 'text-slate-700'"
              >
                {{ formatDate(inst.plan?.next_due_at) }}
              </div>
            </div>

            <RouterLink
              :to="`/installations/${inst.id}`"
              class="px-3 py-1.5 rounded-lg border border-slate-200 hover:bg-slate-100 text-xs font-medium text-slate-700 transition"
            >
              Open File
            </RouterLink>
          </div>
        </li>
      </ul>

      <!-- Pagination -->
      <div
        v-if="pagination.last_page > 1"
        class="px-5 py-3 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500 tabular-nums"
      >
        <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
        <div class="flex gap-2">
          <button
            :disabled="pagination.current_page <= 1"
            @click="loadInstallations(pagination.current_page - 1)"
            class="px-3 py-1.5 rounded-lg border border-slate-200 disabled:opacity-40 hover:bg-slate-50 cursor-pointer"
          >
            Previous
          </button>
          <button
            :disabled="pagination.current_page >= pagination.last_page"
            @click="loadInstallations(pagination.current_page + 1)"
            class="px-3 py-1.5 rounded-lg border border-slate-200 disabled:opacity-40 hover:bg-slate-50 cursor-pointer"
          >
            Next
          </button>
        </div>
      </div>
    </section>
  </div>
</template>