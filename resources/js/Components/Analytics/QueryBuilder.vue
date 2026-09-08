<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import Input from '@/Components/ui/Input.vue';
import { computed, ref, watch } from 'vue';

export interface CatalogColumn {
    key: string;
    label: string;
    type: string;
    role: string;
    aggregations: string[];
    filterable: boolean;
}

export interface CatalogDataset {
    key: string;
    label: string;
    description: string;
    columns: CatalogColumn[];
    joins: { dataset: string }[];
}

export type FilterValue = string | number | boolean | Array<string | number> | null;

export interface AnalyticsQuery {
    dataset: string;
    dimensions: string[];
    measures: Array<{ field?: string; agg?: string; alias?: string; formula?: string }>;
    filters: Array<{ field: string; op: string; value?: FilterValue }>;
    sort: Array<{ field: string; dir?: string }>;
    limit: number;
    pivot?: { rows?: string[]; columns?: string[]; values?: string[] } | null;
    join_datasets?: string[];
}

const props = defineProps<{
    catalog: CatalogDataset[];
    modelValue: AnalyticsQuery;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: AnalyticsQuery];
    preview: [];
}>();

const local = ref<AnalyticsQuery>(structuredClone(props.modelValue));

watch(
    () => props.modelValue,
    (v) => {
        local.value = structuredClone(v);
    },
    { deep: true },
);

const dataset = computed(() => props.catalog.find((d) => d.key === local.value.dataset));
const dimensions = computed(() => dataset.value?.columns.filter((c) => c.role === 'dimension') ?? []);
const measures = computed(() => dataset.value?.columns.filter((c) => c.aggregations?.length) ?? []);

const sync = () => emit('update:modelValue', structuredClone(local.value));

const toggleDimension = (key: string) => {
    const idx = local.value.dimensions.indexOf(key);
    if (idx >= 0) local.value.dimensions.splice(idx, 1);
    else local.value.dimensions.push(key);
    sync();
};

const addMeasure = (col: CatalogColumn) => {
    const agg = col.aggregations[0] ?? 'sum';
    local.value.measures.push({ field: col.key, agg, alias: `${col.key}_${agg}` });
    sync();
};

const addFormula = () => {
    local.value.measures.push({ formula: 'sum(profit)/sum(revenue)', alias: 'margin_pct' });
    sync();
};

const removeMeasure = (index: number) => {
    local.value.measures.splice(index, 1);
    sync();
};

const addFilter = () => {
    const field = dimensions.value[0]?.key ?? 'status';
    local.value.filters.push({ field, op: 'eq', value: '' });
    sync();
};

const removeFilter = (index: number) => {
    local.value.filters.splice(index, 1);
    sync();
};

const setDataset = (key: string) => {
    local.value.dataset = key;
    local.value.dimensions = [];
    local.value.measures = [];
    local.value.filters = [];
    local.value.join_datasets = [];
    sync();
};

const toggleJoin = (key: string) => {
    const joins = local.value.join_datasets ?? [];
    const idx = joins.indexOf(key);
    if (idx >= 0) joins.splice(idx, 1);
    else joins.push(key);
    local.value.join_datasets = joins;
    sync();
};

const enablePivot = computed({
    get: () => Boolean(local.value.pivot),
    set: (v: boolean) => {
        local.value.pivot = v
            ? {
                  rows: local.value.dimensions.slice(0, 1),
                  columns: local.value.dimensions.slice(1, 2),
                  values: local.value.measures.map((m) => m.alias || m.field || '').filter(Boolean),
              }
            : null;
        sync();
    },
});
</script>

