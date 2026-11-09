<script setup>
import { ref } from 'vue';

const appName = ref('MaintenanceFlow');
const clientCount = ref(193);
const fireCount = ref(56);
const counter = ref(0);
</script>

<template>
  <div class="min-h-screen bg-slate-100 flex flex-col justify-center items-center p-6">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-xl p-8 border border-slate-200">
      <div class="flex items-center space-x-3 mb-6">
        <div class="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center text-white font-bold text-xl">
          MF
        </div>
        <div>
          <h1 class="text-2xl font-bold text-slate-800">{{ appName }}</h1>
          <p class="text-xs text-slate-500 font-medium tracking-wide uppercase">Verlinde Beveiliging BV</p>
        </div>
      </div>

      <div class="space-y-3 mb-6">
        <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg flex justify-between items-center">
          <span class="text-sm font-medium text-blue-900">Total Installations</span>
          <span class="font-bold text-blue-700">{{ clientCount }}</span>
        </div>
        <div class="p-3 bg-red-50 border border-red-200 rounded-lg flex justify-between items-center">
          <span class="text-sm font-medium text-red-900">Fire Safety Systems (BRAND)</span>
          <span class="font-bold text-red-700">{{ fireCount }}</span>
        </div>
      </div>

      <div class="pt-4 border-t border-slate-200 flex items-center justify-between">
        <span class="text-sm text-slate-600">Reactivity Check:</span>
        <button 
          @click="counter++"
          class="px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white rounded-lg text-sm font-semibold transition"
        >
          Clicks: {{ counter }}
        </button>
      </div>
    </div>
  </div>
</template>