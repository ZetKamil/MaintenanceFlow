<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { api } from '@/services/api';
import { useAuth } from '@/composables/useAuth';

const { isOwner } = useAuth();

const loading = ref(true);
const error = ref('');
const workOrders = ref([]);
const technicians = ref([]);
const selectedTechId = ref('');
const weekStart = ref(getMonday(new Date()));
const showWeekend = ref(false);

// Reschedule modal state
const editingOrder = ref(null);
const editForm = ref({
    assigned_technician_id: '',
    date: '',
    start_time: '',
    end_time: '',
    status: 'scheduled',
    notes: '',
    cancelled_reason: '',
});
const editError = ref('');
const savingEdit = ref(false);

function getMonday(date) {
    const d = new Date(date);
    const day = d.getDay();
    const diff = day === 0 ? -6 : 1 - day;
    d.setDate(d.getDate() + diff);
    d.setHours(0, 0, 0, 0);
    return d;
}

// Format local date as YYYY-MM-DD (avoids UTC midnight off-by-one shift in Belgium)
function toYmd(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

const weekDays = computed(() => {
    const days = [];
    const todayStr = toYmd(new Date());
    for (let i = 0; i < 7; i++) {
        const d = new Date(weekStart.value);
        d.setDate(d.getDate() + i);
        const ymd = toYmd(d);
        days.push({
            ymd,
            isWeekend: i >= 5,
            isToday: ymd === todayStr,
            weekday: new Intl.DateTimeFormat('en-GB', { weekday: 'short' }).format(d),
            dayNum: new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short' }).format(d),
        });
    }
    return days;
});

const visibleDays = computed(() => (
    showWeekend.value ? weekDays.value : weekDays.value.slice(0, 5)
));

const weekendVisitsCount = computed(() => {
    const weekendYmds = new Set(weekDays.value.slice(5).map((d) => d.ymd));
    return workOrders.value.filter(
        (wo) => wo.scheduled_start_at && weekendYmds.has(toYmd(new Date(wo.scheduled_start_at)))
    ).length;
});

const weekRangeLabel = computed(() => {
    const days = visibleDays.value;
    return `${days[0].dayNum} – ${days[days.length - 1].dayNum}`;
});

const ordersByDay = computed(() => {
    const map = {};
    for (const day of weekDays.value) {
        map[day.ymd] = [];
    }
    for (const wo of workOrders.value) {
        if (!wo.scheduled_start_at) continue;
        const ymd = toYmd(new Date(wo.scheduled_start_at));
        if (map[ymd]) {
            map[ymd].push(wo);
        }
    }
    return map;
});

const matrixRows = computed(() => {
    if (!isOwner.value) return [];

    const visibleTechs = selectedTechId.value
        ? technicians.value.filter((t) => String(t.id) === String(selectedTechId.value))
        : technicians.value;

    const visibleYmds = new Set(visibleDays.value.map((d) => d.ymd));

    const buildCells = (orders) => {
        const cells = {};
        for (const day of visibleDays.value) {
            cells[day.ymd] = [];
        }
        let weekCount = 0;
        for (const wo of orders) {
            if (!wo.scheduled_start_at) continue;
            const ymd = toYmd(new Date(wo.scheduled_start_at));
            if (visibleYmds.has(ymd)) {
                cells[ymd].push(wo);
                weekCount++;
            }
        }
        return { cells, weekCount };
    };

    const rows = visibleTechs.map((tech) => {
        const techOrders = workOrders.value.filter((wo) => wo.technician?.id === tech.id);
        const { cells, weekCount } = buildCells(techOrders);
        return {
            key: `tech-${tech.id}`,
            name: tech.name,
            weekCount,
            cells,
        };
    });

    if (!selectedTechId.value) {
        const unassignedOrders = workOrders.value.filter((wo) => !wo.technician);
        const { cells, weekCount } = buildCells(unassignedOrders);
        if (weekCount > 0) {
            rows.push({
                key: 'unassigned',
                name: 'Unassigned',
                weekCount,
                cells,
            });
        }
    }

    return rows;
});

async function loadPlanning() {
    loading.value = true;
    error.value = '';
    try {
        if (isOwner.value && technicians.value.length === 0) {
            const techRes = await api.get('/api/technicians');
            technicians.value = techRes.technicians;
        }

        const start = weekDays.value[0].ymd;
        const end = weekDays.value[6].ymd;
        let url = `/api/work-orders?start=${start}&end=${end}`;
        if (selectedTechId.value) {
            url += `&technician_id=${selectedTechId.value}`;
        }

        const res = await api.get(url);
        workOrders.value = res.work_orders;
    } catch (err) {
        error.value = err.message || 'Unable to load weekly schedule.';
    } finally {
        loading.value = false;
    }
}

function shiftWeek(days) {
    const d = new Date(weekStart.value);
    d.setDate(d.getDate() + days);
    weekStart.value = d;
}

function goToCurrentWeek() {
    weekStart.value = getMonday(new Date());
}

function openEditModal(wo) {
    editingOrder.value = wo;
    editError.value = '';
    const start = wo.scheduled_start_at ? new Date(wo.scheduled_start_at) : new Date();
    const end = wo.scheduled_end_at ? new Date(wo.scheduled_end_at) : new Date(start.getTime() + 7200000);
    const hhmm = (d) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;

    editForm.value = {
        assigned_technician_id: wo.technician?.id || technicians.value[0]?.id || '',
        date: toYmd(start),
        start_time: hhmm(start),
        end_time: hhmm(end),
        status: wo.status === 'cancelled' ? 'cancelled' : 'scheduled',
        notes: wo.notes || '',
        cancelled_reason: wo.cancelled_reason || '',
    };
}

async function saveEdit() {
    if (!editingOrder.value) return;
    savingEdit.value = true;
    editError.value = '';

    try {
        await api.put(`/api/work-orders/${editingOrder.value.id}`, {
            assigned_technician_id: editForm.value.assigned_technician_id,
            status: editForm.value.status,
            scheduled_start_at: `${editForm.value.date} ${editForm.value.start_time}:00`,
            scheduled_end_at: `${editForm.value.date} ${editForm.value.end_time}:00`,
            notes: editForm.value.notes,
            cancelled_reason: editForm.value.status === 'cancelled' ? editForm.value.cancelled_reason : null,
        });
        editingOrder.value = null;
        await loadPlanning();
    } catch (err) {
        const firstFieldError = Object.values(err.errors || {})[0]?.[0];
        editError.value = firstFieldError || err.message || 'Unable to update appointment.';
    } finally {
        savingEdit.value = false;
    }
}

function formatTime(iso) {
    if (!iso) return '';
    return new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

function eventAccentClass(wo) {
    if (wo.status === 'cancelled') {
        return 'border-l-slate-300 bg-slate-50/70 text-slate-400';
    }
    if (wo.status === 'completed') {
        return 'border-l-emerald-500 bg-emerald-50/45 hover:bg-emerald-50/80';
    }
    if (wo.status === 'in_progress') {
        return 'border-l-blue-600 bg-blue-50/55 hover:bg-blue-50/90';
    }
    if (wo.installation.is_fire_safety) {
        return 'border-l-red-500 bg-red-50/35 hover:bg-red-50/65';
    }
    return 'border-l-slate-700 bg-slate-50/90 hover:bg-slate-100/90';
}

function exceptionBadgeLabel(wo) {
    if (wo.status === 'in_progress') return 'In Progress';
    if (wo.status === 'completed') return 'Done';
    if (wo.status === 'cancelled') return 'Cancelled';
    return '';
}

function exceptionBadgeClass(status) {
    return {
        in_progress: 'text-blue-700 bg-blue-100/70',
        completed: 'text-emerald-700 bg-emerald-100/70',
        cancelled: 'text-slate-400 bg-slate-100',
    }[status] || '';
}

function hasCellConflict(wo, dayOrders) {
    if (wo.status === 'cancelled' || !wo.scheduled_start_at || !wo.scheduled_end_at) {
        return false;
    }
    const start = new Date(wo.scheduled_start_at).getTime();
    const end = new Date(wo.scheduled_end_at).getTime();

    return dayOrders.some((other) => {
        if (other.id === wo.id || other.status === 'cancelled' || !other.scheduled_start_at || !other.scheduled_end_at) {
            return false;
        }
        const oStart = new Date(other.scheduled_start_at).getTime();
        const oEnd = new Date(other.scheduled_end_at).getTime();
        return start < oEnd && end > oStart;
    });
}

watch([weekStart, selectedTechId], loadPlanning);
onMounted(loadPlanning);
</script>

<template>
  <div class="space-y-5">
    <!-- Header & Controls -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold tracking-tight text-slate-900">
          {{ isOwner ? 'Team Planning' : 'My Schedule' }}
        </h1>
        <p class="text-xs text-slate-500 mt-0.5 tabular-nums">{{ weekRangeLabel }}</p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <!-- 5d / 7d toggle -->
        <div class="inline-flex rounded-lg border border-slate-200 bg-white divide-x divide-slate-200 text-xs font-medium">
          <button
            @click="showWeekend = false"
            class="px-3 py-2 cursor-pointer transition"
            :class="!showWeekend ? 'bg-slate-900 text-white rounded-l-[7px]' : 'text-slate-600 hover:bg-slate-50'"
          >
            Mon–Fri
          </button>
          <button
            @click="showWeekend = true"
            class="px-3 py-2 cursor-pointer transition flex items-center gap-1.5"
            :class="showWeekend ? 'bg-slate-900 text-white rounded-r-[7px]' : 'text-slate-600 hover:bg-slate-50'"
          >
            <span>7 Days</span>
            <span
              v-if="weekendVisitsCount > 0"
              class="px-1.5 py-0.5 rounded-full text-[10px] tabular-nums"
              :class="showWeekend ? 'bg-white/20 text-white' : 'bg-amber-100 text-amber-800'"
            >
              {{ weekendVisitsCount }}
            </span>
          </button>
        </div>

        <!-- Week navigation -->
        <div class="inline-flex rounded-lg border border-slate-200 bg-white divide-x divide-slate-200 text-xs font-medium">
          <button @click="shiftWeek(-7)" class="px-3 py-2 hover:bg-slate-50 cursor-pointer">&larr; Prev</button>
          <button @click="goToCurrentWeek" class="px-3 py-2 hover:bg-slate-50 cursor-pointer">This Week</button>
          <button @click="shiftWeek(7)" class="px-3 py-2 hover:bg-slate-50 cursor-pointer">Next &rarr;</button>
        </div>
      </div>
    </div>

    <!-- Technician Filter Pills (Owner only) -->
    <div v-if="isOwner && technicians.length > 0" class="flex flex-wrap items-center gap-1.5">
      <button
        @click="selectedTechId = ''"
        class="px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer"
        :class="selectedTechId === '' ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'"
      >
        All Team
      </button>
      <button
        v-for="tech in technicians"
        :key="tech.id"
        @click="selectedTechId = tech.id"
        class="px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer"
        :class="selectedTechId === tech.id ? 'bg-slate-900 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'"
      >
        {{ tech.name }}
      </button>
    </div>

    <div v-if="error" class="p-4 rounded-lg bg-red-50 border border-red-200 text-sm text-red-700">
      {{ error }}
    </div>

    <!-- OWNER VIEW: Unified Technician x Weekday Dispatch Matrix -->
    <template v-else-if="isOwner">
      <!-- Desktop Matrix (Single Hairline Sheet, Zero Nested Boxes) -->
      <div class="hidden lg:block bg-white rounded-xl border border-slate-200/90 overflow-hidden">
        <!-- Header Row -->
        <div
          class="grid divide-x divide-slate-100 border-b border-slate-200/80 bg-slate-50/70"
          :style="{ gridTemplateColumns: `172px repeat(${visibleDays.length}, minmax(0, 1fr))` }"
        >
          <div class="px-3.5 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
            Technician
          </div>
          <div
            v-for="day in visibleDays"
            :key="day.ymd"
            class="px-3 py-2.5 flex items-center justify-between gap-1 min-w-0"
            :class="day.isToday ? 'bg-slate-900/[0.03]' : ''"
          >
            <span class="text-xs font-semibold" :class="day.isToday ? 'text-slate-900' : 'text-slate-600'">
              {{ day.weekday }}
            </span>
            <span
              class="text-[11px] tabular-nums"
              :class="day.isToday ? 'px-1.5 py-0.5 rounded bg-slate-900 text-white font-medium' : 'text-slate-400'"
            >
              {{ day.dayNum }}
            </span>
          </div>
        </div>

        <!-- Loading Skeleton -->
        <div v-if="loading" class="p-6 space-y-3">
          <div v-for="n in 4" :key="n" class="h-14 rounded-lg bg-slate-100 animate-pulse"></div>
        </div>

        <!-- Technician Rows -->
        <div v-else class="divide-y divide-slate-100">
          <div
            v-for="row in matrixRows"
            :key="row.key"
            class="grid divide-x divide-slate-100"
            :style="{ gridTemplateColumns: `172px repeat(${visibleDays.length}, minmax(0, 1fr))` }"
          >
            <!-- Row Header: Technician -->
            <div class="px-3.5 py-3 flex flex-col justify-start min-w-0 bg-slate-50/30">
              <div class="text-xs font-semibold text-slate-900 truncate" :title="row.name">
                {{ row.name }}
              </div>
              <div class="text-[11px] mt-0.5 tabular-nums" :class="row.weekCount === 0 ? 'text-emerald-600' : 'text-slate-400'">
                {{ row.weekCount === 0 ? 'Available' : `${row.weekCount} ${row.weekCount === 1 ? 'visit' : 'visits'}` }}
              </div>
            </div>

            <!-- Day Cells -->
            <div
              v-for="day in visibleDays"
              :key="day.ymd"
              class="p-1.5 space-y-1.5 min-h-[78px] min-w-0"
              :class="day.isToday ? 'bg-slate-900/[0.015]' : ''"
            >
              <div
                v-for="wo in row.cells[day.ymd]"
                :key="wo.id"
                class="group rounded-md px-2 py-1.5 border-l-2 transition min-w-0"
                :class="eventAccentClass(wo)"
              >
                <div class="flex items-center justify-between gap-1.5 min-w-0">
                  <div class="flex items-center gap-1 min-w-0">
                    <span class="text-[11px] font-semibold text-slate-700 tabular-nums truncate">
                      {{ formatTime(wo.scheduled_start_at) }}–{{ formatTime(wo.scheduled_end_at) }}
                    </span>
                    <span
                      v-if="wo.installation.is_fire_safety"
                      class="w-1.5 h-1.5 rounded-full bg-red-500 shrink-0"
                      title="Fire Detection (BRAND)"
                    ></span>
                  </div>

                  <button
                    v-if="!wo.is_terminal"
                    @click.stop.prevent="openEditModal(wo)"
                    class="text-[10px] font-medium text-slate-400 hover:text-slate-900 shrink-0 cursor-pointer"
                  >
                    Move
                  </button>
                </div>

                <RouterLink
                  :to="`/work-orders/${wo.id}`"
                  :title="wo.installation.customer_name"
                  class="mt-0.5 block text-xs font-semibold text-slate-900 hover:text-blue-600 leading-snug [overflow-wrap:anywhere] line-clamp-2 transition-colors"
                  :class="wo.status === 'cancelled' ? 'line-through text-slate-400' : ''"
                >
                  {{ wo.installation.customer_name }}
                </RouterLink>

                <div class="mt-0.5 flex items-center justify-between gap-1 min-w-0">
                  <span v-if="wo.installation.location_city" class="text-[10px] text-slate-400 truncate">
                    {{ wo.installation.location_city }}
                  </span>
                  <div class="flex items-center gap-1 shrink-0">
                    <span
                      v-if="hasCellConflict(wo, row.cells[day.ymd])"
                      title="Double-booked time slot in legacy schedule"
                      class="px-1.5 py-0.5 rounded text-[10px] font-medium text-amber-800 bg-amber-100"
                    >
                      Overlap
                    </span>
                    <span
                      v-if="exceptionBadgeLabel(wo)"
                      class="px-1.5 py-0.5 rounded text-[10px] font-medium"
                      :class="exceptionBadgeClass(wo.status)"
                    >
                      {{ exceptionBadgeLabel(wo) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Mobile Agenda Fallback (< lg) -->
      <div class="lg:hidden bg-white rounded-xl border border-slate-200/90 divide-y divide-slate-100 overflow-hidden">
        <div v-for="day in visibleDays" :key="day.ymd" class="p-4 space-y-2.5">
          <div class="flex items-center justify-between">
            <span class="text-xs font-semibold text-slate-900">{{ day.weekday }}, {{ day.dayNum }}</span>
            <span v-if="day.isToday" class="px-1.5 py-0.5 rounded bg-slate-900 text-white text-[10px] font-medium">Today</span>
          </div>

          <div v-if="ordersByDay[day.ymd].length === 0" class="text-xs text-slate-400">
            No scheduled visits.
          </div>

          <div v-else class="space-y-2">
            <div
              v-for="wo in ordersByDay[day.ymd]"
              :key="wo.id"
              class="rounded-md px-3 py-2 border-l-2 flex items-center justify-between gap-3"
              :class="eventAccentClass(wo)"
            >
              <RouterLink :to="`/work-orders/${wo.id}`" class="min-w-0 flex-1 space-y-0.5">
                <div class="flex items-center gap-2 text-[11px] font-semibold text-slate-600 tabular-nums">
                  <span>{{ formatTime(wo.scheduled_start_at) }}–{{ formatTime(wo.scheduled_end_at) }}</span>
                  <span v-if="wo.installation.is_fire_safety" class="text-[10px] text-red-600 font-semibold">FIRE</span>
                  <span v-if="exceptionBadgeLabel(wo)" class="px-1.5 rounded text-[10px]" :class="exceptionBadgeClass(wo.status)">
                    {{ exceptionBadgeLabel(wo) }}
                  </span>
                </div>
                <div class="text-xs font-semibold text-slate-900 truncate">{{ wo.installation.customer_name }}</div>
                <div class="text-[11px] text-slate-500">
                  {{ wo.technician?.name || 'Unassigned' }}
                  <span v-if="wo.installation.location_city"> · {{ wo.installation.location_city }}</span>
                </div>
              </RouterLink>

              <button
                v-if="!wo.is_terminal"
                @click="openEditModal(wo)"
                class="px-2.5 py-1 rounded border border-slate-200 bg-white text-[11px] font-medium text-slate-700 shrink-0 cursor-pointer"
              >
                Move
              </button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- TECHNICIAN VIEW: Unified Mon-Fri Hairline Calendar (No Nested Boxes) -->
    <template v-else>
      <div class="bg-white rounded-xl border border-slate-200/90 overflow-hidden">
          <div
            class="hidden lg:grid divide-x divide-slate-100 border-b border-slate-200/80 bg-slate-50/70"
            :style="{ gridTemplateColumns: `repeat(${visibleDays.length}, minmax(0, 1fr))` }"
          >
            <div
              v-for="day in visibleDays"
              :key="day.ymd"
              class="px-3 py-2.5 flex items-center justify-between gap-1 min-w-0"
              :class="day.isToday ? 'bg-slate-900/[0.03]' : ''"
            >
              <span class="text-xs font-semibold" :class="day.isToday ? 'text-slate-900' : 'text-slate-600'">{{ day.weekday }}</span>
              <span class="text-[11px] tabular-nums" :class="day.isToday ? 'px-1.5 py-0.5 rounded bg-slate-900 text-white font-medium' : 'text-slate-400'">
                {{ day.dayNum }}
              </span>
            </div>
          </div>

          <div
            class="hidden lg:grid divide-x divide-slate-100 min-h-[220px]"
            :style="{ gridTemplateColumns: `repeat(${visibleDays.length}, minmax(0, 1fr))` }"
          >
            <div v-for="day in visibleDays" :key="day.ymd" class="p-2 space-y-2 min-w-0">
              <div v-if="loading" class="h-14 rounded bg-slate-100 animate-pulse"></div>
              <div v-else-if="ordersByDay[day.ymd].length === 0" class="py-8 text-center text-[11px] text-slate-300">—</div>
              <RouterLink
                v-else
                v-for="wo in ordersByDay[day.ymd]"
                :key="wo.id"
                :to="`/work-orders/${wo.id}`"
                class="block rounded-md px-2.5 py-2 border-l-2 transition min-w-0 space-y-0.5"
                :class="eventAccentClass(wo)"
              >
                <div class="flex items-center justify-between gap-1">
                  <span class="text-[11px] font-semibold text-slate-700 tabular-nums">
                    {{ formatTime(wo.scheduled_start_at) }}–{{ formatTime(wo.scheduled_end_at) }}
                  </span>
                  <span v-if="wo.installation.is_fire_safety" class="w-1.5 h-1.5 rounded-full bg-red-500 shrink-0"></span>
                </div>
                <div class="text-xs font-semibold text-slate-900 leading-snug [overflow-wrap:anywhere] line-clamp-2">
                  {{ wo.installation.customer_name }}
                </div>
                <div v-if="wo.installation.location_city" class="text-[11px] text-slate-500 truncate">
                  {{ wo.installation.location_city }}
                </div>
                <span
                  v-if="exceptionBadgeLabel(wo)"
                  class="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium"
                  :class="exceptionBadgeClass(wo.status)"
                >
                  {{ exceptionBadgeLabel(wo) }}
                </span>
              </RouterLink>
            </div>
          </div>

          <!-- Technician Mobile List -->
          <div class="lg:hidden divide-y divide-slate-100">
            <div v-for="day in visibleDays" :key="day.ymd" class="p-4 space-y-2">
              <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-900">{{ day.weekday }}, {{ day.dayNum }}</span>
                <span v-if="day.isToday" class="px-1.5 py-0.5 rounded bg-slate-900 text-white text-[10px] font-medium">Today</span>
              </div>
              <div v-if="ordersByDay[day.ymd].length === 0" class="text-xs text-slate-400">No visits</div>
              <RouterLink
                v-else
                v-for="wo in ordersByDay[day.ymd]"
                :key="wo.id"
                :to="`/work-orders/${wo.id}`"
                class="block rounded-md px-3 py-2 border-l-2 space-y-0.5"
                :class="eventAccentClass(wo)"
              >
                <div class="flex items-center gap-2 text-[11px] font-semibold text-slate-600 tabular-nums">
                  <span>{{ formatTime(wo.scheduled_start_at) }}–{{ formatTime(wo.scheduled_end_at) }}</span>
                  <span v-if="wo.installation.is_fire_safety" class="text-[10px] text-red-600">FIRE</span>
                </div>
                <div class="text-xs font-semibold text-slate-900">{{ wo.installation.customer_name }}</div>
                <div v-if="wo.installation.location_city" class="text-[11px] text-slate-500">{{ wo.installation.location_city }}</div>
              </RouterLink>
            </div>
          </div>
      </div>
    </template>

    <!-- Reschedule / Move Modal -->
    <div v-if="editingOrder" class="fixed inset-0 z-50 bg-slate-900/40 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl border border-slate-200 shadow-xl w-full max-w-md p-6 space-y-4">
        <div class="flex items-start justify-between">
          <div>
            <h3 class="text-base font-semibold text-slate-900">Reschedule Visit</h3>
            <p class="text-xs text-slate-500 mt-0.5">{{ editingOrder.installation.customer_name }} · {{ editingOrder.installation.name }}</p>
          </div>
          <button @click="editingOrder = null" class="text-slate-400 hover:text-slate-600 text-lg leading-none cursor-pointer">&times;</button>
        </div>

        <div v-if="editError" class="p-3 rounded-lg bg-red-50 border border-red-200 text-xs text-red-700">
          {{ editError }}
        </div>

        <form @submit.prevent="saveEdit" class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-slate-700 mb-1">Technician</label>
              <select v-model="editForm.assigned_technician_id" required class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm">
                <option v-for="tech in technicians" :key="tech.id" :value="tech.id">{{ tech.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 mb-1">Status</label>
              <select v-model="editForm.status" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm">
                <option value="scheduled">Scheduled</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
          </div>

          <div v-if="editForm.status === 'scheduled'" class="grid grid-cols-3 gap-2">
            <div>
              <label class="block text-xs font-medium text-slate-700 mb-1">Date</label>
              <input v-model="editForm.date" type="date" required class="w-full px-2.5 py-2 rounded-lg border border-slate-300 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 mb-1">Start</label>
              <input v-model="editForm.start_time" type="time" required class="w-full px-2.5 py-2 rounded-lg border border-slate-300 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-700 mb-1">End</label>
              <input v-model="editForm.end_time" type="time" required class="w-full px-2.5 py-2 rounded-lg border border-slate-300 text-sm" />
            </div>
          </div>

          <div v-else>
            <label class="block text-xs font-medium text-slate-700 mb-1">Cancellation Reason</label>
            <input v-model="editForm.cancelled_reason" type="text" required placeholder="Client requested postponement" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm" />
          </div>

          <div>
            <label class="block text-xs font-medium text-slate-700 mb-1">Notes</label>
            <textarea v-model="editForm.notes" rows="2" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm"></textarea>
          </div>

          <div class="pt-2 flex justify-end gap-2">
            <button type="button" @click="editingOrder = null" class="px-4 py-2 rounded-lg border border-slate-200 text-xs font-medium text-slate-700 hover:bg-slate-50 cursor-pointer">
              Close
            </button>
            <button type="submit" :disabled="savingEdit" class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 disabled:opacity-50 text-white text-xs font-medium cursor-pointer">
              {{ savingEdit ? 'Saving...' : 'Save Changes' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
