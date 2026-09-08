<template>
  <ActionGroup
    preset="record-row"
    :align="align"
    :aria-label="ariaLabel"
    role="group"
  >
    <template v-for="action in visibleActions" :key="action.key ?? action.label">
      <ActionButton
        v-bind="actionButtonProps(action)"
        @click="onActionClick(action, $event)"
      />
    </template>
  </ActionGroup>
</template>

<script setup>
import { computed } from 'vue'
import ActionGroup from './ActionGroup.vue'
import ActionButton from './ActionButton.vue'

const props = defineProps({
  actions: {
    type: Array,
    default: () => [],
  },
  align: {
    type: String,
    default: 'end',
  },
  ariaLabel: {
    type: String,
    default: 'Acciones del registro',
  },
  iconOnlyFrom: {
    type: String,
    default: 'lg',
  },
  processing: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['action'])

const visibleActions = computed(() =>
  (props.actions || []).filter((a) => a.show !== false)
)

function actionButtonProps(action) {
  const iconOnly = action.iconOnly ?? true
  return {
    label: action.label,
    icon: action.icon,
    variant: action.variant ?? 'neutral-soft',
    href: action.href,
    inertia: action.inertia,
    inertia: action.inertia,
    disabled: action.disabled,
    loading: action.loading || (props.processing && action.loadingOnProcessing),
    iconOnly,
    showIconOnlyMobileLabel: action.showMobileLabel ?? iconOnly,
    title: action.title ?? action.label,
    size: 'sm',
    innerClass: action.class,
    type: action.type ?? 'button',
    form: action.form,
  }
}

function onActionClick(action, event) {
  if (action.href && !action.onClick) return
  event?.preventDefault?.()
  emit('action', { key: action.key, action, event })
  action.onClick?.(event)
}
</script>
