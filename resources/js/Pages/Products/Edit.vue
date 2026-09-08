<script setup lang="ts">
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import Badge from '@/Components/ui/Badge.vue';
import Card from '@/Components/ui/Card.vue';
import Input from '@/Components/ui/Input.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';

type VariantRow = {
    id?: number;
    sku: string;
    name: string;
    gtin: string;
};

type ProductProp = {
    id: number;
    name: string;
    description: string | null;
    status: string;
    variants: Array<{
        id: number;
        sku: string;
        name: string | null;
        gtin: string | null;
    }>;
};

type MatchedListing = {
    id: number;
    variant_id: number;
    variant_sku: string;
    sku_external: string | null;
    external_variation_id: string | null;
    listing_id: number | null;
    title: string | null;
    external_item_id: string | null;
    permalink: string | null;
    provider: string | null;
    status: string | null;
};

const props = defineProps<{
    product: ProductProp;
    matchedListings: MatchedListing[];
}>();

const form = useForm({
    name: props.product.name,
    description: props.product.description ?? '',
    status: props.product.status || 'active',
    variants: (props.product.variants.length > 0
        ? props.product.variants.map((v) => ({
              id: v.id,
              sku: v.sku,
              name: v.name ?? '',
              gtin: v.gtin ?? '',
          }))
        : [{ sku: '', name: '', gtin: '' }]) as VariantRow[],
});

const page = usePage();
const flashSuccess = computed(
    () => (page.props.flash as { success?: string } | undefined)?.success,
);

const addVariant = () => {
    form.variants.push({ sku: '', name: '', gtin: '' });
};

const removeVariant = (index: number) => {
    if (form.variants.length <= 1) {
        return;
    }
    form.variants.splice(index, 1);
};

const submit = () => {
    form.put(route('products.update', props.product.id));
};

const variantError = (index: number, field: string): string | undefined => {
    return (form.errors as Record<string, string | undefined>)[`variants.${index}.${field}`];
};
</script>

<template>
    <Head title="Edit product" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <PageHeader
                    title="Edit product"
                    description="Update catalog details and SKUs used for marketplace matching."
                >
                    <template #actions>
                        <Link :href="route('products.index')">
                            <Button size="sm" variant="outline" type="button">
                                Back to products
                            </Button>
                        </Link>
                    </template>
                </PageHeader>

                <p
                    v-if="flashSuccess"
                    class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800"
                >
                    {{ flashSuccess }}
                </p>

                <Card class="mb-6">
                    <div class="space-y-3">
                        <div>
                            <h2 class="text-sm font-medium">Linked listings</h2>
                            <p class="text-xs text-muted-foreground">
                                Marketplace publications matched to this product’s SKUs.
                                Matched items no longer appear in Matching.
                            </p>
                        </div>

                        <p
                            v-if="matchedListings.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No listings linked yet. Match them from
                            <Link
                                :href="route('matching.index')"
                                class="font-medium text-primary underline-offset-2 hover:underline"
                            >
                                Matching
                            </Link>
                            or sync listings with the same SKU.
                        </p>

                        <ul v-else class="divide-y divide-border rounded-md border border-border">
                            <li
                                v-for="row in matchedListings"
                                :key="row.id"
                                class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium">
                                        {{ row.title || 'Untitled listing' }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        <span v-if="row.external_item_id">
                                            {{ row.external_item_id }}
                                        </span>
                                        <span v-if="row.provider"> · {{ row.provider }}</span>
                                        <span> · SKU {{ row.variant_sku }}</span>
                                        <span v-if="row.sku_external">
                                            ← {{ row.sku_external }}
                                        </span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <Badge
                                        v-if="row.status"
                                        variant="secondary"
                                        class="capitalize"
                                    >
                                        {{ row.status }}
                                    </Badge>
                                    <a
                                        v-if="row.permalink"
                                        :href="row.permalink"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        <Button size="sm" variant="outline" type="button">
                                            Open listing
                                        </Button>
                                    </a>
                                </div>
                            </li>
                        </ul>
                    </div>
                </Card>

                <Card>
                    <form class="space-y-6" @submit.prevent="submit">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Name</label>
                            <Input v-model="form.name" required maxlength="255" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-destructive">
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">Description</label>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                class="flex w-full rounded-md border border-input bg-white px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            />
                            <p
                                v-if="form.errors.description"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ form.errors.description }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium">Status</label>
                            <select
                                v-model="form.status"
                                class="flex h-10 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <p v-if="form.errors.status" class="mt-1 text-xs text-destructive">
                                {{ form.errors.status }}
                            </p>
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h2 class="text-sm font-medium">Variants</h2>
                                    <p class="text-xs text-muted-foreground">
                                        At least one SKU is required. Removed variants are archived
                                        and unmatched from listings.
                                    </p>
                                </div>
                                <Button size="sm" variant="outline" type="button" @click="addVariant">
                                    Add variant
                                </Button>
                            </div>

                            <p v-if="form.errors.variants" class="text-xs text-destructive">
                                {{ form.errors.variants }}
                            </p>

                            <div
                                v-for="(variant, index) in form.variants"
                                :key="variant.id ?? `new-${index}`"
                                class="space-y-3 rounded-md border border-border p-4"
                            >
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-medium text-muted-foreground">
                                        Variant {{ index + 1 }}
                                    </span>
                                    <Button
                                        v-if="form.variants.length > 1"
                                        size="sm"
                                        variant="outline"
                                        type="button"
                                        @click="removeVariant(index)"
                                    >
                                        Remove
                                    </Button>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-3">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium">SKU</label>
                                        <Input
                                            v-model="variant.sku"
                                            required
                                            maxlength="255"
                                            placeholder="DEMO-001"
                                        />
                                        <p
                                            v-if="variantError(index, 'sku')"
                                            class="mt-1 text-xs text-destructive"
                                        >
                                            {{ variantError(index, 'sku') }}
                                        </p>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium">
                                            Variant name
                                        </label>
                                        <Input
                                            v-model="variant.name"
                                            maxlength="255"
                                            placeholder="Optional"
                                        />
                                        <p
                                            v-if="variantError(index, 'name')"
                                            class="mt-1 text-xs text-destructive"
                                        >
                                            {{ variantError(index, 'name') }}
                                        </p>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium">GTIN</label>
                                        <Input
                                            v-model="variant.gtin"
                                            maxlength="64"
                                            placeholder="Optional"
                                        />
                                        <p
                                            v-if="variantError(index, 'gtin')"
                                            class="mt-1 text-xs text-destructive"
                                        >
                                            {{ variantError(index, 'gtin') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 pt-2">
                            <Link :href="route('products.index')">
                                <Button variant="outline" type="button">Cancel</Button>
                            </Link>
                            <Button type="submit" :disabled="form.processing">
                                {{ form.processing ? 'Saving…' : 'Save changes' }}
                            </Button>
                        </div>
                    </form>
                </Card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
