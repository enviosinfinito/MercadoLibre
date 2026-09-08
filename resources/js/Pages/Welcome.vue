<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    canLogin?: boolean;
    canRegister?: boolean;
}>();
</script>

<template>
    <Head title="Commerce — Operaciones de e-commerce" />

    <div class="min-h-screen bg-aurora text-slate-900">
        <header class="relative z-20 mx-auto flex max-w-6xl items-center justify-between px-6 py-5">
            <Link
                href="/"
                class="flex items-center gap-2"
            >
                <ApplicationLogo class="h-8 w-auto fill-current text-brand" />
                <span class="text-lg font-semibold tracking-tight">Commerce</span>
            </Link>

            <nav
                v-if="canLogin"
                class="flex items-center gap-3"
            >
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('dashboard')"
                    class="rounded-md bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-hover"
                >
                    Ir al dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="rounded-md px-3 py-2 text-sm font-medium text-slate-700 transition hover:text-brand"
                    >
                        Ingresar
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="rounded-md bg-brand px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-hover"
                    >
                        Empezar
                    </Link>
                </template>
            </nav>
        </header>

        <!-- Hero: brand + one headline + one sentence + CTAs only -->
        <section class="relative overflow-hidden px-6 pb-24 pt-10 sm:pt-16 lg:pb-32 lg:pt-20">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_bottom,rgba(15,118,110,0.12),transparent_55%)]" />
            <div class="relative mx-auto max-w-4xl text-center">
                <p class="animate-fade-up text-sm font-semibold uppercase tracking-[0.2em] text-brand">
                    Commerce
                </p>
                <h1 class="text-headline animate-fade-up mt-4">
                    Tecnología para escalar
                    <em>operaciones de e-commerce.</em>
                </h1>
                <p class="animate-fade-up-delay mx-auto mt-6 max-w-2xl text-lg text-slate-600 sm:text-xl">
                    Integra tus marketplaces. Órdenes, stock y precios sincronizados en tiempo real,
                    desde un solo lugar.
                </p>
                <div class="animate-fade-up-delay mt-10 flex flex-wrap items-center justify-center gap-3">
                    <Link
                        :href="canRegister ? route('register') : route('login')"
                        class="rounded-md bg-brand px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-hover"
                    >
                        Empezar ahora
                    </Link>
                    <Link
                        :href="route('login')"
                        class="rounded-md border border-slate-300 bg-white/70 px-6 py-3 text-sm font-semibold text-slate-800 backdrop-blur transition hover:border-brand hover:text-brand"
                    >
                        Ingresar
                    </Link>
                </div>
            </div>
        </section>

        <!-- Product pillars -->
        <section class="border-t border-slate-200/80 bg-white/60 px-6 py-20 backdrop-blur-sm">
            <div class="mx-auto max-w-6xl">
                <p class="text-sm font-semibold uppercase tracking-wider text-brand">Plataforma</p>
                <h2 class="mt-3 max-w-2xl text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
                    Una sola plataforma,
                    <em class="font-medium italic text-brand">todas</em>
                    las integraciones.
                </h2>

                <div class="mt-14 grid gap-12 lg:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">01 / Órdenes</p>
                        <h3 class="mt-2 text-2xl font-semibold text-slate-900">
                            Todas las <em class="font-medium italic text-brand">órdenes</em>, en un solo inbox.
                        </h3>
                        <p class="mt-3 text-slate-600">
                            Mercado Libre y más — recibe las ventas en un solo lugar. Despacha sin saltar
                            entre pestañas.
                        </p>
                    </div>
                    <div class="space-y-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div
                            v-for="row in [
                                { id: '49221', name: 'Laura M.', status: 'Pagada', amount: '$12.480' },
                                { id: '49220', name: 'Pedro G.', status: 'Pendiente', amount: '$3.200' },
                                { id: '49219', name: 'Noelia R.', status: 'En tránsito', amount: '$24.100' },
                                { id: '49218', name: 'Iván A.', status: 'Pagada', amount: '$8.040' },
                            ]"
                            :key="row.id"
                            class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2.5 text-sm"
                        >
                            <span class="font-mono text-xs text-slate-500">#{{ row.id }}</span>
                            <span class="font-medium text-slate-800">{{ row.name }}</span>
                            <span class="text-slate-500">{{ row.status }}</span>
                            <span class="font-semibold text-slate-900">{{ row.amount }}</span>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">02 / Stock</p>
                        <h3 class="mt-2 text-2xl font-semibold text-slate-900">
                            Stock <em class="font-medium italic text-brand">real</em>, en cada canal.
                        </h3>
                        <p class="mt-3 text-slate-600">
                            Una unidad vendida en cualquier canal descuenta stock automáticamente del resto.
                            Sin sobreventas.
                        </p>
                    </div>
                    <div class="flex items-center justify-center gap-6 rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
                        <div class="text-center">
                            <p class="text-xs uppercase tracking-wider text-slate-400">ML</p>
                            <p class="mt-2 text-3xl font-semibold text-brand">−1</p>
                        </div>
                        <div class="h-10 w-px bg-slate-200" />
                        <div class="text-center">
                            <p class="text-xs uppercase tracking-wider text-slate-400">Otros</p>
                            <p class="mt-2 text-3xl font-semibold text-brand">−1</p>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">03 / Precios</p>
                        <h3 class="mt-2 text-2xl font-semibold text-slate-900">
                            Precios y ofertas <em class="font-medium italic text-brand">por canal.</em>
                        </h3>
                        <p class="mt-3 text-slate-600">
                            Ajusta márgenes por marketplace y sincroniza precios sin hojas de cálculo.
                        </p>
                    </div>
                    <div class="space-y-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2.5 text-sm">
                            <span>Mercado Libre</span>
                            <span class="font-semibold">$12.480 <span class="text-emerald-600">+8%</span></span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2.5 text-sm">
                            <span>Amazon</span>
                            <span class="font-semibold text-slate-400">Próximamente</span>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-400">04 / Publicaciones</p>
                        <h3 class="mt-2 text-2xl font-semibold text-slate-900">
                            Tu catálogo publicado en <em class="font-medium italic text-brand">todos</em> lados.
                        </h3>
                        <p class="mt-3 text-slate-600">
                            Edita fichas, pausa o activa publicaciones y mantén el matching con tu catálogo
                            interno.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="rounded-lg border border-slate-100 bg-slate-50 px-4 py-3 text-sm">
                            <p class="font-mono text-xs text-slate-500">SKU AK-029</p>
                            <p class="mt-1 font-semibold">ML</p>
                        </div>
                        <div class="rounded-lg border border-slate-100 bg-slate-50 px-4 py-3 text-sm">
                            <p class="font-mono text-xs text-slate-500">SKU AK-030</p>
                            <p class="mt-1 font-semibold text-slate-400">AM</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Monitoring mock -->
        <section class="bg-mist px-6 py-20">
            <div class="mx-auto max-w-6xl">
                <p class="text-sm font-semibold uppercase tracking-wider text-brand">Centro de monitoreo</p>
                <h2 class="mt-3 max-w-2xl text-3xl font-semibold tracking-tight sm:text-4xl">
                    Tu operación, <em class="font-medium italic text-brand">monitoreada</em> en tiempo real.
                </h2>
                <p class="mt-4 max-w-xl text-slate-600">
                    Detectamos caídas, errores y latencia antes de que se traduzcan en una venta perdida.
                </p>

                <div class="mt-10 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="flex items-center gap-2 border-b border-slate-100 px-5 py-3">
                        <span class="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500 animate-live-pulse" />
                        <span class="text-sm font-medium text-slate-800">En vivo</span>
                        <span class="text-xs text-slate-400">· actualizado hace 3 s</span>
                    </div>
                    <div class="divide-y divide-slate-100">
                        <div
                            v-for="row in [
                                { name: 'Mercado Libre', status: 'Operativo', incidents: 0 },
                                { name: 'Amazon', status: 'Próximamente', incidents: '—' },
                            ]"
                            :key="row.name"
                            class="flex items-center justify-between px-5 py-3 text-sm"
                        >
                            <span class="font-medium">{{ row.name }}</span>
                            <span
                                :class="
                                    row.status === 'Operativo'
                                        ? 'text-emerald-700'
                                        : 'text-slate-400'
                                "
                            >
                                {{ row.status }}
                            </span>
                            <span class="text-slate-500">{{ row.incidents }} incidencias</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Stats -->
        <section class="border-t border-slate-200 bg-white px-6 py-20">
            <div class="mx-auto grid max-w-6xl gap-10 sm:grid-cols-3">
                <div>
                    <p class="text-4xl font-semibold text-brand">1</p>
                    <p class="mt-2 text-sm text-slate-600">Inbox de órdenes multi-canal</p>
                </div>
                <div>
                    <p class="text-4xl font-semibold text-brand">∞</p>
                    <p class="mt-2 text-sm text-slate-600">Stock y precios sincronizados</p>
                </div>
                <div>
                    <p class="text-4xl font-semibold text-brand">24/7</p>
                    <p class="mt-2 text-sm text-slate-600">Monitoreo de integraciones</p>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="bg-slate-900 px-6 py-20 text-center text-white">
            <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                Comienza a vender
                <em class="font-medium italic text-teal-300">por nuevos canales.</em>
            </h2>
            <p class="mx-auto mt-4 max-w-lg text-slate-300">
                Conecta Mercado Libre y opera órdenes, stock, precios y publicaciones desde Commerce.
            </p>
            <Link
                :href="canRegister ? route('register') : route('login')"
                class="mt-8 inline-flex rounded-md bg-teal-500 px-6 py-3 text-sm font-semibold text-white transition hover:bg-teal-400"
            >
                Crear cuenta
            </Link>
        </section>

        <footer class="border-t border-slate-800 bg-slate-950 px-6 py-8 text-center text-sm text-slate-500">
            © {{ new Date().getFullYear() }} Commerce. Operaciones de e-commerce para LATAM.
        </footer>
    </div>
</template>
