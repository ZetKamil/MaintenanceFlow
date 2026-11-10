<script setup>
import { computed, onMounted, ref } from 'vue';
import { api } from '@/services/api';
import { useAuth } from '@/composables/useAuth';

const { user, isOwner } = useAuth();

const loading = ref(true);
const error = ref('');
const dashboard = ref(null);
const technicians = ref([]);
const activeTab = ref('all');

const schedulingItem = ref(null);
const scheduleForm = ref({
    technician_id: '',
    date: new Date(Date.now() + 86400000).toISOString().slice(0, 10),
    start_time: '08:30',
    end_time: '11:00',
    notes: '',
});
const scheduleError = ref('');
const savingSchedule = ref(false);

const todayLabel = new Intl.DateTimeFormat('en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
}).format(new Date());

const filteredQueue = computed(() => {
    const list = dashboard.value?.needs_contact_list || [];
    if (activeTab.value === 'fire') return list.filter((i) => i.is_fire_safety);
    if (activeTab.value === 'overdue') return list.filter((i) => i.is_overdue);
    return list;
});

const maxTechVisits = computed(() => {
    const counts = (dashboard.value?.team_week_load || []).map((t) => t.week_visits);
    return Math.max(5, ...counts);
});

async function loadDashboard() {
    loading.value = true;
    error.value = '';
    try {
        dashboard.value = await api.get('/api/dashboard');
        if (isOwner.value && technicians.value.length === 0) {
            const techRes = await api.get('/api/technicians');
            technicians.value = techRes.technicians;
        }
    } catch (err) {
        error.value = err.message || 'Unable to load dashboard data.';
    } finally {
        loading.value = false;
    }
}

function openScheduleModal(item) {
    schedulingItem.value = item;
    scheduleError.value = '';
    scheduleForm.value = {
        technician_id: technicians.value[0]?.id || '',
        date: new Date(Date.now() + 86400000).toISOString().slice(0, 10),
        start_time: '08:30',
        end_time: '11:00',
        notes: item.notes || '',
    };
}

async function submitSchedule() {
    if (!schedulingItem.value) return;
    savingSchedule.value = true;
    scheduleError.value = '';

    const payload = {
        assigned_technician_id: scheduleForm.value.technician_id,
        status: 'scheduled',
        scheduled_start_at: `${scheduleForm.value.date} ${scheduleForm.value.start_time}:00`,
        scheduled_end_at: `${scheduleForm.value.date} ${scheduleForm.value.end_time}:00`,
        notes: scheduleForm.value.notes,
    };

    try {
        if (schedulingItem.value.open_work_order_id) {
            await api.put(`/api/work-orders/${schedulingItem.value.open_work_order_id}`, payload);
        } else {
            await api.post('/api/work-orders', {
                ...payload,
                installation_id: schedulingItem.value.installation_id,
                maintenance_plan_id: schedulingItem.value.plan_id,
            });
        }
        schedulingItem.value = null;
        await loadDashboard();
    } catch (err) {
        const firstFieldError = Object.values(err.errors || {})[0]?.[0];
        scheduleError.value = firstFieldError || err.message || 'Could not schedule appointment.';
    } finally {
        savingSchedule.value = false;
    }
}

function typeLabel(type) {
    return {
        BRAND: 'Fire Detection',
        INBR: 'Intrusion Alarm',
        CAM: 'Camera / CCTV',
        TOEG: 'Access Control',
    }[type] || 'Security System';
}

function formatDate(dateStr) {
    if (!dateStr) return 'No date';
    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(dateStr));
}

function formatVisitSlot(startIso, endIso) {
    if (!startIso) return '';
    const d = new Date(startIso);
    const day = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }).format(d);
    const t = (iso) => new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
    return `${day} · ${t(startIso)}${endIso ? `–${t(endIso)}` : ''}`;
}

onMounted(loadDashboard);
</script>

