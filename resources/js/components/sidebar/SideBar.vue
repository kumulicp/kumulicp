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

    // Icons come from several fonts whose glyphs differ in width, which pushed
    // each item's label to a different position. Give every leading icon the
    // same box so the labels line up.
    &__item__content > .va-icon:first-child {
      flex: 0 0 1.5rem;
      width: 1.5rem;
      display: inline-flex;
      justify-content: center;
      align-items: center;
    }

    // Links are wrapped in a sidebar item of their own, which doubled the
    // item's left border and indented them compared to the accordion headers.
    &-item > .va-sidebar-item {
      border-left: 0;
      padding-right: 0;
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
