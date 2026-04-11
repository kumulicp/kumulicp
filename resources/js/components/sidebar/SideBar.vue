<script setup>
import MenuAccordion from './menu/MenuAccordion.vue'
</script>
<template>
  <va-sidebar :width="width" :minimized="minimized" :minimized-width="minimizedWidth" animated :hoverable="minimized">
    <menu-accordion :items="$page.props.menu" @toggle-menu="toggleMenu" />
  </va-sidebar>
</template>
<script>
export default {
  props: {
    isTablet: Boolean,
    width: String,
    sidebarMinimizedWidth: String
  },
  methods: {
    toggleMenu () {
      if (this.isTablet) {
        this.$emit('toggleMenu')
      }
    }
  }
}
</script>

<style lang="scss">
  .va-sidebar {
    &__menu {
      padding: 2rem 0;
    }

    &-item {
      &__icon {
        width: 1.5rem;
        height: 1.5rem;
        display: flex;
        justify-content: center;
        align-items: center;
      }
    }
  }
</style>
