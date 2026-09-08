<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ExportToolbarButton from '@/Components/Export/ExportToolbarButton.vue';
import { buildExportSelectionPayload } from '@/composables/buildExportSelectionPayload';
import DataTable from '@/Components/App/DataTable.vue';
import QuestionsSearchAndFilters from '@/Components/Questions/SearchAndFilters.vue';
import Badge from '@/Components/ui/Badge.vue';
import TableHead from '@/Components/ui/TableHead.vue';
import TableRow from '@/Components/ui/TableRow.vue';
import TableCell from '@/Components/ui/TableCell.vue';
import TableSelectionCheckbox from '@/Components/ui/TableSelectionCheckbox.vue';
import RecordSelectionBar from '@/Components/ui/ListToolbar/RecordSelectionBar.vue';
import { useRecordSelection } from '@/composables/useRecordSelection';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, defineAsyncComponent, ref } from 'vue';
import { formatDateTime, formatRelativeShort } from '@/lib/utils';

const QuestionDetailSlideOver = defineAsyncComponent(
    () => import('@/Components/Questions/QuestionDetailSlideOver.vue'),
);

interface QuestionRow {
    id: number;
    external_question_id: string | null;
    external_item_id: string | null;
    buyer_external_id?: string | null;
    status: string;
    question_text: string | null;
    answer_text?: string | null;
    asked_at: string | null;
    connection?: { id: number; provider: string } | null;
}

interface PaginatedQuestions {
    data: QuestionRow[];
    total?: number;
    links?: Array<{ url: string | null; label: string; active: boolean }>;
}

const props = defineProps<{
    questions: PaginatedQuestions;
    filters: {
        q: string;
        connection_id: number | null;
        tab: string;
    };
    status_counts: Record<string, number>;
    connections: Array<{
        id: number;
        provider: string;
        external_user_id: string | null;
        display_name: string | null;
    }>;
}>();

const questionOpen = ref(false);
const selectedQuestionId = ref<number | null>(null);

const tabs = computed(() => [
    { key: 'all', label: 'Todas', count: Number(props.status_counts.all ?? 0) },
    { key: 'unanswered', label: 'Sin responder', count: Number(props.status_counts.unanswered ?? 0) },
    { key: 'answered', label: 'Respondidas', count: Number(props.status_counts.answered ?? 0) },
]);

function statusLabel(status: string) {
    return (
        {
            unanswered: 'Sin responder',
            answered: 'Respondida',
            closed_unanswered: 'Cerrada',
            under_review: 'En revisión',
            banned: 'Bloqueada',
            deleted: 'Eliminada',
        }[status] ?? status
    );
}

function statusVariant(status: string) {
    if (status === 'unanswered') return 'warning';
    if (status === 'answered') return 'success';
    return 'secondary';
}

function selectTab(tab: string) {
    router.get(
        route('questions.index'),
        {
            q: props.filters.q || undefined,
            connection_id: props.filters.connection_id || undefined,
            tab,
        },
        { preserveState: true, replace: true },
    );
}

function openQuestion(id: number) {
    selectedQuestionId.value = id;
    questionOpen.value = true;
}

function closeQuestion() {
    questionOpen.value = false;
    selectedQuestionId.value = null;
}

const questionsTotalCount = computed(
    () => Number(props.questions.total ?? props.questions.data?.length ?? 0) || 0,
);

const {
    selectedIds,
    allPagesSelected,
    hasSelection,
    bulkSelectionProps,
    allLoadedSelected,
    someLoadedSelected,
    isSelected,
    toggle,
    onHeaderCheckboxChange,
    selectAllInFilteredUniverse,
    clearSelection,
} = useRecordSelection({
    loadedRows: computed(() => props.questions.data ?? []),
    totalCount: questionsTotalCount,
    summableColumns: computed(() => []),
    currentQuery: () => ({
        q: props.filters.q || undefined,
        connection_id: props.filters.connection_id || undefined,
        tab: props.filters.tab || undefined,
    }),
    endpoints: {
        allIds: () => route('questions.all-ids'),
        filteredSums: () => route('questions.filtered-sums'),
    },
    itemLabel: 'preguntas',
});
</script>

