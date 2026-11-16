<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import axios from 'axios';

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'low',
    reference_images: [],
});

const newImageUrl = ref('');
const pricingData = ref(null);
const loadingPricing = ref(true);

const addReferenceImage = () => {
    if (newImageUrl.value.trim() && form.reference_images.length < 4) {
        form.reference_images.push(newImageUrl.value.trim());
        newImageUrl.value = '';
    }
};

const removeReferenceImage = (index) => {
    form.reference_images.splice(index, 1);
};

const addKeyword = (kw) => {
    if (!form.prompt.includes(kw)) {
        form.prompt = form.prompt ? `${form.prompt} ${kw}` : kw;
    }
};

const fetchPricing = async () => {
    try {
        const res = await axios.get('https://pixelforge.promptedit.dev/v1/pricing');
        pricingData.value = res.data;
    } catch (e) {
        console.error('Failed to load pricing:', e);
    } finally {
        loadingPricing.value = false;
    }
};

onMounted(() => {
    fetchPricing();
});

// Calculate live estimate in nanodollars
const estimate = computed(() => {
    if (!pricingData.value) {
        return null;
    }

    const rates = pricingData.value.rates || {
        input_text: '5.00',
        input_image: '10.00',
        output: '40.00',
        reasoning: '12.50',
    };

    const textTokens = Math.ceil((form.prompt || '').length / 4);
    const imageTokens = form.reference_images.length * 765;

    let outputTokens = 280;
    const outputSpecs = pricingData.value.token_specs?.output || [];
    const matchedOutput = outputSpecs.find(
        (s) => s.quality === form.quality && s.size === form.size
    );
    if (matchedOutput) {
        outputTokens = matchedOutput.tokens;
    }

    let reasoningMin = 0;
    let reasoningMax = 500;
    const reasoningSpecs = pricingData.value.token_specs?.reasoning || [];
    const matchedReasoning = reasoningSpecs.find((s) => s.quality === form.quality);
    if (matchedReasoning) {
        reasoningMin = matchedReasoning.min;
        reasoningMax = matchedReasoning.max;
    }

    // Rate calculations (rates are per 1M tokens -> multiply by 1000 for nano per token)
    const textRateNano = parseFloat(rates.input_text) * 1000;
    const imageRateNano = parseFloat(rates.input_image) * 1000;
    const outputRateNano = parseFloat(rates.output) * 1000;
    const reasoningRateNano = parseFloat(rates.reasoning) * 1000;

    const baseMinProviderNano =
        textTokens * textRateNano +
        imageTokens * imageRateNano +
        outputTokens * outputRateNano +
        reasoningMin * reasoningRateNano;

    const baseMaxProviderNano =
        textTokens * textRateNano +
        imageTokens * imageRateNano +
        outputTokens * outputRateNano +
        reasoningMax * reasoningRateNano;

    const minUserNano = Math.round(baseMinProviderNano * 1.25);
    const maxUserNano = Math.round(baseMaxProviderNano * 1.25);
    const headlineUserNano = Math.round((minUserNano + maxUserNano) / 2);

    return {
        textTokens,
        imageTokens,
        outputTokens,
        reasoningMin,
        reasoningMax,
        minUserUSD: (minUserNano / 1_000_000_000).toFixed(6),
        maxUserUSD: (maxUserNano / 1_000_000_000).toFixed(6),
        headlineUserUSD: (headlineUserNano / 1_000_000_000).toFixed(6),
        minProviderUSD: (baseMinProviderNano / 1_000_000_000).toFixed(6),
        maxProviderUSD: (baseMaxProviderNano / 1_000_000_000).toFixed(6),
    };
});

