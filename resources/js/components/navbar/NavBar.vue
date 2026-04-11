<script setup>
import { computed } from 'vue'
import { useColors } from 'vuestic-ui'
import VuesticLogo from '@/components/VuesticLogo.vue'
import VaIconMenuCollapsed from '@/components/icons/VaIconMenuCollapsed.vue'
import AppNavbarActions from './components/AppNavbarActions.vue'
import NotificationsDropdown from './components/dropdowns/NotificationsDropdown.vue'
import HelpDeskModal from './components/modals/HelpDeskModal.vue'

const { getColors } = useColors()
const colors = computed(() => getColors())
</script>
<template>
  <VaNavbar shadowed>
    <template #left>
      <div class="left">
        <va-icon-menu-collapsed
          :class="{ 'x-flip': isSidebarMinimized }"
          class="va-navbar__item"
          :color="colors.primary"
          @click="toggleMenu"
        />
        <vuestic-logo class="logo" />
      </div>
    </template>
    <template #center>
    <va-navbar-item class="navbar-item-slot">
      <div class="app-navbar-center">
        <span class="app-navbar-center__text mr-2"
          >Welcome to the Control Panel.</span
        >
      </div>
    </va-navbar-item>
    </template>
    <template #right>
      <va-navbar-item>
      <notifications-dropdown />
      </va-navbar-item>
      <va-navbar-item>
      <app-navbar-actions class="app-navbar__actions" :user-name="$page.props.auth.user.name" />
      </va-navbar-item>
      <va-navbar-item>
      <help-desk-modal />
      </va-navbar-item>
    </template>

  </VaNavbar>
</template>

<script>
export default {
  props: {
    isSidebarMinimized: Boolean
  },
  data () {
    return {
      // isSidebarMinimized: this.isGlobalSidebarMinimized
    }
  },
  methods: {
    toggleMenu () {
      // this.isSidebarMinimized = !this.isSidebarMinimized
      this.$emit('toggleMenu')
    }
  }
}
</script>

<style lang="scss" scoped>
  .va-navbar {
    box-shadow: var(--va-box-shadow);
    z-index: 2;

    @media screen and (max-width: 950px) {
      .left {
        width: 100%;
      }

      .app-navbar__actions {
        width: 100%;
        display: flex;
        justify-content: space-between;
      }
    }
  }

  .left {
    display: flex;
    align-items: center;

    & > * {
      margin-right: 1.5rem;
    }

    & > *:last-child {
      margin-right: 0;
    }
  }

  .x-flip {
    transform: scaleX(-100%);
  }

  .app-navbar-center {
    display: flex;
    align-items: center;

    @media screen and (max-width: 1200px) {
      &__github-button {
        display: none;
      }
    }

    @media screen and (max-width: 950px) {
      &__text {
        display: none;
      }
    }
  }
</style>
