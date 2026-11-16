<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    balance_nano: {
        type: Number,
        default: 10000000000,
    },
    balance_display: {
        type: String,
        default: '$10.00',
    },
    recent_generations: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Dashboard
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-8">
                <!-- Welcome & Balance Banner -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Balance Card -->
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 flex flex-col justify-between">
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">
                                Available Balance
                            </span>
                            <div class="text-3xl font-extrabold text-emerald-600 dark:text-emerald-400 font-mono">
                                {{ balance_display }}
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                Used for paying generation charges (+25% markup).
                            </p>
                        </div>
                        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <Link
                                :href="route('balance.buy')"
                                method="post"
                                as="button"
                                class="w-full text-center px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm font-medium transition"
                            >
                                + Buy $5.00 Credits (Instant)
                            </Link>
                        </div>
                    </div>

                    <!-- Quick Action: Generate -->
                    <div class="bg-gradient-to-br from-indigo-500 to-purple-600 p-6 rounded-xl shadow-sm text-white flex flex-col justify-between">
                        <div>
                            <span class="text-xs font-semibold text-indigo-200 uppercase tracking-wider block mb-1">
                                AI Image Generation
                            </span>
                            <h3 class="text-xl font-bold mb-2">Create New Images</h3>
                            <p class="text-xs text-indigo-100">
                                Full control over prompt, resolution, quality, reference images, and live cost calculation.
                            </p>
                        </div>
                        <div class="mt-4">
                            <Link
                                :href="route('generate')"
                                class="inline-block w-full text-center px-4 py-2 bg-white text-indigo-600 hover:bg-indigo-50 rounded-lg text-sm font-semibold transition"
                            >
                                Start Generating →
                            </Link>
                        </div>
                    </div>

                    <!-- Admin & Reconciliation -->
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 flex flex-col justify-between">
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">
                                Financial Reporting
                            </span>
                            <h3 class="text-xl font-bold text-gray-800 dark:text-gray-200 mb-2">
                                Admin & Reconciliation
                            </h3>
                            <p class="text-xs text-gray-500">
                                Per-day spend, profit margins, and zero-diff reconciliation against Pixelforge usage API.
                            </p>
                        </div>
                        <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                            <Link
                                :href="route('admin.report')"
                                class="inline-block w-full text-center px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-200 rounded-lg text-sm font-medium transition"
                            >
                                View Admin Report
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Recent Generations -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">
                            Recent Generations
                        </h3>
                        <Link
                            :href="route('history')"
                            class="text-xs font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400"
                        >
                            View All History →
                        </Link>
                    </div>

                    <div v-if="recent_generations.length === 0" class="text-center py-8 text-sm text-gray-500">
                        No generations yet. Click "Start Generating" to try it out!
                    </div>

                    <div v-else class="divide-y divide-gray-100 dark:divide-gray-700">
                        <div
                            v-for="item in recent_generations"
                            :key="item.id"
                            class="py-3 flex items-center justify-between gap-4 text-sm"
                        >
                            <div class="flex-1 truncate">
                                <span class="font-medium text-gray-800 dark:text-gray-200 block truncate">
                                    {{ item.prompt }}
                                </span>
                                <span class="text-xs text-gray-400 font-mono">
                                    {{ new Date(item.created_at).toLocaleString() }}
                                </span>
                            </div>

                            <div class="flex items-center space-x-3">
                                <span
                                    v-if="item.status === 'succeeded'"
                                    class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400"
                                >
                                    Succeeded
                                </span>
                                <span
                                    v-else-if="item.status === 'failed'"
                                    class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-400"
                                >
                                    Failed
                                </span>
                                <span
                                    v-else
                                    class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-400"
                                >
                                    {{ item.status }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
