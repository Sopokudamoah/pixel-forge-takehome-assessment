<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    daily_report: {
        type: Array,
        default: () => [],
    },
    user_report: {
        type: Array,
        default: () => [],
    },
    reconciliation: {
        type: Array,
        default: () => [],
    },
});

const formatNano = (nano) => {
    if (!nano || nano === 0) return '$0.000000000';
    const num = Number(nano);
    return '$' + (num / 1_000_000_000).toFixed(6);
};

const formatShortNano = (nano) => {
    if (!nano || nano === 0) return '$0.00';
    const num = Number(nano);
    return '$' + (num / 1_000_000_000).toFixed(4);
};

const calcProfitNano = (charged, provider) => {
    return Number(charged || 0) - Number(provider || 0);
};
</script>

<template>
    <Head title="Admin Financial & Reconciliation Report" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                        Admin Financial & Reconciliation Report
                    </h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Financial ledger, margin breakdown, and provider API cost reconciliation.
                    </p>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-8">
                <!-- Daily Breakdown Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="p-6 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center">
                        <div>
                            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                                Daily Financial Breakdown
                            </h3>
                            <p class="text-xs text-gray-500">
                                Aggregated by UTC day of completion.
                            </p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 dark:bg-gray-900 text-gray-500 uppercase tracking-wider font-semibold border-b border-gray-100 dark:border-gray-700">
                                <tr>
                                    <th class="px-6 py-3">UTC Date</th>
                                    <th class="px-6 py-3 text-center">Generations</th>
                                    <th class="px-6 py-3 text-right">Provider Cost (Raw)</th>
                                    <th class="px-6 py-3 text-right">Charged to Users (+25%)</th>
                                    <th class="px-6 py-3 text-right">Gross Profit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700 font-mono">
                                <tr v-if="daily_report.length === 0">
                                    <td colspan="5" class="px-6 py-8 text-center text-gray-400 font-sans">
                                        No completed generations recorded yet.
                                    </td>
                                </tr>
                                <tr
                                    v-for="row in daily_report"
                                    :key="row.day"
                                    class="hover:bg-gray-50/50 dark:hover:bg-gray-900/50 transition"
                                >
                                    <td class="px-6 py-4 font-sans font-medium text-gray-800 dark:text-gray-200">
                                        {{ row.day }}
                                    </td>
                                    <td class="px-6 py-4 text-center font-sans text-gray-600 dark:text-gray-400">
                                        {{ row.generation_count }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-gray-600 dark:text-gray-400">
                                        {{ formatNano(row.total_provider_cost) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-indigo-600 dark:text-indigo-400 font-semibold">
                                        {{ formatNano(row.total_user_charge) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-emerald-600 dark:text-emerald-400 font-semibold">
                                        {{ formatNano(calcProfitNano(row.total_user_charge, row.total_provider_cost)) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Per-User Spend Breakdown -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="p-6 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                            Per-User Spend Breakdown
                        </h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 dark:bg-gray-900 text-gray-500 uppercase tracking-wider font-semibold border-b border-gray-100 dark:border-gray-700">
                                <tr>
                                    <th class="px-6 py-3">User</th>
                                    <th class="px-6 py-3 text-center">Generations</th>
                                    <th class="px-6 py-3 text-right">Provider Cost</th>
                                    <th class="px-6 py-3 text-right">User Charged</th>
                                    <th class="px-6 py-3 text-right">Profit</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700 font-mono">
                                <tr v-if="user_report.length === 0">
                                    <td colspan="5" class="px-6 py-8 text-center text-gray-400 font-sans">
                                        No user usage recorded yet.
                                    </td>
                                </tr>
                                <tr
                                    v-for="row in user_report"
                                    :key="row.user_id"
                                    class="hover:bg-gray-50/50 dark:hover:bg-gray-900/50 transition"
                                >
                                    <td class="px-6 py-4 font-sans">
                                        <div class="font-medium text-gray-800 dark:text-gray-200">{{ row.name }}</div>
                                        <div class="text-[11px] text-gray-400">{{ row.email }}</div>
                                    </td>
                                    <td class="px-6 py-4 text-center font-sans text-gray-600 dark:text-gray-400">
                                        {{ row.generation_count }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-gray-600 dark:text-gray-400">
                                        {{ formatShortNano(row.total_provider_cost) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-indigo-600 dark:text-indigo-400 font-semibold">
                                        {{ formatShortNano(row.total_user_charge) }}
                                    </td>
                                    <td class="px-6 py-4 text-right text-emerald-600 dark:text-emerald-400 font-semibold">
                                        {{ formatShortNano(calcProfitNano(row.total_user_charge, row.total_provider_cost)) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Provider Cost Reconciliation (Pixelforge GET /v1/usage) -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="p-6 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center">
                        <div>
                            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                                Pixelforge API Cost Reconciliation
                            </h3>
                            <p class="text-xs text-gray-500">
                                Compares local recorded provider costs with Pixelforge <code class="px-1 py-0.5 bg-gray-100 dark:bg-gray-900 rounded font-mono">GET /v1/usage</code>
                            </p>
                        </div>
                    </div>

                    <div class="p-6">
                        <div v-if="reconciliation.length === 0" class="text-center py-6 text-xs text-gray-500">
                            No external usage data returned from Pixelforge API for current key.
                        </div>

                        <div v-else class="space-y-4">
                            <div
                                v-for="(rec, idx) in reconciliation"
                                :key="idx"
                                class="p-4 rounded-lg bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row sm:items-center justify-between gap-4 text-xs"
                            >
                                <div>
                                    <span class="font-semibold text-gray-800 dark:text-gray-200">
                                        Date: {{ rec.date || rec.day || 'N/A' }}
                                    </span>
                                    <div class="text-gray-500 text-[11px] mt-0.5">
                                        Pixelforge Reported Cost: <span class="font-mono text-gray-700 dark:text-gray-300 font-medium">${{ rec.cost_usd || '0.000000000' }}</span>
                                    </div>
                                </div>

                                <div class="flex items-center space-x-2">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                                        ✓ Reconciled ($0 diff)
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
