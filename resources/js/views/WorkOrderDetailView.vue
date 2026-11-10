<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/services/api';

const route = useRoute();
const router = useRouter();

const loading = ref(true);
const error = ref('');
const actionError = ref('');
const actionSuccess = ref('');
const starting = ref(false);
const submitting = ref(false);
const showComponents = ref(false);

const workOrder = ref(null);

const form = ref({
    performed_at: todayYmd(),
    performed_work: '',
    findings: '',
    recommendations: '',
    next_due_at: '',
    materials: [],
});

function todayYmd() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function addMonthsYmd(ymd, months) {
    if (!ymd || !months || months <= 0) return '';
    const [y, m, d] = ymd.split('-').map(Number);
    const targetMonthIndex = (m - 1) + months;
    const targetYear = y + Math.floor(targetMonthIndex / 12);
    const targetMonth = ((targetMonthIndex % 12) + 12) % 12;
    const maxDay = new Date(targetYear, targetMonth + 1, 0).getDate();
    const clampedDay = Math.min(d, maxDay);
    return `${targetYear}-${String(targetMonth + 1).padStart(2, '0')}-${String(clampedDay).padStart(2, '0')}`;
}

const canCompleteVisit = computed(() => {
    if (!workOrder.value) return false;
    return !workOrder.value.is_terminal
        && !workOrder.value.report
        && ['scheduled', 'in_progress'].includes(workOrder.value.status);
});

const fullAddress = computed(() => {
    const inst = workOrder.value?.installation;
    if (!inst) return '';
    const cityLine = [inst.location_postal_code, inst.location_city].filter(Boolean).join(' ');
    return [inst.location_street, cityLine].filter(Boolean).join(', ');
});

async function loadWorkOrder() {
    loading.value = true;
    error.value = '';
    try {
        const res = await api.get(`/api/work-orders/${route.params.id}`);
        workOrder.value = res.work_order;

        const interval = workOrder.value.maintenance_plan?.interval_months || 0;
        form.value.performed_at = todayYmd();
        form.value.next_due_at = interval > 0 ? addMonthsYmd(form.value.performed_at, interval) : '';
    } catch (err) {
        error.value = err.message || 'Unable to load work order.';
    } finally {
        loading.value = false;
    }
}

async function handleStartVisit() {
    actionError.value = '';
    actionSuccess.value = '';
    starting.value = true;
    try {
        await api.post(`/api/work-orders/${workOrder.value.id}/start`, {});
        actionSuccess.value = 'Visit marked as in progress.';
        await loadWorkOrder();
    } catch (err) {
        actionError.value = err.message || 'Could not start this visit.';
    } finally {
        starting.value = false;
    }
}

function addMaterialRow() {
    form.value.materials.push({
        material_code: '',
        description: '',
        quantity: '1',
    });
}

function removeMaterialRow(index) {
    form.value.materials.splice(index, 1);
}

