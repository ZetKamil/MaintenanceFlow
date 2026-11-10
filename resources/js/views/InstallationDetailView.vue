<script setup>
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/services/api';

const route = useRoute();
const router = useRouter();

const loading = ref(true);
const error = ref('');
const installation = ref(null);

async function loadInstallation() {
    loading.value = true;
    error.value = '';
    try {
        const res = await api.get(`/api/installations/${route.params.id}`);
        installation.value = res.installation;
    } catch (err) {
        error.value = err.message || 'Unable to load installation details.';
    } finally {
        loading.value = false;
    }
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(dateStr));
}

function statusPillClass(status) {
    return {
        scheduled: 'bg-slate-100 text-slate-700',
        in_progress: 'bg-blue-50 text-blue-700',
        completed: 'bg-emerald-50 text-emerald-700',
        needs_contact: 'bg-amber-50 text-amber-700',
        awaiting_customer: 'bg-amber-50 text-amber-700',
        cancelled: 'bg-slate-100 text-slate-400',
    }[status] || 'bg-slate-100 text-slate-600';
}

onMounted(loadInstallation);
</script>

<template>
  <div class="space-y-6">
    <div>
      <button @click="router.back()" class="text-xs font-medium text-slate-500 hover:text-slate-900 cursor-pointer">
        &larr; Back
      </button>
    </div>

    <div v-if="error" class="p-4 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">
      {{ error }}
    </div>

    <div v-else-if="loading" class="space-y-4">
      <div class="h-20 bg-white rounded-xl border border-slate-200 animate-pulse"></div>
      <div class="h-72 bg-white rounded-xl border border-slate-200 animate-pulse"></div>
    </div>

    <template v-else-if="installation">
      <!-- Header -->
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <div class="flex items-center gap-2.5 flex-wrap">
            <h1 class="text-xl font-semibold tracking-tight text-slate-900">{{ installation.name }}</h1>
            <span
              v-if="installation.is_fire_safety"
              class="px-2 py-0.5 rounded text-[11px] font-semibold bg-red-50 text-red-700 border border-red-200/80"
            >
              FIRE (BRAND)
            </span>
          </div>
          <p class="text-xs text-slate-500 mt-1">
            {{ installation.customer.name }} · {{ installation.location_city || 'Location unknown' }}
          </p>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- LEFT (2/3): Hardware + Service History -->
        <div class="lg:col-span-8 space-y-6">
          <!-- Hardware Components -->
          <section class="bg-white rounded-xl border border-slate-200/90 overflow-hidden">
            <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between">
              <h2 class="text-sm font-semibold text-slate-900">Installed Hardware</h2>
              <span class="text-xs text-slate-400 tabular-nums">{{ installation.components.length }} items</span>
            </div>

            <div v-if="installation.components.length === 0" class="p-8 text-center text-xs text-slate-500">
              No hardware components recorded for this installation.
            </div>

            <ul v-else class="divide-y divide-slate-100">
              <li
                v-for="comp in installation.components"
                :key="comp.id"
                class="px-5 py-3 flex items-center justify-between gap-4 text-sm"
              >
                <div class="min-w-0">
                  <div class="font-medium text-slate-900">
                    <span class="text-slate-400 tabular-nums mr-1.5">{{ comp.quantity }}&times;</span>
                    {{ comp.description }}
                  </div>
                  <div class="text-xs text-slate-400 flex items-center gap-2 flex-wrap mt-0.5">
                    <span v-if="comp.article_code" class="font-mono text-[11px]">{{ comp.article_code }}</span>
                    <span v-if="comp.serial_number" class="tabular-nums">S/N: {{ comp.serial_number }}</span>
                  </div>
                </div>
                <span v-if="comp.location" class="px-2 py-0.5 rounded bg-slate-100 text-[11px] text-slate-600 shrink-0">
                  {{ comp.location }}
                </span>
              </li>
            </ul>
          </section>

          <!-- Service History -->
          <section class="bg-white rounded-xl border border-slate-200/90 overflow-hidden">
            <div class="px-5 py-3.5 border-b border-slate-100 flex items-center justify-between">
              <h2 class="text-sm font-semibold text-slate-900">Work Order &amp; Service History</h2>
              <span class="text-xs text-slate-400 tabular-nums">{{ installation.work_orders.length }} records</span>
            </div>

            <div v-if="installation.work_orders.length === 0" class="p-8 text-center text-xs text-slate-500">
              No work orders logged yet.
            </div>

            <div v-else class="divide-y divide-slate-100">
              <div v-for="wo in installation.work_orders" :key="wo.id" class="p-5 space-y-2">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                  <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-[11px] font-medium" :class="statusPillClass(wo.status)">
                      {{ wo.status_label }}
                    </span>
                    <span class="text-xs font-medium text-slate-500 tabular-nums">
                      {{ formatDate(wo.scheduled_start_at || wo.cycle_due_date) }}
                    </span>
                    <span v-if="wo.technician" class="text-xs text-slate-400">· {{ wo.technician.name }}</span>
                  </div>

                  <RouterLink :to="`/work-orders/${wo.id}`" class="text-xs font-medium text-blue-600 hover:underline">
                    Open Visit &rarr;
                  </RouterLink>
                </div>

                <p v-if="wo.notes" class="text-xs text-slate-600 whitespace-pre-line">{{ wo.notes }}</p>

                <div v-if="wo.report" class="mt-2 p-3 rounded-lg bg-slate-50 border border-slate-100 text-xs space-y-1">
                  <div class="font-semibold text-slate-700">
                    Service Report ({{ formatDate(wo.report.performed_at) }})
                  </div>
                  <div class="text-slate-600">{{ wo.report.performed_work }}</div>
                  <div v-if="wo.report.findings" class="text-slate-500">Findings: {{ wo.report.findings }}</div>
                  <div v-if="wo.report.recommendations" class="text-slate-500">Recommendations: {{ wo.report.recommendations }}</div>
                </div>

                <div v-if="wo.materials?.length" class="flex flex-wrap gap-1.5 pt-1">
                  <span
                    v-for="mat in wo.materials"
                    :key="mat.id"
                    class="px-2 py-0.5 rounded bg-slate-100 text-[11px] text-slate-600 tabular-nums"
                  >
                    {{ Number(mat.quantity) }}&times; {{ mat.description }}
                  </span>
                </div>
              </div>
            </div>
          </section>
        </div>

        <!-- RIGHT (1/3): Maintenance Plan & Site Contact -->
        <aside class="lg:col-span-4 space-y-5">
          <section class="bg-white rounded-xl border border-slate-200/90 p-5 space-y-3">
            <h2 class="text-sm font-semibold text-slate-900">Maintenance Cycle</h2>

            <div v-for="plan in installation.maintenance_plans" :key="plan.id" class="space-y-2.5 text-xs">
              <div class="flex justify-between">
                <span class="text-slate-500">Interval</span>
                <span class="font-medium text-slate-900 tabular-nums">
                  {{ plan.interval_months > 0 ? `Every ${plan.interval_months} months` : 'On request / Review' }}
                </span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-500">Last Completed</span>
                <span class="font-medium text-slate-900 tabular-nums">{{ formatDate(plan.last_completed_at) }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-slate-500">Next Due</span>
                <span class="font-semibold tabular-nums" :class="plan.is_overdue ? 'text-red-600' : 'text-slate-900'">
                  {{ formatDate(plan.next_due_at) }}
                </span>
              </div>
              <p v-if="plan.source_notes" class="pt-2 border-t border-slate-100 text-slate-500">
                {{ plan.source_notes }}
              </p>
            </div>
          </section>

          <section class="bg-white rounded-xl border border-slate-200/90 p-5 space-y-2.5 text-xs">
            <h2 class="text-sm font-semibold text-slate-900 mb-3">Customer &amp; Site</h2>

            <div class="font-semibold text-slate-900 text-sm">{{ installation.customer.name }}</div>
            <div class="text-slate-600">
              {{ installation.location_street || installation.customer.street }}<br />
              {{ installation.location_postal_code || installation.customer.postal_code }}
              {{ installation.location_city || installation.customer.city }}
            </div>

            <div class="pt-2 space-y-1">
              <div v-if="installation.customer.phone">
                <a :href="`tel:${installation.customer.phone}`" class="text-blue-600 hover:underline tabular-nums">
                  {{ installation.customer.phone }}
                </a>
              </div>
              <div v-if="installation.customer.email">
                <a :href="`mailto:${installation.customer.email}`" class="text-blue-600 hover:underline">
                  {{ installation.customer.email }}
                </a>
              </div>
            </div>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>