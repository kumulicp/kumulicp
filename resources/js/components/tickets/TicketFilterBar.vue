<script setup>
// Search plus filters. From the medium breakpoint the filters sit in one row; on a
// phone only the search shows, with a "Filters" button that unfolds the rest.
import { ref } from 'vue'

defineProps({
  // How many filters are set (shown on the button)
  active: { type: Number, default: 0 },
})

const open = ref(false)
</script>
<template>
  <div class="t-filters" :class="{ 't-filters--open': open }">
    <div class="t-filters__fields">
      <slot />
    </div>
    <div class="t-filters__bar">
      <div class="t-filters__search"><slot name="search" /></div>
      <va-button
        class="t-filters__toggle"
        preset="secondary" border-color="primary"
        icon="tune"
        :aria-expanded="open"
        @click="open = !open"
      >{{ $t('tickets.list.filters') }}<template v-if="active"> ({{ active }})</template></va-button>
    </div>
  </div>
</template>
