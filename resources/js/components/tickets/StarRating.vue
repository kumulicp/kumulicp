<script setup>
// One to five stars, tappable. Plain buttons and text glyphs, so it does not
// depend on an icon font and has a comfortable touch size.
defineProps({
  modelValue: { type: Number, default: 0 },
})
defineEmits(['update:modelValue'])
</script>
<template>
  <div class="star-rating" role="radiogroup">
    <button
      v-for="star in 5"
      :key="star"
      type="button"
      role="radio"
      class="star-rating__star"
      :class="{ 'star-rating__star--on': star <= modelValue }"
      :aria-checked="star === modelValue"
      :aria-label="`${star} / 5`"
      @click="$emit('update:modelValue', star === modelValue ? 0 : star)"
    >{{ star <= modelValue ? '★' : '☆' }}</button>
  </div>
</template>
<style>
  .star-rating {
    display: inline-flex;
    gap: 0.15rem;
  }

  .star-rating__star {
    min-width: 2.75rem;
    min-height: 2.75rem;
    padding: 0;
    border: 0;
    background: none;
    color: var(--va-text-secondary);
    font-size: 2rem;
    line-height: 1;
    cursor: pointer;
  }

  .star-rating__star--on {
    color: #f5a623;
  }

  .star-rating__star:focus-visible {
    outline: 2px solid var(--va-primary);
    border-radius: 0.375rem;
  }
</style>
