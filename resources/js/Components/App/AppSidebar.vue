<script setup lang="ts">
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import SidebarNavItem from '@/Components/App/SidebarNavItem.vue';
import { useSidebarStore } from '@/stores/sidebar';
import { Link, usePage } from '@inertiajs/vue3';
import {
    LayoutDashboard,
    ShoppingCart,
    Truck,
    ShieldAlert,
    RotateCcw,
    MessageCircleQuestion,
    Package,
    Link2,
    Warehouse,
    Plug,
    LineChart,
    FileDown,
    CreditCard,
    Activity,
    Building2,
    Users,
    Shield,
    PanelLeftClose,
    PanelLeftOpen,
    ChartColumn,
    Boxes,
    Tag,
    Megaphone,
    BadgePercent,
    ScrollText,
    Timer,
} from 'lucide-vue-next';
import { computed } from 'vue';
import type { PageProps } from '@/types';

defineProps<{
    /** Force expanded (mobile drawer). */
    forceExpanded?: boolean;
}>();

const emit = defineEmits<{
    close: [];
}>();

const sidebar = useSidebarStore();
const page = usePage<PageProps>();

const isPlatformAdmin = computed(
    () => Boolean(page.props.auth?.user?.is_platform_admin),
);

const showLabels = computed(() => Boolean(sidebar.expanded));

const groups = [
    {
        title: 'Operaciones',
        items: [
            { label: 'Dashboard', routeName: 'dashboard', icon: LayoutDashboard },
            { label: 'Órdenes', routeName: 'orders.index', icon: ShoppingCart },
            { label: 'Envíos', routeName: 'shipments.index', icon: Truck },
            { label: 'Reclamos', routeName: 'claims.index', icon: ShieldAlert },
            { label: 'Devoluciones', routeName: 'returns.index', icon: RotateCcw },
            { label: 'Preguntas', routeName: 'questions.index', icon: MessageCircleQuestion },
            { label: 'Monitoreo', routeName: 'monitoring.index', icon: Activity },
            { label: 'Logs de sync', routeName: 'sync-logs.index', icon: ScrollText },
        ],
    },
    {
        title: 'Catálogo',
        items: [
            { label: 'Productos', routeName: 'products.index', icon: Package },
            { label: 'Precios', routeName: 'prices.index', icon: Tag },
            { label: 'Publicaciones', routeName: 'publications.index', icon: Megaphone },
            { label: 'Matching', routeName: 'matching.index', icon: Link2 },
        ],
    },
    {
        title: 'Inventario',
        items: [
            { label: 'Stock', routeName: 'stock.index', icon: Boxes },
            { label: 'Almacenes', routeName: 'inventory.warehouses.index', icon: Warehouse },
            { label: 'Movimientos', routeName: 'inventory.ledger.index', icon: ScrollText },
            { label: 'Movimientos Full', routeName: 'inventory.full-operations.index', icon: Truck },
            { label: 'Ingresos', routeName: 'inventory.receipts.index', icon: Warehouse },
        ],
    },
    {
        title: 'Integraciones',
        items: [
            { label: 'Conexiones', routeName: 'connections.index', icon: Plug },
        ],
    },
    {
        title: 'Finanzas',
        items: [
            { label: 'Analytics', routeName: 'analytics.dashboards.index', icon: ChartColumn },
            { label: 'Finance', routeName: 'finance.dashboard', icon: LineChart },
            { label: 'Caja / pagos', routeName: 'finance.cash.index', icon: CreditCard },
            { label: 'Publicidad', routeName: 'ads.dashboard', icon: BadgePercent },
            { label: 'Asistente Ads', routeName: 'ads.assistant', icon: BadgePercent },
            { label: 'Exports', routeName: 'exports.index', icon: FileDown },
            { label: 'Billing', routeName: 'billing.index', icon: CreditCard },
        ],
    },
] as const;

const adminItems = [
    { label: 'Workspaces', routeName: 'admin.workspaces.index', icon: Building2 },
    { label: 'Users', routeName: 'admin.users.index', icon: Users },
    { label: 'Connections', routeName: 'admin.connections.index', icon: Plug },
    { label: 'Analytics templates', routeName: 'admin.analytics.templates.index', icon: ChartColumn },
    { label: 'Diagnostic logging', routeName: 'admin.diagnostic-logging.show', icon: Timer },
] as const;
</script>