<template>
    <div class="space-y-4">
        <div>
            <label class="mb-1 block text-sm font-medium">Dataset</label>
            <select
                class="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                :value="local.dataset"
                @change="setDataset(($event.target as HTMLSelectElement).value)"
            >
                <option v-for="d in catalog" :key="d.key" :value="d.key">{{ d.label }}</option>
            </select>
            <p v-if="dataset" class="mt-1 text-xs text-muted-foreground">{{ dataset.description }}</p>
        </div>

        <div>
            <p class="mb-2 text-sm font-medium">Dimensiones</p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="col in dimensions"
                    :key="col.key"
                    type="button"
                    class="rounded-full border px-3 py-1 text-xs"
                    :class="
                        local.dimensions.includes(col.key) || local.dimensions.includes(col.key + ':day')
                            ? 'border-slate-900 bg-slate-900 text-white'
                            : 'border-slate-200 bg-white'
                    "
                    @click="toggleDimension(col.type === 'date' ? col.key + ':day' : col.key)"
                >
                    {{ col.label }}
                </button>
            </div>
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <p class="text-sm font-medium">Métricas</p>
                <div class="flex gap-2">
                    <Button type="button" size="sm" variant="outline" @click="addFormula">+ Fórmula</Button>
                </div>
            </div>
            <div class="mb-2 flex flex-wrap gap-2">
                <button
                    v-for="col in measures"
                    :key="col.key"
                    type="button"
                    class="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs"
                    @click="addMeasure(col)"
                >
                    + {{ col.label }}
                </button>
            </div>
            <ul class="space-y-2">
                <li
                    v-for="(m, i) in local.measures"
                    :key="i"
                    class="flex flex-wrap items-center gap-2 rounded border border-slate-100 bg-slate-50 p-2 text-sm"
                >
                    <template v-if="m.formula">
                        <Input v-model="m.formula" class="min-w-[200px] flex-1" @change="sync" />
                        <Input v-model="m.alias" class="w-36" placeholder="alias" @change="sync" />
                    </template>
                    <template v-else>
                        <span class="font-medium">{{ m.field }}</span>
                        <select v-model="m.agg" class="rounded border px-2 py-1 text-xs" @change="sync">
                            <option
                                v-for="a in measures.find((c) => c.key === m.field)?.aggregations || ['sum']"
                                :key="a"
                                :value="a"
                            >
                                {{ a }}
                            </option>
                        </select>
                        <Input v-model="m.alias" class="w-36" @change="sync" />
                    </template>
                    <Button type="button" size="sm" variant="outline" @click="removeMeasure(i)">Quitar</Button>
                </li>
            </ul>
        </div>

        <div v-if="dataset?.joins?.length">
            <p class="mb-2 text-sm font-medium">Joins</p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="j in dataset.joins"
                    :key="j.dataset"
                    type="button"
                    class="rounded-full border px-3 py-1 text-xs"
                    :class="
                        (local.join_datasets || []).includes(j.dataset)
                            ? 'border-slate-900 bg-slate-900 text-white'
                            : 'border-slate-200 bg-white'
                    "
                    @click="toggleJoin(j.dataset)"
                >
                    {{ j.dataset }}
                </button>
            </div>
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <p class="text-sm font-medium">Filtros</p>
                <Button type="button" size="sm" variant="outline" @click="addFilter">+ Filtro</Button>
            </div>
            <ul class="space-y-2">
                <li v-for="(f, i) in local.filters" :key="i" class="flex flex-wrap gap-2">
                    <select v-model="f.field" class="rounded border px-2 py-1 text-sm" @change="sync">
                        <option v-for="col in dataset?.columns || []" :key="col.key" :value="col.key">
                            {{ col.label }}
                        </option>
                    </select>
                    <select v-model="f.op" class="rounded border px-2 py-1 text-sm" @change="sync">
                        <option value="eq">=</option>
                        <option value="neq">≠</option>
                        <option value="gt">&gt;</option>
                        <option value="gte">≥</option>
                        <option value="lt">&lt;</option>
                        <option value="lte">≤</option>
                        <option value="in">in</option>
                        <option value="between">between</option>
                        <option value="like">like</option>
                        <option value="is_null">is null</option>
                        <option value="is_not_null">not null</option>
                    </select>
                    <Input
                        v-if="f.op !== 'is_null' && f.op !== 'is_not_null'"
                        :model-value="String(f.value ?? '')"
                        class="min-w-[140px] flex-1"
                        @update:model-value="(v) => { f.value = String(v); sync(); }"
                    />
                    <Button type="button" size="sm" variant="outline" @click="removeFilter(i)">Quitar</Button>
                </li>
            </ul>
        </div>

        <div class="flex flex-wrap items-center gap-4">
            <label class="flex items-center gap-2 text-sm">
                <input v-model="enablePivot" type="checkbox" />
                Pivot
            </label>
            <div>
                <label class="mr-2 text-sm">Limit</label>
                <Input
                    type="number"
                    class="w-24"
                    :model-value="String(local.limit)"
                    @update:model-value="(v) => { local.limit = Number(v) || 500; sync(); }"
                />
            </div>
            <Button type="button" size="sm" @click="emit('preview')">Preview</Button>
        </div>
    </div>
</template>