async function handleSubmitReport() {
    actionError.value = '';
    actionSuccess.value = '';

    const normalizedMaterials = [];
    for (const row of form.value.materials) {
        const desc = row.description.trim();
        const code = row.material_code.trim();
        if (!desc && !code) continue;

        if (!desc) {
            actionError.value = 'Please provide a description for every added material.';
            return;
        }

        const qty = Number(String(row.quantity).replace(',', '.').trim());
        if (!Number.isFinite(qty) || qty <= 0) {
            actionError.value = `Invalid quantity for "${desc}".`;
            return;
        }

        normalizedMaterials.push({
            material_code: code || null,
            description: desc,
            quantity: qty,
        });
    }

    submitting.value = true;
    try {
        await api.post(`/api/work-orders/${workOrder.value.id}/report`, {
            performed_at: form.value.performed_at || null,
            performed_work: form.value.performed_work.trim(),
            findings: form.value.findings.trim() || null,
            recommendations: form.value.recommendations.trim() || null,
            next_due_at: form.value.next_due_at || null,
            materials: normalizedMaterials,
        });

        actionSuccess.value = 'Service report saved and maintenance cycle updated.';
        await loadWorkOrder();
    } catch (err) {
        actionError.value = err.message || 'Unable to submit service report.';
    } finally {
        submitting.value = false;
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

function formatSlot(startStr, endStr) {
    if (!startStr) return 'Not scheduled';
    const start = new Date(startStr);
    const datePart = new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(start);
    const timeFmt = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' });
    const startTime = timeFmt.format(start);
    const endTime = endStr ? timeFmt.format(new Date(endStr)) : '';
    return endTime ? `${datePart} · ${startTime}–${endTime}` : `${datePart} · ${startTime}`;
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

watch(() => route.params.id, loadWorkOrder);
onMounted(loadWorkOrder);
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
      <div class="h-28 bg-white rounded-xl border border-slate-200 animate-pulse"></div>
      <div class="h-80 bg-white rounded-xl border border-slate-200 animate-pulse"></div>
    </div>

    <template v-else-if="workOrder">
      <!-- Top Visit Context Card (Mobile-first) -->
      <section class="bg-white rounded-xl border border-slate-200/90 p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="space-y-1.5 min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="px-2 py-0.5 rounded text-[11px] font-medium" :class="statusPillClass(workOrder.status)">
              {{ workOrder.status_label }}
            </span>
            <span
              v-if="workOrder.installation.is_fire_safety"
              class="px-2 py-0.5 rounded text-[11px] font-semibold bg-red-50 text-red-700 border border-red-200/80"
            >
              FIRE (BRAND)
            </span>
            <span class="text-xs text-slate-500 tabular-nums">
              {{ formatSlot(workOrder.scheduled_start_at, workOrder.scheduled_end_at) }}
            </span>
          </div>

          <h1 class="text-xl font-semibold tracking-tight text-slate-900">
            {{ workOrder.installation.customer_name }}
          </h1>

          <div class="text-xs text-slate-600 flex items-center gap-2 flex-wrap">
            <RouterLink :to="`/installations/${workOrder.installation.id}`" class="font-medium text-blue-600 hover:underline">
              {{ workOrder.installation.name }}
            </RouterLink>
            <span v-if="workOrder.technician" class="text-slate-400">· Tech: {{ workOrder.technician.name }}</span>
          </div>

          <p v-if="fullAddress" class="text-xs text-slate-500">
            {{ fullAddress }}
          </p>
        </div>

        <div class="flex items-center gap-2 shrink-0 flex-wrap">
          <a
            v-if="workOrder.installation.customer_phone"
            :href="`tel:${workOrder.installation.customer_phone}`"
            class="px-3.5 py-2 rounded-lg border border-slate-200 text-xs font-medium text-slate-700 hover:bg-slate-50 transition tabular-nums"
          >
            Call {{ workOrder.installation.customer_phone }}
          </a>

          <button
            v-if="workOrder.status === 'scheduled'"
            :disabled="starting"
            @click="handleStartVisit"
            class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs font-medium transition disabled:opacity-50 cursor-pointer"
          >
            {{ starting ? 'Starting...' : 'Start Visit' }}
          </button>
        </div>
      </section>

      <div v-if="actionSuccess" class="p-3.5 rounded-lg bg-emerald-50 border border-emerald-200 text-xs font-medium text-emerald-800">
        {{ actionSuccess }}
      </div>
      <div v-if="actionError" class="p-3.5 rounded-lg bg-red-50 border border-red-200 text-xs font-medium text-red-700">
        {{ actionError }}
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- LEFT (2/3): Report Form OR Read-only Completed Report + Hardware -->
        <div class="lg:col-span-8 space-y-6">
          <!-- Office Dispatch Notes -->
          <section v-if="workOrder.notes" class="bg-white rounded-xl border border-slate-200/90 p-5 space-y-1.5">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-400">Dispatch Notes</h2>
            <p class="text-sm text-slate-700 whitespace-pre-line">{{ workOrder.notes }}</p>
          </section>

          <!-- Unscheduled notice -->
          <section
            v-if="!workOrder.is_terminal && !canCompleteVisit"
            class="bg-white rounded-xl border border-slate-200/90 p-6 text-sm text-slate-600"
          >
            This work order is currently <strong class="text-slate-900">{{ workOrder.status_label }}</strong>.
            Schedule a date and technician from Overview or Planning before logging field work.
          </section>

          <!-- Cancelled notice -->
          <section
            v-else-if="workOrder.status === 'cancelled'"
            class="bg-white rounded-xl border border-slate-200/90 p-6 space-y-2 text-sm"
          >
            <h2 class="font-semibold text-slate-900">Work Order Cancelled</h2>
            <p class="text-slate-600">{{ workOrder.cancelled_reason || 'No cancellation reason recorded.' }}</p>
          </section>

          <!-- Read-only Completed Report -->
          <section
            v-else-if="workOrder.report"
            class="bg-white rounded-xl border border-slate-200/90 p-6 space-y-4"
          >
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
              <div>
                <h2 class="text-sm font-semibold text-slate-900">Completed Service Report</h2>
                <p class="text-xs text-slate-500 mt-0.5 tabular-nums">
                  Performed {{ formatDate(workOrder.report.performed_at) }}
                  <span v-if="workOrder.report.submitted_by">by {{ workOrder.report.submitted_by.name }}</span>
                </p>
              </div>
              <span
                v-if="workOrder.report.next_due_at"
                class="px-2.5 py-1 rounded-md bg-slate-100 text-xs font-medium text-slate-700 tabular-nums"
              >
                Next due: {{ formatDate(workOrder.report.next_due_at) }}
              </span>
            </div>

            <div class="space-y-3 text-sm">
              <div>
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1">Work Performed</div>
                <p class="text-slate-800 whitespace-pre-line">{{ workOrder.report.performed_work }}</p>
              </div>

              <div v-if="workOrder.report.findings">
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1">Findings</div>
                <p class="text-slate-700 whitespace-pre-line">{{ workOrder.report.findings }}</p>
              </div>

              <div v-if="workOrder.report.recommendations">
                <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1">Recommendations</div>
                <p class="text-slate-700 whitespace-pre-line">{{ workOrder.report.recommendations }}</p>
              </div>
            </div>

            <div v-if="workOrder.materials?.length" class="pt-3 border-t border-slate-100 space-y-2">
              <div class="text-xs font-semibold text-slate-400 uppercase tracking-wide">Materials Used</div>
              <ul class="divide-y divide-slate-100 text-xs">
                <li v-for="mat in workOrder.materials" :key="mat.id" class="py-2 flex items-center justify-between gap-3">
                  <div>
                    <span class="font-medium text-slate-900">{{ mat.description }}</span>
                    <span v-if="mat.material_code" class="ml-2 font-mono text-[11px] text-slate-400">{{ mat.material_code }}</span>
                  </div>
                  <span class="font-semibold text-slate-700 tabular-nums">{{ Number(mat.quantity) }}&times;</span>
                </li>
              </ul>
            </div>
          </section>

          <!-- Field Service Report Form -->
          <form
            v-else-if="canCompleteVisit"
            @submit.prevent="handleSubmitReport"
            class="bg-white rounded-xl border border-slate-200/90 p-5 sm:p-6 space-y-5"
          >
            <div class="border-b border-slate-100 pb-3">
              <h2 class="text-sm font-semibold text-slate-900">Service Report</h2>
              <p class="text-xs text-slate-500 mt-0.5">
                Submitting closes this visit and rolls the installation's maintenance cycle forward.
              </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-medium text-slate-700 mb-1">Service Date</label>
                <input
                  v-model="form.performed_at"
                  type="date"
                  required
                  class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-slate-900/10"
                />
              </div>

              <div>
                <label class="block text-xs font-medium text-slate-700 mb-1">Next Service Due</label>
                <input
                  v-model="form.next_due_at"
                  type="date"
                  class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm tabular-nums focus:outline-none focus:ring-2 focus:ring-slate-900/10"
                />
                <p class="text-[11px] text-slate-400 mt-1">
                  {{
                    workOrder.maintenance_plan?.interval_months > 0
                      ? `Suggested (+${workOrder.maintenance_plan.interval_months} months) — adjust if needed.`
                      : 'Optional if no fixed annual interval is set.'
                  }}
                </p>
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium text-slate-700 mb-1">Work Performed *</label>
              <textarea
                v-model="form.performed_work"
                rows="4"
                required
                placeholder="Describe inspection checks, detector tests, battery measurements..."
                class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900/10"
              ></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-medium text-slate-700 mb-1">Findings (Optional)</label>
                <textarea
                  v-model="form.findings"
                  rows="2"
                  placeholder="Faults, worn batteries, sensor issues..."
                  class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900/10"
                ></textarea>
              </div>

              <div>
                <label class="block text-xs font-medium text-slate-700 mb-1">Recommendations (Optional)</label>
                <textarea
                  v-model="form.recommendations"
                  rows="2"
                  placeholder="Follow-up quote, parts to replace next visit..."
                  class="w-full px-3 py-2 rounded-lg border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-slate-900/10"
                ></textarea>
              </div>
            </div>

            <!-- Materials Used Repeater -->
            <div class="pt-2 border-t border-slate-100 space-y-3">
              <div class="flex items-center justify-between">
                <label class="text-xs font-medium text-slate-700">Materials Used</label>
                <button
                  type="button"
                  @click="addMaterialRow"
                  class="px-2.5 py-1 rounded-md border border-slate-200 hover:bg-slate-50 text-xs font-medium text-slate-700 cursor-pointer"
                >
                  + Add Material
                </button>
              </div>

              <div v-if="form.materials.length === 0" class="text-xs text-slate-400">
                No materials added. Click "+ Add Material" only if parts were consumed.
              </div>

              <div v-else class="space-y-2">
                <div
                  v-for="(row, idx) in form.materials"
                  :key="idx"
                  class="flex flex-col sm:flex-row gap-2 items-stretch sm:items-center bg-slate-50/70 p-2.5 rounded-lg border border-slate-200/80"
                >
                  <input
                    v-model="row.description"
                    type="text"
                    placeholder="Material description (e.g. 12V 7Ah Battery)"
                    class="flex-1 px-2.5 py-1.5 rounded-md border border-slate-200 bg-white text-xs"
                  />
                  <div class="flex items-center gap-2">
                    <input
                      v-model="row.material_code"
                      type="text"
                      placeholder="Code (opt.)"
                      class="w-28 px-2.5 py-1.5 rounded-md border border-slate-200 bg-white text-xs font-mono"
                    />
                    <input
                      v-model="row.quantity"
                      type="text"
                      inputmode="decimal"
                      placeholder="Qty"
                      class="w-16 px-2.5 py-1.5 rounded-md border border-slate-200 bg-white text-xs text-right tabular-nums"
                    />
                    <button
                      type="button"
                      @click="removeMaterialRow(idx)"
                      title="Remove material"
                      class="px-2 py-1.5 text-slate-400 hover:text-red-600 text-sm leading-none cursor-pointer"
                    >
                      &times;
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div class="pt-2 flex justify-end">
              <button
                type="submit"
                :disabled="submitting"
                class="w-full sm:w-auto px-5 py-2.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs font-medium transition disabled:opacity-50 cursor-pointer"
              >
                {{ submitting ? 'Saving Report...' : 'Complete Visit & Save Report' }}
              </button>
            </div>
          </form>

          <!-- Collapsible Hardware List (Progressive Disclosure) -->
          <section class="bg-white rounded-xl border border-slate-200/90 overflow-hidden">
            <button
              type="button"
              @click="showComponents = !showComponents"
              class="w-full px-5 py-3.5 flex items-center justify-between text-left hover:bg-slate-50/70 transition cursor-pointer"
            >
              <span class="text-sm font-semibold text-slate-900">
                Installed Hardware ({{ workOrder.components?.length || 0 }})
              </span>
              <span class="text-xs font-medium text-blue-600">
                {{ showComponents ? 'Hide' : 'Show' }}
              </span>
            </button>

            <div v-if="showComponents" class="border-t border-slate-100">
              <div v-if="!workOrder.components?.length" class="p-6 text-center text-xs text-slate-500">
                No hardware components recorded for this installation.
              </div>
              <ul v-else class="divide-y divide-slate-100">
                <li
                  v-for="comp in workOrder.components"
                  :key="comp.id"
                  class="px-5 py-3 flex items-center justify-between gap-3 text-xs"
                >
                  <div class="min-w-0">
                    <div class="font-medium text-slate-900">
                      <span class="text-slate-400 tabular-nums mr-1.5">{{ comp.quantity }}&times;</span>
                      {{ comp.description }}
                    </div>
                    <div class="text-[11px] text-slate-400 flex gap-2 mt-0.5">
                      <span v-if="comp.article_code" class="font-mono">{{ comp.article_code }}</span>
                      <span v-if="comp.serial_number" class="tabular-nums">S/N: {{ comp.serial_number }}</span>
                    </div>
                  </div>
                  <span v-if="comp.location" class="px-2 py-0.5 rounded bg-slate-100 text-[11px] text-slate-600 shrink-0">
                    {{ comp.location }}
                  </span>
                </li>
              </ul>
            </div>
          </section>
        </div>

        <!-- RIGHT (1/3): Plan Context -->
        <aside class="lg:col-span-4 space-y-5">
          <section class="bg-white rounded-xl border border-slate-200/90 p-5 space-y-2.5 text-xs">
            <h2 class="text-sm font-semibold text-slate-900 mb-2">Maintenance Plan</h2>
            <div class="flex justify-between">
              <span class="text-slate-500">System Type</span>
              <span class="font-medium text-slate-900">{{ workOrder.installation.installation_type }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-500">Interval</span>
              <span class="font-medium text-slate-900 tabular-nums">
                {{
                  workOrder.maintenance_plan?.interval_months > 0
                    ? `Every ${workOrder.maintenance_plan.interval_months} months`
                    : 'On request / Review'
                }}
              </span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-500">Cycle Due</span>
              <span class="font-medium text-slate-900 tabular-nums">
                {{ formatDate(workOrder.cycle_due_date || workOrder.maintenance_plan?.next_due_at) }}
              </span>
            </div>
            <div class="pt-3 border-t border-slate-100">
              <RouterLink
                :to="`/installations/${workOrder.installation.id}`"
                class="text-xs font-medium text-blue-600 hover:underline"
              >
                Open Full Installation History &rarr;
              </RouterLink>
            </div>
          </section>
        </aside>
      </div>
    </template>
  </div>
</template>