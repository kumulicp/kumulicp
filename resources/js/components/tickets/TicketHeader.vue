<script setup>
// Page heading for the support desk: a small reference line, the title, badges,
// and the main actions. Wraps neatly on a phone (actions drop below, full width).
defineProps({
  title: { type: String, required: true },
  eyebrow: { type: String, default: '' },
})
</script>
<template>
  <header class="t-header">
    <div class="t-header__main">
      <div v-if="eyebrow" class="t-muted">{{ eyebrow }}</div>
      <h1 class="t-header__title">{{ title }}</h1>
      <div v-if="$slots.meta" class="t-header__meta"><slot name="meta" /></div>
    </div>
    <div v-if="$slots.actions" class="t-header__actions"><slot name="actions" /></div>
  </header>
</template>
