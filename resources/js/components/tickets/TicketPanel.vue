<script setup>
// A card in the side column. From the large breakpoint up it is a plain card;
// on smaller screens its title becomes a button that folds the body away, so the
// conversation and the reply box stay close together.
import { ref } from 'vue'

const props = defineProps({
  title: { type: String, required: true },
  // Whether it starts unfolded on a small screen
  open: { type: Boolean, default: false },
})

const isOpen = ref(props.open)
</script>
<template>
  <va-card class="t-panel" :class="{ 't-panel--open': isOpen }">
    <va-card-title>
      <button type="button" class="t-panel__head" :aria-expanded="isOpen" @click="isOpen = !isOpen">
        <span class="t-panel__title">{{ title }}</span>
        <slot name="aside" />
        <va-icon name="expand_more" size="small" class="t-panel__chevron" />
      </button>
    </va-card-title>
    <va-card-content class="t-panel__body">
      <slot />
    </va-card-content>
  </va-card>
</template>