<template>
    <aside
        class="flex h-full flex-col border-r border-slate-200 bg-white transition-[width] duration-200 ease-out"
        :class="showLabels || forceExpanded ? 'w-64' : 'w-16'"
        aria-label="Main navigation"
        @mouseenter="sidebar.setHovered(true)"
        @mouseleave="sidebar.setHovered(false)"
    >
        <div
            class="flex h-14 shrink-0 items-center border-b border-slate-200"
            :class="showLabels || forceExpanded ? 'gap-2 px-3' : 'justify-center px-2'"
        >
            <Link
                :href="route('dashboard')"
                class="flex min-w-0 items-center gap-2"
                :title="showLabels || forceExpanded ? undefined : 'Commerce'"
                @click="emit('close')"
            >
                <ApplicationLogo class="h-8 w-auto shrink-0 fill-current text-brand" />
                <span
                    v-show="showLabels || forceExpanded"
                    class="truncate text-sm font-semibold tracking-tight text-slate-900"
                >
                    Commerce
                </span>
            </Link>

            <button
                v-if="showLabels || forceExpanded"
                type="button"
                class="ml-auto hidden shrink-0 rounded-md p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 md:inline-flex"
                :aria-pressed="sidebar.pinned"
                :aria-label="sidebar.pinned ? 'Dejar de fijar menú' : 'Mantener menú expandido'"
                :title="sidebar.pinned ? 'Colapsar al salir' : 'Mantener expandido'"
                @click.stop="sidebar.togglePinned()"
            >
                <PanelLeftClose
                    v-if="sidebar.pinned"
                    class="h-4 w-4"
                />
                <PanelLeftOpen
                    v-else
                    class="h-4 w-4"
                />
            </button>
        </div>

        <nav
            class="flex-1 space-y-5 overflow-y-auto overflow-x-hidden py-4"
            :class="showLabels || forceExpanded ? 'px-3' : 'px-2'"
        >
            <div
                v-for="group in groups"
                :key="group.title"
            >
                <p
                    v-show="showLabels || forceExpanded"
                    class="mb-1.5 px-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400"
                >
                    {{ group.title }}
                </p>
                <div
                    v-show="!(showLabels || forceExpanded)"
                    class="mx-auto mb-1.5 h-px w-6 bg-slate-200"
                    aria-hidden="true"
                />
                <div class="space-y-0.5">
                    <SidebarNavItem
                        v-for="item in group.items"
                        :key="item.routeName"
                        :href="route(item.routeName)"
                        :label="item.label"
                        :icon="item.icon"
                        :active="route().current(item.routeName)"
                        :expanded="showLabels || forceExpanded"
                        @click="emit('close')"
                    />
                </div>
            </div>

            <div v-if="isPlatformAdmin">
                <p
                    v-show="showLabels || forceExpanded"
                    class="mb-1.5 px-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400"
                >
                    Admin
                </p>
                <div
                    v-show="!(showLabels || forceExpanded)"
                    class="mx-auto mb-1.5 h-px w-6 bg-slate-200"
                    aria-hidden="true"
                />
                <div class="space-y-0.5">
                    <SidebarNavItem
                        v-for="item in adminItems"
                        :key="item.routeName"
                        :href="route(item.routeName)"
                        :label="item.label"
                        :icon="item.icon"
                        :active="route().current(item.routeName.replace('.index', '.*'))"
                        :expanded="showLabels || forceExpanded"
                        @click="emit('close')"
                    />
                </div>
            </div>
        </nav>

        <div
            class="shrink-0 space-y-0.5 border-t border-slate-200 py-3"
            :class="showLabels || forceExpanded ? 'px-3' : 'px-2'"
        >
            <SidebarNavItem
                :href="route('workspaces.index')"
                label="Workspaces"
                :icon="Building2"
                :active="route().current('workspaces.index')"
                :expanded="showLabels || forceExpanded"
                @click="emit('close')"
            />
            <SidebarNavItem
                :href="route('workspaces.members.index')"
                label="Miembros"
                :icon="Users"
                :active="route().current('workspaces.members.*')"
                :expanded="showLabels || forceExpanded"
                @click="emit('close')"
            />
            <SidebarNavItem
                v-if="isPlatformAdmin"
                :href="route('admin.workspaces.index')"
                label="Platform admin"
                :icon="Shield"
                :active="route().current('admin.*')"
                :expanded="showLabels || forceExpanded"
                @click="emit('close')"
            />

            <button
                v-if="!(showLabels || forceExpanded)"
                type="button"
                class="mx-auto mt-1 hidden h-9 w-9 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 md:flex"
                :aria-pressed="sidebar.pinned"
                aria-label="Mantener menú expandido"
                title="Mantener expandido"
                @click.stop="sidebar.togglePinned()"
            >
                <PanelLeftOpen class="h-4 w-4" />
            </button>
        </div>
    </aside>
</template>
