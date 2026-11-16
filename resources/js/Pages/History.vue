<script setup>
import { onMounted, onUnmounted, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    generations: {
        type: Array,
        default: () => [],
    },
});

let pollTimer = null;

const hasActiveGenerations = computed(() => {
    return props.generations.some(
        (g) => g.status === 'queued' || g.status === 'processing'
    );
});

const startPolling = () => {
    if (pollTimer) return;
    pollTimer = setInterval(() => {
        if (hasActiveGenerations.value) {
            router.reload({ only: ['generations'], preserveScroll: true });
        }
    }, 3000);
};

onMounted(() => {
    startPolling();
});

onUnmounted(() => {
    if (pollTimer) {
        clearInterval(pollTimer);
    }
});

const retryGeneration = (id) => {
    router.post(route('generations.retry', id), {}, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Generation History" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Generation History
                </h2>
                <Link
                    :href="route('generate')"
                    class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition"
                >
                    + New Generation
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- Live status banner if polling -->
                <div
                    v-if="hasActiveGenerations"
                    class="mb-6 p-4 rounded-xl bg-blue-50 dark:bg-blue-950/40 border border-blue-200 dark:border-blue-900 flex items-center justify-between text-blue-700 dark:text-blue-300 text-sm"
                >
                    <div class="flex items-center space-x-3">
                        <span class="relative flex h-3 w-3">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-blue-500"></span>
                        </span>
                        <span>Generations in progress. Live updates every 3 seconds...</span>
                    </div>
                </div>

                <!-- Empty State -->
                <div
                    v-if="generations.length === 0"
                    class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-12 text-center"
                >
                    <div class="w-16 h-16 mx-auto mb-4 bg-indigo-50 dark:bg-indigo-950/50 rounded-full flex items-center justify-center text-indigo-500 text-2xl">
                        🎨
                    </div>
                    <h3 class="text-lg font-medium text-gray-800 dark:text-gray-200 mb-1">
                        No generations yet
                    </h3>
                    <p class="text-sm text-gray-500 mb-6">
                        Start generating stunning images with Pixelforge AI.
                    </p>
                    <Link
                        :href="route('generate')"
                        class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition"
                    >
                        Generate Your First Image
                    </Link>
                </div>

                <!-- Generation Cards -->
                <div v-else class="space-y-6">
                    <div
                        v-for="item in generations"
                        :key="item.id"
                        class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden"
                    >
                        <div class="p-6">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-100 dark:border-gray-700">
                                <div class="flex items-center space-x-3">
                                    <!-- Status Badge -->
                                    <span
                                        v-if="item.status === 'succeeded'"
                                        class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800"
                                    >
                                        ✓ Succeeded
                                    </span>
                                    <span
                                        v-else-if="item.status === 'failed'"
                                        class="px-2.5 py-1 rounded-full text-xs font-semibold bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-400 border border-red-200 dark:border-red-800"
                                    >
                                        ✕ Failed
                                    </span>
                                    <span
                                        v-else-if="item.status === 'processing'"
                                        class="px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-400 border border-blue-200 dark:border-blue-800 flex items-center space-x-1.5"
                                    >
                                        <span class="animate-spin text-xs">⟳</span>
                                        <span>Processing...</span>
                                    </span>
                                    <span
                                        v-else
                                        class="px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400 border border-amber-200 dark:border-amber-800"
                                    >
                                        Queued
                                    </span>

                                    <span class="text-xs text-gray-400">
                                        ID: <span class="font-mono">{{ item.id }}</span>
                                    </span>
                                </div>

                                <!-- Date & Cost Summary -->
                                <div class="flex items-center space-x-4 text-xs">
                                    <span class="text-gray-400">
                                        {{ new Date(item.created_at).toLocaleString() }}
                                    </span>

                                    <div class="flex items-center space-x-2 bg-gray-50 dark:bg-gray-900 px-3 py-1 rounded-lg">
                                        <span class="text-gray-500">Charged:</span>
                                        <span
                                            v-if="item.status === 'succeeded'"
                                            class="font-mono font-semibold text-emerald-600 dark:text-emerald-400"
                                        >
                                            {{ item.user_charge_display }}
                                        </span>
                                        <span
                                            v-else-if="item.status === 'failed'"
                                            class="font-mono font-medium text-gray-500"
                                        >
                                            $0.00 (No charge)
                                        </span>
                                        <span
                                            v-else
                                            class="font-mono text-gray-400"
                                        >
                                            Pending...
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 pt-4">
                                <!-- Prompt & Settings -->
                                <div class="md:col-span-2 space-y-3">
                                    <div>
                                        <label class="text-xs font-medium text-gray-400 block mb-1">Prompt</label>
                                        <p class="text-sm text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-900/50 p-3 rounded-lg border border-gray-100 dark:border-gray-700/50">
                                            {{ item.prompt }}
                                        </p>
                                    </div>

                                    <div class="flex flex-wrap gap-4 text-xs text-gray-500">
                                        <div><span class="font-medium text-gray-700 dark:text-gray-300">Size:</span> {{ item.size }}</div>
                                        <div><span class="font-medium text-gray-700 dark:text-gray-300">Quality:</span> {{ item.quality }}</div>
                                        <div v-if="item.provider_cost_nano > 0">
                                            <span class="font-medium text-gray-700 dark:text-gray-300">Provider Cost:</span> {{ item.provider_cost_display }}
                                        </div>
                                    </div>

                                    <!-- Error Box if Failed -->
                                    <div
                                        v-if="item.status === 'failed'"
                                        class="p-4 rounded-lg bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900/60 text-xs text-red-700 dark:text-red-300 space-y-2"
                                    >
                                        <div class="flex items-center justify-between">
                                            <span class="font-semibold">Error: {{ item.error_type || 'Generation Failed' }}</span>
                                            <button
                                                type="button"
                                                @click="retryGeneration(item.id)"
                                                class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white font-medium rounded transition"
                                            >
                                                Retry Generation
                                            </button>
                                        </div>
                                        <p class="text-red-600 dark:text-red-400">
                                            {{ item.error_message || 'An error occurred during image generation.' }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Image / Thumbnail -->
                                <div class="flex flex-col items-center justify-center bg-gray-50 dark:bg-gray-900 rounded-lg p-2 min-h-[160px] border border-gray-100 dark:border-gray-800">
                                    <div v-if="item.image_url" class="space-y-2 text-center w-full">
                                        <img
                                            :src="item.image_url"
                                            :alt="item.prompt"
                                            class="w-full h-48 object-cover rounded shadow-sm"
                                        />
                                        <span class="text-[10px] text-gray-400 block">
                                            Provider URL expires in 24 hours
                                        </span>
                                    </div>
                                    <div
                                        v-else-if="item.status === 'queued' || item.status === 'processing'"
                                        class="text-center py-8 space-y-2"
                                    >
                                        <div class="animate-spin text-2xl text-indigo-500">⏳</div>
                                        <p class="text-xs text-gray-500">Generating image...</p>
                                    </div>
                                    <div
                                        v-else
                                        class="text-center py-8 text-xs text-gray-400"
                                    >
                                        No image available
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
