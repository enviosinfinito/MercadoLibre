<script setup>
defineProps({
  loading: Boolean,
  error: { type: [Object, Error, String], default: null },
  headers: { type: Array, default: () => [] },
  rows: { type: Array, default: () => [] },
  totalRows: { type: Number, default: 0 },
})
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-3 p-4">
    <p
      v-if="loading"
      class="text-sm text-slate-500"
    >
      Cargando preview…
    </p>
    <p
      v-else-if="error"
      class="text-sm text-rose-600"
    >
      No se pudo leer el archivo.
    </p>
    <template v-else>
      <p class="text-xs text-slate-500">
        Mostrando {{ rows.length }} de {{ totalRows }} filas
      </p>
      <div class="min-h-0 flex-1 overflow-auto rounded border border-slate-200">
        <table class="min-w-full border-collapse text-left text-xs">
          <thead class="sticky top-0 bg-slate-50">
            <tr>
              <th
                v-for="(h, i) in headers"
                :key="i"
                class="whitespace-nowrap border-b border-slate-200 px-2 py-1.5 font-semibold text-slate-700"
              >
                {{ h }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(row, ri) in rows"
              :key="ri"
              class="odd:bg-white even:bg-slate-50/60"
            >
              <td
                v-for="(cell, ci) in row"
                :key="ci"
                class="whitespace-nowrap border-b border-slate-100 px-2 py-1 text-slate-700"
              >
                {{ cell }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
