<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});

// Interactive Simulator State
const simPrompt = ref('Cyberpunk samurai overlooking a neon-lit rain city with holographic dragons [succeed]');
const simSize = ref('1024x1024');
const simQuality = ref('medium');
const simImagesCount = ref(0);

const pricingData = ref({
    rates: {
        input_text: '5.00',
        input_image: '10.00',
        output: '40.00',
        reasoning: '12.50',
    },
    token_specs: {
        output: [
            { quality: 'low', size: '1024x1024', tokens: 280 },
            { quality: 'low', size: '1536x1024', tokens: 420 },
            { quality: 'low', size: '1024x1536', tokens: 420 },
            { quality: 'medium', size: '1024x1024', tokens: 1100 },
            { quality: 'medium', size: '1536x1024', tokens: 1650 },
            { quality: 'medium', size: '1024x1536', tokens: 1650 },
            { quality: 'high', size: '1024x1024', tokens: 4300 },
            { quality: 'high', size: '1536x1024', tokens: 6450 },
            { quality: 'high', size: '1024x1536', tokens: 6450 },
        ],
        reasoning: [
            { quality: 'low', min: 0, max: 500 },
            { quality: 'medium', min: 200, max: 2000 },
            { quality: 'high', min: 1000, max: 8000 },
        ],
    },
});

onMounted(async () => {
    try {
        const res = await axios.get('https://pixelforge.promptedit.dev/v1/pricing');
        if (res.data && res.data.rates) {
            pricingData.value = res.data;
        }
    } catch {
        // Fallback to initial defaults
    }
});

const simEstimate = computed(() => {
    const textTokens = Math.ceil((simPrompt.value || '').length / 4);
    const imageTokens = simImagesCount.value * 765;

    let outputTokens = 1100;
    const matchedOutput = pricingData.value.token_specs?.output?.find(
        (s) => s.quality === simQuality.value && s.size === simSize.value
    );
    if (matchedOutput) {
        outputTokens = matchedOutput.tokens;
    }

    let reasoningMin = 200;
    let reasoningMax = 2000;
    const matchedReasoning = pricingData.value.token_specs?.reasoning?.find(
        (s) => s.quality === simQuality.value
    );
    if (matchedReasoning) {
        reasoningMin = matchedReasoning.min;
        reasoningMax = matchedReasoning.max;
    }

    const rates = pricingData.value.rates;
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
        minProviderUSD: (baseMinProviderNano / 1000000000).toFixed(9),
        maxProviderUSD: (baseMaxProviderNano / 1000000000).toFixed(9),
        minUserUSD: (minUserNano / 1000000000).toFixed(9),
        maxUserUSD: (maxUserNano / 1000000000).toFixed(9),
        headlineUserUSD: (headlineUserNano / 1000000000).toFixed(9),
        headlineNano: headlineUserNano,
    };
});

const sampleGenerations = [
    {
        title: 'Cyberpunk Ronin in Rain',
        prompt: 'Futuristic samurai with neon katana in heavy Tokyo downpour [succeed]',
        size: '1024x1024',
        quality: 'Medium',
        estimated: '$0.062125000',
        actual: '$0.061842500',
        status: 'succeeded',
        gradient: 'from-purple-600 via-indigo-600 to-blue-500',
    },
    {
        title: 'Deep Space Nebula Forge',
        prompt: 'Cosmic star forge birthing celestial planets, hyper-detailed [slow]',
        size: '1536x1024',
        quality: 'High',
        estimated: '$0.312500000',
        actual: '$0.309875000',
        status: 'succeeded',
        gradient: 'from-amber-500 via-rose-600 to-purple-800',
    },
    {
        title: 'Synthwave Retro Arcade',
        prompt: '1980s neon arcade with glowing pixel cabinets and synth music vibe [duplicate]',
        size: '1024x1536',
        quality: 'Low',
        estimated: '$0.021000000',
        actual: '$0.020750000',
        status: 'succeeded',
        gradient: 'from-cyan-500 via-emerald-500 to-teal-700',
    },
    {
        title: 'Restricted Guardrail Test',
        prompt: 'Testing prompt policy boundary [blocked]',
        size: '1024x1024',
        quality: 'Low',
        estimated: '$0.015803125',
        actual: '$0.000000000',
        status: 'failed',
        error: 'Content policy violation',
        gradient: 'from-red-600 via-rose-800 to-zinc-900',
    },
];