<template>
  <div class="space-y-6">
    <!-- Top Workspace Greeting -->
    <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-2">
      <div>
        <p class="text-xs font-medium text-slate-400">{{ todayLabel }}</p>
        <h1 class="text-xl font-semibold tracking-tight text-slate-900 mt-0.5">
          {{ isOwner ? 'Operations Overview' : `Good day, ${user?.name}` }}
        </h1>
      </div>
      <RouterLink
        v-if="isOwner"
        to="/planning"
        class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-600 hover:text-slate-900 transition"
      >
        <span>Open Weekly Calendar</span>
        <span aria-hidden="true">&rarr;</span>
      </RouterLink>
    </div>

    <!-- Error State -->
    <div v-if="error" class="p-4 rounded-lg bg-red-50 border border-red-200 flex items-center justify-between text-sm text-red-700">
      <span>{{ error }}</span>
      <button @click="loadDashboard" class="font-semibold underline cursor-pointer">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <div v-else-if="loading" class="space-y-5">
      <div class="h-24 bg-white rounded-xl border border-slate-200 animate-pulse"></div>
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <div class="lg:col-span-8 h-80 bg-white rounded-xl border border-slate-200 animate-pulse"></div>
        <div class="lg:col-span-4 h-80 bg-white rounded-xl border border-slate-200 animate-pulse"></div>
      </div>
    </div>

    <!-- OWNER VIEW (DIRK) -->
    <template v-else-if="dashboard?.role === 'owner'">
      <!-- Unified Command Strip -->
      <div class="bg-white rounded-xl border border-slate-200/90 grid grid-cols-2 lg:grid-cols-4 divide-y lg:divide-y-0 lg:divide-x divide-slate-100 tabular-nums">
        <div class="px-5 py-4">
          <div class="text-xs text-slate-500">Overdue</div>
          <div class="mt-1.5 flex items-baseline gap-2">
            <span class="text-2xl font-semibold tracking-tight" :class="dashboard.kpis.overdue_count > 0 ? 'text-red-600' : 'text-slate-900'">
              {{ dashboard.kpis.overdue_count }}
            </span>
            <span class="text-xs text-slate-400">plans past due</span>
          </div>
        </div>

        <div class="px-5 py-4">
          <div class="text-xs text-slate-500">Fire Safety Unscheduled</div>
          <div class="mt-1.5 flex items-baseline gap-2">
            <span class="text-2xl font-semibold tracking-tight" :class="dashboard.kpis.brand_at_risk_count > 0 ? 'text-red-600' : 'text-slate-900'">
              {{ dashboard.kpis.brand_at_risk_count }}
            </span>
            <span class="text-xs text-slate-400">annual legal req.</span>
          </div>
        </div>

        <div class="px-5 py-4">
          <div class="text-xs text-slate-500">Due within 30 Days</div>
          <div class="mt-1.5 flex items-baseline gap-2">
            <span class="text-2xl font-semibold tracking-tight text-slate-900">
              {{ dashboard.kpis.due_soon_count }}
            </span>
            <span class="text-xs text-slate-400">upcoming</span>
          </div>
        </div>

        <div class="px-5 py-4">
          <div class="text-xs text-slate-500">Scheduled This Week</div>
          <div class="mt-1.5 flex items-baseline gap-2">
            <span class="text-2xl font-semibold tracking-tight text-slate-900">
              {{ dashboard.kpis.scheduled_this_week_count }}
            </span>
            <span class="text-xs text-slate-400">visits booked</span>
          </div>
        </div>
      </div>

      <!-- Asymmetric 8/4 Cockpit Grid -->
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- LEFT (2/3): Follow-up Inbox -->
        <section class="lg:col-span-8 bg-white rounded-xl border border-slate-200/90 overflow-hidden">
          <div class="px-5 py-3.5 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
              <h2 class="text-sm font-semibold text-slate-900">Needs Contact</h2>
              <span class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-600 tabular-nums">
                {{ filteredQueue.length }}
              </span>
            </div>

            <div class="inline-flex rounded-lg bg-slate-100 p-0.5 text-xs font-medium">
              <button
                @click="activeTab = 'all'"
                class="px-2.5 py-1 rounded-md transition cursor-pointer"
                :class="activeTab === 'all' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-500 hover:text-slate-700'"
              >
                Priority All
              </button>
              <button
                @click="activeTab = 'fire'"
                class="px-2.5 py-1 rounded-md transition cursor-pointer"
                :class="activeTab === 'fire' ? 'bg-white text-red-600 shadow-xs' : 'text-slate-500 hover:text-slate-700'"
              >
                Fire Only
              </button>
              <button
                @click="activeTab = 'overdue'"
                class="px-2.5 py-1 rounded-md transition cursor-pointer"
                :class="activeTab === 'overdue' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-500 hover:text-slate-700'"
              >
                Overdue
              </button>
            </div>
          </div>

          <div v-if="filteredQueue.length === 0" class="p-10 text-center text-sm text-slate-500">
            Nothing needs follow-up in this filter.
          </div>

          <ul v-else class="divide-y divide-slate-100">
            <li
              v-for="item in filteredQueue"
              :key="item.plan_id"
              class="relative px-5 py-3.5 hover:bg-slate-50/70 transition flex flex-col sm:flex-row sm:items-center justify-between gap-3"
            >
              <!-- Subtle priority edge bar -->
              <span
                class="absolute left-0 top-2 bottom-2 w-0.5 rounded-r"
                :class="item.is_fire_safety && item.is_overdue ? 'bg-red-500' : (item.is_overdue ? 'bg-amber-400' : 'bg-slate-200')"
              ></span>

              <div class="min-w-0 space-y-0.5 pl-1">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-sm font-medium text-slate-900 truncate">{{ item.customer_name }}</span>
                  <span v-if="item.location_city" class="text-xs text-slate-400">· {{ item.location_city }}</span>
                  <span
                    v-if="item.is_fire_safety"
                    class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-50 text-red-700 border border-red-200/80"
                  >
                    FIRE
                  </span>
                </div>

                <div class="text-xs text-slate-500 flex items-center gap-2 flex-wrap">
                  <RouterLink :to="`/installations/${item.installation_id}`" class="hover:text-slate-900 hover:underline">
                    {{ item.installation_name }}
                  </RouterLink>
                  <span class="text-slate-300">·</span>
                  <span>{{ typeLabel(item.installation_type) }}</span>
                  <template v-if="item.last_completed_at">
                    <span class="text-slate-300">·</span>
                    <span class="tabular-nums">Last: {{ formatDate(item.last_completed_at) }}</span>
                  </template>
                </div>
              </div>

              <div class="flex items-center justify-between sm:justify-end gap-3 shrink-0 pl-1">
                <div class="text-left sm:text-right tabular-nums">
                  <div v-if="item.is_overdue" class="text-xs font-semibold text-red-600">
                    {{ item.days_overdue }}d overdue
                  </div>
                  <div v-else class="text-xs font-medium text-slate-700">
                    {{ formatDate(item.next_due_at) }}
                  </div>
                  <div v-if="item.is_overdue" class="text-[11px] text-slate-400">
                    {{ formatDate(item.next_due_at) }}
                  </div>
                </div>

                <div class="flex items-center gap-1.5">
                  <a
                    v-if="item.customer_phone"
                    :href="`tel:${item.customer_phone}`"
                    :title="`Call ${item.customer_phone}`"
                    class="p-2 rounded-lg border border-slate-200 text-slate-500 hover:text-slate-900 hover:bg-white transition"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                    </svg>
                  </a>

                  <button
                    @click="openScheduleModal(item)"
                    class="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs font-medium transition cursor-pointer"
                  >
                    Schedule
                  </button>
                </div>
              </div>
            </li>
          </ul>
        </section>

        <!-- RIGHT (1/3): Crew & Schedule Pulse -->
        <aside class="lg:col-span-4 space-y-5">
          <!-- Crew Week Load -->
          <section class="bg-white rounded-xl border border-slate-200/90 p-5 space-y-4">
            <div class="flex items-center justify-between">
              <h2 class="text-sm font-semibold text-slate-900">Team This Week</h2>
              <span class="text-[11px] text-slate-400">Booked visits</span>
            </div>

            <div class="space-y-3">
              <div v-for="tech in dashboard.team_week_load" :key="tech.id" class="space-y-1.5">
                <div class="flex items-center justify-between text-xs">
                  <span class="font-medium text-slate-700">{{ tech.name }}</span>
                  <span class="text-slate-500 tabular-nums">{{ tech.week_visits }}</span>
                </div>
                <div class="h-1.5 w-full bg-slate-100 rounded-full overflow-hidden">
                  <div
                    class="h-full bg-slate-800 rounded-full transition-all"
                    :style="{ width: `${Math.min(100, (tech.week_visits / maxTechVisits) * 100)}%` }"
                  ></div>
                </div>
              </div>
            </div>
          </section>

          <!-- Upcoming Calendar Preview -->
          <section class="bg-white rounded-xl border border-slate-200/90 p-5 space-y-4">
            <div class="flex items-center justify-between">
              <h2 class="text-sm font-semibold text-slate-900">Next Scheduled</h2>
              <RouterLink to="/planning" class="text-xs font-medium text-blue-600 hover:underline">
                View all
              </RouterLink>
            </div>

            <div v-if="dashboard.upcoming_week_visits.length === 0" class="text-xs text-slate-500 py-3">
              No visits scheduled yet.
            </div>

            <div v-else class="space-y-3">
              <RouterLink
                v-for="visit in dashboard.upcoming_week_visits"
                :key="visit.id"
                :to="`/work-orders/${visit.id}`"
                class="block p-3 rounded-lg border border-slate-100 hover:border-slate-200 hover:bg-slate-50/60 transition space-y-1"
              >
                <div class="flex items-center justify-between gap-2">
                  <span class="text-[11px] font-medium text-slate-500 tabular-nums">
                    {{ formatVisitSlot(visit.scheduled_start_at, visit.scheduled_end_at) }}
                  </span>
                  <span v-if="visit.installation_type === 'BRAND'" class="w-1.5 h-1.5 rounded-full bg-red-500" title="Fire Detection"></span>
                </div>
                <div class="text-xs font-semibold text-slate-900 truncate">{{ visit.customer_name }}</div>
                <div class="text-[11px] text-slate-500">{{ visit.technician_name }}</div>
              </RouterLink>
            </div>
          </section>
        </aside>
      </div>
    </template>

    <!-- TECHNICIAN VIEW -->
    <template v-else-if="dashboard?.role === 'technician'">
      <div class="bg-white rounded-xl border border-slate-200/90 grid grid-cols-3 divide-x divide-slate-100 tabular-nums">
        <div class="px-4 py-3.5">
          <div class="text-xs text-slate-500">Today</div>
          <div class="mt-1 text-2xl font-semibold text-slate-900">{{ dashboard.kpis.today_count }}</div>
        </div>
        <div class="px-4 py-3.5">
          <div class="text-xs text-slate-500">This Week</div>
          <div class="mt-1 text-2xl font-semibold text-slate-900">{{ dashboard.kpis.week_count }}</div>
        </div>
        <div class="px-4 py-3.5">
          <div class="text-xs text-slate-500">Completed</div>
          <div class="mt-1 text-2xl font-semibold text-slate-900">{{ dashboard.kpis.completed_count }}</div>
        </div>
      </div>

      <section class="space-y-3">
        <h2 class="text-sm font-semibold text-slate-900">Your Upcoming Visits</h2>

        <div v-if="dashboard.upcoming_work_orders.length === 0" class="bg-white rounded-xl border border-slate-200 p-8 text-center text-sm text-slate-500">
          No upcoming visits assigned.
        </div>

        <div
          v-for="wo in dashboard.upcoming_work_orders"
          :key="wo.id"
          class="bg-white rounded-xl border border-slate-200/90 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4"
        >
          <div class="space-y-1">
            <div class="flex items-center gap-2">
              <span class="text-xs font-medium text-slate-500 tabular-nums">
                {{ formatVisitSlot(wo.scheduled_start_at, wo.scheduled_end_at) }}
              </span>
              <span v-if="wo.installation_type === 'BRAND'" class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-50 text-red-700 border border-red-200">
                FIRE
              </span>
            </div>
            <div class="font-semibold text-slate-900 text-sm">{{ wo.customer_name }}</div>
            <div class="text-xs text-slate-600">{{ wo.installation_name }}</div>
            <div class="text-xs text-slate-400">{{ wo.location_address }}</div>
          </div>

          <div class="flex items-center gap-2">
            <a
              v-if="wo.customer_phone"
              :href="`tel:${wo.customer_phone}`"
              class="px-4 py-2.5 rounded-lg border border-slate-200 text-xs font-medium text-slate-700 hover:bg-slate-50"
            >
              Call
            </a>
            <RouterLink
              :to="`/work-orders/${wo.id}`"
              class="px-4 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs font-medium transition"
            >
              Open Visit
            </RouterLink>
          </div>
        </div>
      </section>
    </template>

    <!-- Quick Schedule Modal -->
    <div v-if="schedulingItem" class="fixed inset-0 z-50 bg-slate-900/40 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl border border-slate-200 shadow-xl w-full max-w-md p-6 space-y-4">
        <div class="flex items-start justify-between">
          <div>
            <h3 class="text-base font-semibold text-slate-900">Schedule Maintenance</h3>
            <p class="text-xs text-slate-500 mt-0.5">{{ schedulingItem.customer_name }} · {{ schedulingItem.installation_name }}</p>
          </div>
          <button @click="schedulingItem = null" class="text-slate-400 hover:text-slate-600 text-lg leading-none cursor-pointer">&times;</button>
        </div>

        <div v-if="scheduleError" class="p-3 rounded-lg bg-red-50 border border-red-200 text-xs text-red-700">
          {{ scheduleError }}
        </div>

        <form @submit.prevent="submitSchedule" class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-slate-700 mb-1">Technician</label>
            <select v-model="scheduleForm.technician_id" required class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm">
              <option v-for="tech in technicians" :key="tech.id" :value="tech.id">{{ tech.name }}</option>
            </select>
          </div>

          <div class="grid grid-cols-3 gap-2">
            <div>
              <label class="block text-xs font-medium text-slate-700 mb-1">Date</label>
              <input v-model="scheduleForm.date" type="date" required class="w-full px-2.5 py-2 rounded-lg border border-slate-300 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 mb-1">Start</label>
              <input v-model="scheduleForm.start_time" type="time" required class="w-full px-2.5 py-2 rounded-lg border border-slate-300 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 mb-1">End</label>
              <input v-model="scheduleForm.end_time" type="time" required class="w-full px-2.5 py-2 rounded-lg border border-slate-300 text-sm" />
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-slate-700 mb-1">Notes</label>
            <textarea v-model="scheduleForm.notes" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm" placeholder="Optional gate code or instructions..."></textarea>
          </div>

          <div class="pt-2 flex justify-end gap-2">
            <button type="button" @click="schedulingItem = null" class="px-4 py-2 rounded-lg border border-slate-200 text-xs font-medium text-slate-700 hover:bg-slate-50 cursor-pointer">
              Cancel
            </button>
            <button type="submit" :disabled="savingSchedule" class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 disabled:opacity-50 text-white text-xs font-medium cursor-pointer">
              {{ savingSchedule ? 'Saving...' : 'Confirm Appointment' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