const submit = () => {
    form.post(route('generations.store'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Generate Image" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                Generate Image
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Form Section -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 p-6 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700">
                        <form @submit.prevent="submit" class="space-y-6">
                            <!-- Prompt -->
                            <div>
                                <div class="flex justify-between items-center mb-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Prompt <span class="text-red-500">*</span>
                                    </label>
                                    <span class="text-xs text-gray-400">
                                        {{ form.prompt.length }} / 4000 characters
                                    </span>
                                </div>
                                <textarea
                                    v-model="form.prompt"
                                    rows="4"
                                    maxlength="4000"
                                    required
                                    placeholder="A vivid cinematic photo of a cyberpunk city in rain..."
                                    class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                ></textarea>
                                <InputError :message="form.errors.prompt" class="mt-1" />

                                <!-- Test Keywords Helper -->
                                <div class="mt-2 flex flex-wrap gap-1.5 items-center">
                                    <span class="text-xs text-gray-500">Test keywords:</span>
                                    <button
                                        type="button"
                                        v-for="kw in ['[succeed]', '[fail]', '[blocked]', '[slow]', '[duplicate]']"
                                        :key="kw"
                                        @click="addKeyword(kw)"
                                        class="text-xs px-2 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-indigo-50 hover:text-indigo-600 dark:hover:bg-indigo-900/40 transition"
                                    >
                                        {{ kw }}
                                    </button>
                                </div>
                            </div>

                            <!-- Size & Quality -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Size
                                    </label>
                                    <select
                                        v-model="form.size"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    >
                                        <option value="1024x1024">Square (1024x1024)</option>
                                        <option value="1536x1024">Landscape (1536x1024)</option>
                                        <option value="1024x1536">Portrait (1024x1536)</option>
                                    </select>
                                    <InputError :message="form.errors.size" class="mt-1" />
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Quality
                                    </label>
                                    <select
                                        v-model="form.quality"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm"
                                    >
                                        <option value="low">Low (Fast & Cheap)</option>
                                        <option value="medium">Medium (Standard)</option>
                                        <option value="high">High (Maximum Detail)</option>
                                    </select>
                                    <InputError :message="form.errors.quality" class="mt-1" />
                                </div>
                            </div>

                            <!-- Reference Images (Optional, max 4) -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Reference Image URLs (Optional, max 4)
                                </label>
                                <div class="flex gap-2">
                                    <input
                                        type="url"
                                        v-model="newImageUrl"
                                        placeholder="https://example.com/reference.png"
                                        :disabled="form.reference_images.length >= 4"
                                        class="flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm disabled:opacity-50"
                                    />
                                    <button
                                        type="button"
                                        @click="addReferenceImage"
                                        :disabled="!newImageUrl.trim() || form.reference_images.length >= 4"
                                        class="px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-lg text-sm font-medium transition disabled:opacity-50"
                                    >
                                        Add
                                    </button>
                                </div>

                                <div v-if="form.reference_images.length > 0" class="mt-2 space-y-1">
                                    <div
                                        v-for="(url, idx) in form.reference_images"
                                        :key="idx"
                                        class="flex items-center justify-between p-2 rounded bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-xs text-gray-700 dark:text-gray-300"
                                    >
                                        <span class="truncate mr-2">{{ url }}</span>
                                        <button
                                            type="button"
                                            @click="removeReferenceImage(idx)"
                                            class="text-red-500 hover:text-red-700 font-semibold"
                                        >
                                            Remove
                                        </button>
                                    </div>
                                </div>
                                <InputError :message="form.errors.reference_images" class="mt-1" />
                            </div>

                            <!-- Submit Button -->
                            <div class="flex items-center justify-end pt-4 border-t border-gray-100 dark:border-gray-700">
                                <PrimaryButton
                                    :class="{ 'opacity-25': form.processing }"
                                    :disabled="form.processing"
                                >
                                    Queue Generation
                                </PrimaryButton>
                            </div>
                        </form>
                    </div>

                    <!-- Live Cost Estimate Card -->
                    <div class="bg-white dark:bg-gray-800 p-6 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 h-fit space-y-5">
                        <div class="flex items-center justify-between pb-3 border-b border-gray-100 dark:border-gray-700">
                            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                                Live Cost Estimate
                            </h3>
                            <span class="text-xs bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 px-2 py-0.5 rounded-full font-medium">
                                +25% Markup Included
                            </span>
                        </div>

                        <div v-if="loadingPricing" class="py-8 text-center text-sm text-gray-500">
                            Loading pricing data...
                        </div>

                        <div v-else-if="estimate" class="space-y-4">
                            <!-- Headline Price -->
                            <div class="bg-gradient-to-br from-indigo-50 to-purple-50 dark:from-indigo-950/40 dark:to-purple-950/40 p-4 rounded-xl text-center border border-indigo-100 dark:border-indigo-900/50">
                                <span class="text-xs text-gray-500 dark:text-gray-400 block mb-1">
                                    Expected Charge (Estimate)
                                </span>
                                <div class="text-2xl font-bold text-indigo-600 dark:text-indigo-400 font-mono">
                                    ${{ estimate.headlineUserUSD }}
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400 mt-1 block">
                                    Range: ${{ estimate.minUserUSD }} – ${{ estimate.maxUserUSD }}
                                </span>
                            </div>

                            <!-- Token Breakdown -->
                            <div class="space-y-2 text-xs">
                                <div class="font-medium text-gray-700 dark:text-gray-300 mb-1">Token Breakdown:</div>
                                <div class="flex justify-between py-1 border-b border-gray-100 dark:border-gray-700 text-gray-600 dark:text-gray-400">
                                    <span>Input text (prompt):</span>
                                    <span class="font-mono">{{ estimate.textTokens }} tokens</span>
                                </div>
                                <div class="flex justify-between py-1 border-b border-gray-100 dark:border-gray-700 text-gray-600 dark:text-gray-400">
                                    <span>Input images ({{ form.reference_images.length }} refs):</span>
                                    <span class="font-mono">{{ estimate.imageTokens }} tokens</span>
                                </div>
                                <div class="flex justify-between py-1 border-b border-gray-100 dark:border-gray-700 text-gray-600 dark:text-gray-400">
                                    <span>Output image ({{ form.quality }}/{{ form.size }}):</span>
                                    <span class="font-mono">{{ estimate.outputTokens }} tokens</span>
                                </div>
                                <div class="flex justify-between py-1 border-b border-gray-100 dark:border-gray-700 text-gray-600 dark:text-gray-400">
                                    <span>Reasoning ({{ form.quality }}):</span>
                                    <span class="font-mono">{{ estimate.reasoningMin }}–{{ estimate.reasoningMax }} tokens</span>
                                </div>
                            </div>

                            <!-- Pricing Rules Notice -->
                            <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg text-xs text-gray-500 space-y-1">
                                <div class="font-medium text-gray-700 dark:text-gray-300">Pricing Rules:</div>
                                <p>• Actual charge is recorded on completion based on actual tokens used.</p>
                                <p>• User charge = Pixelforge cost × 1.25 (round half up to nearest nanodollar).</p>
                                <p>• Failed generations cost $0.00.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