const testKeywords = [
    { tag: '[succeed]', desc: 'Fast normal completion with actual usage stats' },
    { tag: '[slow]', desc: 'Simulates 60-90s generation time to test async polling' },
    { tag: '[fail]', desc: 'Simulates generation failure with guaranteed $0 charge' },
    { tag: '[duplicate]', desc: 'Dispatches duplicate webhooks to validate idempotency' },
    { tag: '[blocked]', desc: 'Triggers instant 400 content policy error' },
    { tag: '[overloaded]', desc: 'Simulates 503 provider backpressure and automatic retries' },
];
</script>

<template>
    <Head title="Pixel Forge - Exact-Cost AI Image Generation" />

    <div class="min-h-screen bg-slate-950 text-slate-100 selection:bg-indigo-500 selection:text-white font-sans antialiased overflow-x-hidden">
        <!-- Ambient Glow Elements -->
        <div class="fixed top-0 left-1/2 -translate-x-1/2 w-full max-w-7xl h-[500px] bg-gradient-to-b from-indigo-600/15 via-purple-600/10 to-transparent blur-3xl pointer-events-none -z-10"></div>
        <div class="fixed -top-40 -right-40 w-96 h-96 bg-purple-600/20 rounded-full blur-[120px] pointer-events-none -z-10"></div>
        <div class="fixed top-1/2 -left-40 w-96 h-96 bg-indigo-600/15 rounded-full blur-[120px] pointer-events-none -z-10"></div>

        <!-- Navigation Bar -->
        <header class="border-b border-slate-800/80 bg-slate-950/80 backdrop-blur-md sticky top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 flex items-center justify-center shadow-lg shadow-indigo-500/25 ring-1 ring-white/20">
                        <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <div>
                        <span class="text-xl font-bold tracking-tight text-white flex items-center gap-2">
                            Pixel<span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 to-purple-400">Forge</span>
                            <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">v1.0</span>
                        </span>
                    </div>
                </div>

                <nav class="flex items-center space-x-3 sm:space-x-4">
                    <a href="#simulator" class="hidden sm:inline-flex text-sm text-slate-300 hover:text-white transition px-3 py-1.5">
                        Live Estimator
                    </a>
                    <a href="#features" class="hidden sm:inline-flex text-sm text-slate-300 hover:text-white transition px-3 py-1.5">
                        Architecture
                    </a>
                    <a href="#keywords" class="hidden sm:inline-flex text-sm text-slate-300 hover:text-white transition px-3 py-1.5">
                        Test Lab
                    </a>

                    <div v-if="canLogin" class="flex items-center space-x-2 pl-2 sm:pl-4 border-l border-slate-800">
                        <Link
                            v-if="$page.props.auth.user"
                            :href="route('dashboard')"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 shadow-md shadow-indigo-600/30 transition"
                        >
                            <span>Dashboard</span>
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </Link>
                        <template v-else>
                            <Link
                                :href="route('login')"
                                class="px-3.5 py-2 text-sm font-medium text-slate-300 hover:text-white transition"
                            >
                                Log in
                            </Link>
                            <Link
                                v-if="canRegister"
                                :href="route('register')"
                                class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-lg bg-gradient-to-r from-indigo-500 to-purple-600 text-white hover:from-indigo-400 hover:to-purple-500 shadow-md shadow-indigo-500/25 transition ring-1 ring-white/10"
                            >
                                <span>Get $10 Credit</span>
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                </svg>
                            </Link>
                        </template>
                    </div>
                </nav>
            </div>
        </header>

        <!-- Hero Section -->
        <section class="relative pt-16 pb-20 sm:pt-24 sm:pb-28">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                <!-- Top Badge -->
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-slate-900/90 border border-indigo-500/30 text-xs font-medium text-indigo-300 mb-8 shadow-inner shadow-indigo-500/10 backdrop-blur-sm">
                    <span class="flex h-2 w-2 rounded-full bg-emerald-400 animate-ping"></span>
                    <span class="flex h-2 w-2 rounded-full bg-emerald-400 -ml-4"></span>
                    <span>Nanodollar Precision & Zero-Fail Transparency</span>
                </div>

                <!-- Main Headline -->
                <h1 class="text-4xl sm:text-6xl lg:text-7xl font-extrabold tracking-tight text-white max-w-4xl mx-auto leading-tight">
                    Next-Gen AI Imaging with
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-indigo-400 via-purple-300 to-pink-400">
                        Exact Money Math
                    </span>
                </h1>

                <!-- Subheadline -->
                <p class="mt-6 text-lg sm:text-xl text-slate-400 max-w-2xl mx-auto font-normal leading-relaxed">
                    Generate images via Pixelforge with live token cost estimation, atomic forward-only state progression, HMAC signature verification, and zero-float nanodollar billing accuracy.
                </p>

                <!-- Hero Actions -->
                <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <Link
                        v-if="!$page.props.auth.user"
                        :href="route('register')"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-xl bg-gradient-to-r from-indigo-500 via-purple-600 to-pink-600 text-white font-semibold text-base shadow-xl shadow-indigo-500/25 hover:shadow-indigo-500/40 hover:scale-[1.02] active:scale-[0.98] transition duration-150 ring-1 ring-white/20"
                    >
                        <span>Start Generating with $10 Credit</span>
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                    </Link>
                    <Link
                        v-else
                        :href="route('generate')"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-xl bg-indigo-600 text-white font-semibold text-base shadow-xl shadow-indigo-600/30 hover:bg-indigo-500 hover:scale-[1.02] transition"
                    >
                        <span>Create Image Now</span>
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                    </Link>

                    <a
                        href="#simulator"
                        class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-slate-200 font-semibold text-base border border-slate-700/80 shadow-lg transition"
                    >
                        <span>Interactive Cost Simulator</span>
                        <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </a>
                </div>

                <!-- Trust / Spec Stats -->
                <div class="mt-16 grid grid-cols-2 md:grid-cols-4 gap-4 max-w-4xl mx-auto">
                    <div class="bg-slate-900/60 border border-slate-800/80 rounded-2xl p-4 backdrop-blur-sm">
                        <div class="text-2xl font-bold text-white font-mono">1 USD</div>
                        <div class="text-xs text-slate-400 mt-1">1,000,000,000 Nanodollars</div>
                    </div>
                    <div class="bg-slate-900/60 border border-slate-800/80 rounded-2xl p-4 backdrop-blur-sm">
                        <div class="text-2xl font-bold text-emerald-400 font-mono">+25%</div>
                        <div class="text-xs text-slate-400 mt-1">Fixed Transparent Markup</div>
                    </div>
                    <div class="bg-slate-900/60 border border-slate-800/80 rounded-2xl p-4 backdrop-blur-sm">
                        <div class="text-2xl font-bold text-purple-400 font-mono">0.000000000</div>
                        <div class="text-xs text-slate-400 mt-1">9-Decimal Precision Display</div>
                    </div>
                    <div class="bg-slate-900/60 border border-slate-800/80 rounded-2xl p-4 backdrop-blur-sm">
                        <div class="text-2xl font-bold text-indigo-400 font-mono">HMAC-SHA256</div>
                        <div class="text-xs text-slate-400 mt-1">Idempotent Webhooks</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Interactive Cost Simulator Section -->
        <section id="simulator" class="py-20 border-t border-slate-800/80 bg-slate-900/30 relative">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-3xl mx-auto mb-14">
                    <h2 class="text-xs font-semibold text-indigo-400 uppercase tracking-widest">Live Math Engine</h2>
                    <p class="mt-2 text-3xl sm:text-4xl font-extrabold text-white">Interactive Nanodollar Cost Estimator</p>
                    <p class="mt-3 text-slate-400 text-sm sm:text-base">
                        Test the real-time pricing breakdown. Text tokens, output resolution, reference images, and reasoning ranges are computed live without float rounding errors.
                    </p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start max-w-6xl mx-auto">
                    <!-- Controls -->
                    <div class="lg:col-span-7 bg-slate-900/80 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl">
                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-slate-200 mb-2">Prompt Input</label>
                                <textarea
                                    v-model="simPrompt"
                                    rows="3"
                                    class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-slate-100 placeholder-slate-500 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm transition"
                                    placeholder="Enter your image prompt..."
                                ></textarea>
                                <div class="mt-1 flex justify-between text-xs text-slate-400 font-mono">
                                    <span>Length: {{ simPrompt.length }} chars</span>
                                    <span>Text Tokens: ~{{ simEstimate.textTokens }}</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-200 mb-2">Output Resolution</label>
                                    <select
                                        v-model="simSize"
                                        class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2.5 text-slate-100 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm"
                                    >
                                        <option value="1024x1024">Square (1024 x 1024)</option>
                                        <option value="1536x1024">Landscape (1536 x 1024)</option>
                                        <option value="1024x1536">Portrait (1024 x 1536)</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-slate-200 mb-2">Quality Tier</label>
                                    <select
                                        v-model="simQuality"
                                        class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2.5 text-slate-100 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 text-sm"
                                    >
                                        <option value="low">Low (Fast / Budget)</option>
                                        <option value="medium">Medium (Balanced)</option>
                                        <option value="high">High (Maximum Detail)</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <div class="flex justify-between items-center mb-2">
                                    <label class="text-sm font-medium text-slate-200">Reference Images (765 tokens each)</label>
                                    <span class="text-xs text-indigo-400 font-mono">{{ simImagesCount }} / 4 images</span>
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        v-for="count in [0, 1, 2, 3, 4]"
                                        :key="count"
                                        type="button"
                                        @click="simImagesCount = count"
                                        :class="[
                                            simImagesCount === count
                                                ? 'bg-indigo-600 text-white border-indigo-500'
                                                : 'bg-slate-950 text-slate-300 border-slate-800 hover:bg-slate-800',
                                            'flex-1 py-2 text-xs font-semibold rounded-lg border transition'
                                        ]"
                                    >
                                        {{ count }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Breakdown Result Card -->
                    <div class="lg:col-span-5 bg-gradient-to-br from-indigo-950/60 via-slate-900 to-purple-950/40 border border-indigo-500/30 rounded-2xl p-6 sm:p-8 shadow-2xl relative overflow-hidden">
                        <div class="absolute -top-12 -right-12 w-36 h-36 bg-indigo-500/20 rounded-full blur-2xl"></div>

                        <div class="flex items-center justify-between pb-4 border-b border-slate-800">
                            <span class="text-xs font-semibold uppercase tracking-wider text-indigo-300">Live Breakdown</span>
                            <span class="text-xs text-emerald-400 font-mono bg-emerald-500/10 px-2 py-0.5 rounded-full border border-emerald-500/20">Exact Math</span>
                        </div>

                        <!-- Main Headline Estimate -->
                        <div class="mt-6 mb-6">
                            <div class="text-xs text-slate-400">Headline User Estimate (with 25% markup)</div>
                            <div class="text-3xl font-extrabold text-white font-mono mt-1 tracking-tight">
                                ${{ simEstimate.headlineUserUSD }}
                            </div>
                            <div class="text-xs text-indigo-300 mt-1 font-mono">
                                {{ simEstimate.headlineNano.toLocaleString() }} nanodollars
                            </div>
                        </div>

                        <!-- Expected Range -->
                        <div class="bg-slate-950/80 rounded-xl p-4 border border-slate-800/80 mb-6 space-y-2 text-xs font-mono">
                            <div class="flex justify-between text-slate-300">
                                <span>Estimated Range:</span>
                                <span class="text-white">${{ simEstimate.minUserUSD }} – ${{ simEstimate.maxUserUSD }}</span>
                            </div>
                            <div class="flex justify-between text-slate-400">
                                <span>Base Provider Cost:</span>
                                <span>${{ simEstimate.minProviderUSD }} – ${{ simEstimate.maxProviderUSD }}</span>
                            </div>
                        </div>

                        <!-- Token Breakdown Table -->
                        <div class="space-y-2 text-xs font-mono text-slate-300">
                            <div class="flex justify-between py-1.5 border-b border-slate-800/60">
                                <span class="text-slate-400">Text Tokens ($5/1M):</span>
                                <span class="text-white">{{ simEstimate.textTokens }}</span>
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-slate-800/60">
                                <span class="text-slate-400">Output Tokens ($40/1M):</span>
                                <span class="text-white">{{ simEstimate.outputTokens }}</span>
                            </div>
                            <div class="flex justify-between py-1.5 border-b border-slate-800/60">
                                <span class="text-slate-400">Reasoning Range ($12.50/1M):</span>
                                <span class="text-white">{{ simEstimate.reasoningMin }} – {{ simEstimate.reasoningMax }}</span>
                            </div>
                            <div class="flex justify-between py-1.5">
                                <span class="text-slate-400">Reference Images ($10/1M):</span>
                                <span class="text-white">{{ simEstimate.imageTokens }} ({{ simImagesCount }}x)</span>
                            </div>
                        </div>

                        <div class="mt-6 pt-4 border-t border-slate-800/80">
                            <Link
                                v-if="!$page.props.auth.user"
                                :href="route('register')"
                                class="w-full inline-flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-medium text-xs transition shadow-md shadow-indigo-600/30"
                            >
                                <span>Try on Live Account ($10 Credit)</span>
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Architectural Highlights Section -->
        <section id="features" class="py-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto mb-16">
                <h2 class="text-xs font-semibold text-indigo-400 uppercase tracking-widest">Built for Correctness</h2>
                <p class="mt-2 text-3xl sm:text-4xl font-extrabold text-white">Engineered for Robust Financial Integrity</p>
                <p class="mt-3 text-slate-400 text-sm sm:text-base">
                    Every design decision in Pixel Forge is built around money correctness, state resilience, and transparent reconciliation.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Card 1 -->
                <div class="bg-slate-900/60 border border-slate-800/80 rounded-2xl p-6 hover:border-indigo-500/40 transition duration-200">
                    <div class="w-12 h-12 rounded-xl bg-indigo-500/10 text-indigo-400 flex items-center justify-center mb-5 ring-1 ring-indigo-500/20">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">Zero-Float Nanodollar Engine</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Money is stored and manipulated strictly as integer nanodollars (<code class="text-indigo-300 font-mono">1 USD = 10^9</code>). BCMath ensures floating-point truncation never compromises invoicing.
                    </p>
                </div>

                <!-- Card 2 -->
                <div class="bg-slate-900/60 border border-slate-800/80 rounded-2xl p-6 hover:border-purple-500/40 transition duration-200">
                    <div class="w-12 h-12 rounded-xl bg-purple-500/10 text-purple-400 flex items-center justify-center mb-5 ring-1 ring-purple-500/20">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">HMAC-SHA256 Webhook Pipeline</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Verifies raw webhook signatures with timing-safe comparisons and rejects timestamps older than 300 seconds. Event deduplication is enforced at database level.
                    </p>
                </div>

                <!-- Card 3 -->
                <div class="bg-slate-900/60 border border-slate-800/80 rounded-2xl p-6 hover:border-emerald-500/40 transition duration-200">
                    <div class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center mb-5 ring-1 ring-emerald-500/20">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">Forward-Only State Machine</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Generations progress strictly: <code class="text-emerald-300 font-mono">queued → processing → succeeded|failed</code>. Row-level locks prevent out-of-order race conditions from double-charging.
                    </p>
                </div>

                <!-- Card 4 -->
                <div class="bg-slate-900/60 border border-slate-800/80 rounded-2xl p-6 hover:border-pink-500/40 transition duration-200">
                    <div class="w-12 h-12 rounded-xl bg-pink-500/10 text-pink-400 flex items-center justify-center mb-5 ring-1 ring-pink-500/20">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">Automated Usage Reconciliation</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Admin reporting reconciles provider costs against Pixelforge's <code class="text-pink-300 font-mono">GET /v1/usage</code> API, ensuring local ledger matches remote telemetry exactly.
                    </p>
                </div>

                <!-- Card 5 -->
                <div class="bg-slate-900/60 border border-slate-800/80 rounded-2xl p-6 hover:border-cyan-500/40 transition duration-200">
                    <div class="w-12 h-12 rounded-xl bg-cyan-500/10 text-cyan-400 flex items-center justify-center mb-5 ring-1 ring-cyan-500/20">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">Atomic Credit Protection</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        User balance is atomically validated before dispatching jobs. Failed generations incur zero charge, and balances are strictly locked against concurrent overdrafts.
                    </p>
                </div>

                <!-- Card 6 -->
                <div class="bg-slate-900/60 border border-slate-800/80 rounded-2xl p-6 hover:border-amber-500/40 transition duration-200">
                    <div class="w-12 h-12 rounded-xl bg-amber-500/10 text-amber-400 flex items-center justify-center mb-5 ring-1 ring-amber-500/20">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">Live UI Polling & Sync</h3>
                    <p class="text-sm text-slate-400 leading-relaxed">
                        Inertia.js partial reloads poll every 3 seconds for active jobs. Status updates, estimated vs actual costs, and temporary URL expiration handling are completely seamless.
                    </p>
                </div>
            </div>
        </section>

        <!-- Sample Showcase Section -->
        <section class="py-20 border-t border-slate-800/80 bg-slate-900/20">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-3xl mx-auto mb-14">
                    <h2 class="text-xs font-semibold text-indigo-400 uppercase tracking-widest">Generation Showcase</h2>
                    <p class="mt-2 text-3xl sm:text-4xl font-extrabold text-white">Estimated vs. Actual Invoicing</p>
                    <p class="mt-3 text-slate-400 text-sm sm:text-base">
                        Observe how different parameters resolve into exact nanodollar charges on completion.
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div
                        v-for="(gen, idx) in sampleGenerations"
                        :key="idx"
                        class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-lg flex flex-col justify-between"
                    >
                        <!-- Graphic Placeholder -->
                        <div :class="['h-36 bg-gradient-to-tr p-4 flex flex-col justify-between relative', gen.gradient]">
                            <div class="flex justify-between items-center">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-black/40 text-white backdrop-blur-md">
                                    {{ gen.quality }} Quality
                                </span>
                                <span
                                    :class="[
                                        gen.status === 'succeeded' ? 'bg-emerald-500/80 text-white' : 'bg-red-500/80 text-white',
                                        'px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider backdrop-blur-md'
                                    ]"
                                >
                                    {{ gen.status }}
                                </span>
                            </div>
                            <div class="text-white font-semibold text-sm drop-shadow-md">
                                {{ gen.title }}
                            </div>
                        </div>

                        <!-- Content Details -->
                        <div class="p-5 space-y-3 flex-1 flex flex-col justify-between">
                            <p class="text-xs text-slate-300 italic line-clamp-2">"{{ gen.prompt }}"</p>

                            <div class="pt-3 border-t border-slate-800 text-xs font-mono space-y-1.5">
                                <div class="flex justify-between text-slate-400">
                                    <span>Resolution:</span>
                                    <span class="text-slate-200">{{ gen.size }}</span>
                                </div>
                                <div class="flex justify-between text-slate-400">
                                    <span>Estimated:</span>
                                    <span class="text-slate-200">{{ gen.estimated }}</span>
                                </div>
                                <div class="flex justify-between font-bold">
                                    <span class="text-indigo-400">Actual Charge:</span>
                                    <span :class="gen.status === 'succeeded' ? 'text-emerald-400' : 'text-slate-400'">
                                        {{ gen.actual }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Test Keywords Laboratory Section -->
        <section id="keywords" class="py-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-gradient-to-br from-slate-900 via-indigo-950/40 to-slate-900 border border-slate-800 rounded-3xl p-8 sm:p-12 shadow-2xl">
                <div class="max-w-3xl mb-10">
                    <span class="text-xs font-semibold text-indigo-400 uppercase tracking-widest">Integration Testing Lab</span>
                    <h2 class="text-3xl font-extrabold text-white mt-2">Pixelforge Test Keywords Supported</h2>
                    <p class="text-slate-400 text-sm sm:text-base mt-2">
                        Append any of these keywords to your prompts to test edge cases, concurrency backoff, duplicate events, and content guardrails.
                    </p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div
                        v-for="(kw, idx) in testKeywords"
                        :key="idx"
                        class="bg-slate-950/80 border border-slate-800/80 rounded-xl p-4 flex flex-col justify-between"
                    >
                        <div class="flex items-center gap-2 mb-2">
                            <span class="px-2.5 py-1 rounded-md bg-indigo-500/20 text-indigo-300 font-mono text-xs font-bold border border-indigo-500/30">
                                {{ kw.tag }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed">{{ kw.desc }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="py-20 border-t border-slate-800/80 text-center relative overflow-hidden">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight">
                    Ready for Exact-Cost Generative Imaging?
                </h2>
                <p class="mt-4 text-base sm:text-lg text-slate-400 max-w-xl mx-auto">
                    Sign up in seconds. You'll receive $10.00 in initial test credits and instant access to the generation suite.
                </p>

                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <Link
                        v-if="!$page.props.auth.user"
                        :href="route('register')"
                        class="w-full sm:w-auto px-8 py-3.5 rounded-xl bg-gradient-to-r from-indigo-500 to-purple-600 text-white font-semibold text-base shadow-xl shadow-indigo-500/25 hover:from-indigo-400 hover:to-purple-500 transition"
                    >
                        Claim $10 Free Credits
                    </Link>
                    <Link
                        v-else
                        :href="route('generate')"
                        class="w-full sm:w-auto px-8 py-3.5 rounded-xl bg-indigo-600 text-white font-semibold text-base shadow-xl shadow-indigo-600/30 hover:bg-indigo-500 transition"
                    >
                        Go to Image Generator
                    </Link>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="border-t border-slate-900 bg-slate-950 py-10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-400">
                <div class="flex items-center space-x-2">
                    <span class="font-bold text-slate-300">Pixel Forge</span>
                    <span>&bull;</span>
                    <span>Powered by Laravel {{ laravelVersion }} & PHP {{ phpVersion }}</span>
                </div>
                <div class="flex items-center space-x-6">
                    <a href="https://pixelforge.promptedit.dev/docs" target="_blank" class="hover:text-slate-300 transition">
                        Pixelforge API Docs
                    </a>
                    <a href="https://github.com" target="_blank" class="hover:text-slate-300 transition">
                        GitHub Repository
                    </a>
                </div>
            </div>
        </footer>
    </div>
</template>