<template>
    <Head title="Preguntas" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Preguntas"
                    description="Preguntas pre-venta de compradores sobre tus publicaciones."
                >
                    <template #actions>
                        <ExportToolbarButton
                            target-module="questions"
                            :get-payload="() => buildExportSelectionPayload({
                                selectedIds,
                                allPagesSelected,
                                totalCount: questionsTotalCount,
                            })"
                        />
                    </template>
                </PageHeader>

                <div class="mb-4 flex flex-wrap gap-2">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            (filters.tab || 'all') === tab.key
                                ? 'bg-brand text-white'
                                : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                        "
                        @click="selectTab(tab.key)"
                    >
                        {{ tab.label }}
                        <span class="ml-1 opacity-70">{{ tab.count }}</span>
                    </button>
                </div>

                <QuestionsSearchAndFilters
                    :filters="filters"
                    :connections="connections"
                />

                <RecordSelectionBar
                    :has-selection="hasSelection"
                    :bulk-selection-props="bulkSelectionProps"
                    @select-all-filtered="selectAllInFilteredUniverse"
                    @clear-selection="clearSelection"
                    @header-toggle="onHeaderCheckboxChange"
                />

                <DataTable
                    :is-empty="questions.data.length === 0"
                    empty-title="Sin preguntas"
                    empty-description="Sincroniza el recurso Preguntas en la conexión para verlas aquí."
                >
                    <template #head>
                        <TableHead class="w-10">
                            <TableSelectionCheckbox
                                :checked="allLoadedSelected"
                                :indeterminate="someLoadedSelected"
                                aria-label="Seleccionar preguntas cargadas"
                                @change="onHeaderCheckboxChange"
                            />
                        </TableHead>
                        <TableHead>Pregunta</TableHead>
                        <TableHead>Ítem</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead>Fecha</TableHead>
                    </template>

                    <TableRow
                        v-for="row in questions.data"
                        :key="row.id"
                        class="cursor-pointer"
                        @click="openQuestion(row.id)"
                    >
                        <TableCell class="w-10" @click.stop>
                            <TableSelectionCheckbox
                                :checked="isSelected(row.id)"
                                aria-label="Seleccionar pregunta"
                                @change="toggle(row.id)"
                            />
                        </TableCell>
                        <TableCell class="max-w-lg">
                            <p class="line-clamp-2 text-sm font-medium text-slate-900">
                                {{ row.question_text ?? '—' }}
                            </p>
                            <p class="mt-0.5 font-mono text-[11px] text-muted-foreground">
                                #{{ row.external_question_id ?? row.id }}
                            </p>
                        </TableCell>
                        <TableCell class="font-mono text-xs">
                            {{ row.external_item_id ?? '—' }}
                        </TableCell>
                        <TableCell>
                            <Badge :variant="statusVariant(row.status)">
                                {{ statusLabel(row.status) }}
                            </Badge>
                        </TableCell>
                        <TableCell>
                            <template v-if="row.asked_at">
                                <div class="text-sm text-slate-800">
                                    {{ formatDateTime(row.asked_at) }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ formatRelativeShort(row.asked_at) }}
                                </div>
                            </template>
                            <template v-else>—</template>
                        </TableCell>
                    </TableRow>
                </DataTable>

                <div
                    v-if="questions.links?.length"
                    class="mt-4 flex flex-wrap gap-2"
                >
                    <Link
                        v-for="link in questions.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        class="rounded-md px-3 py-1 text-sm"
                        :class="
                            link.active
                                ? 'bg-brand text-white'
                                : 'bg-white text-slate-600 ring-1 ring-slate-200'
                        "
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>

        <QuestionDetailSlideOver
            :show="questionOpen"
            :question-id="selectedQuestionId ?? undefined"
            @close="closeQuestion"
        />
    </AuthenticatedLayout>
</template>
