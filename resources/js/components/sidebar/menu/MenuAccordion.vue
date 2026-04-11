<script setup>
import { Link } from '@inertiajs/vue3'
</script>

<template>
  <template v-for="(route, idx) in items"
      :key="idx">
    <template v-if="!route.submenu">
      <va-sidebar-item v-if="route.external" :href="route.url">
        <va-sidebar-item-content>
          <va-icon :name="route.icon" />

          <va-sidebar-item-title>
            {{ route.name }}
          </va-sidebar-item-title>
        </va-sidebar-item-content>
      </va-sidebar-item>
      <va-sidebar-item v-else :active="(route.url !== '/' && pathname.startsWith(route.url) || pathname === route.url)">
        <Link class="va-sidebar__item va-sidebar-item" :href="route.url" @click="updatePath(route)">
            <va-sidebar-item-content>
              <va-icon :name="route.icon" />

              <va-sidebar-item-title>
                {{ route.name }}
              </va-sidebar-item-title>
            </va-sidebar-item-content>
        </Link>
      </va-sidebar-item>
    </template>
    <va-accordion v-else v-model="accordionValue[idx]">
      <va-collapse
        :class="{ expanded: accordionValue[idx] && route.submenu }"
        >
        <template #header="{ value: isCollapsed }">
          <va-sidebar-item>
            <va-sidebar-item-content>
              <va-icon :name="route.icon" />

              <va-sidebar-item-title>
                {{ route.name }}
              </va-sidebar-item-title>

              <va-icon v-if="route.submenu" :name="isCollapsed ? 'expand_less' : 'expand_more'" />
            </va-sidebar-item-content>
          </va-sidebar-item>
        </template>
        <template #body>
          <template v-for="(child, index) in route.submenu" :key="index">
            <va-sidebar-item v-if="child.external" :href="child.url">
              <va-sidebar-item-content>
                <div class="va-sidebar-item__icon" />
                <va-sidebar-item-title>
                  {{ child.name }}
                </va-sidebar-item-title>
              </va-sidebar-item-content>
            </va-sidebar-item>
            <va-sidebar-item v-else :active="pathname.startsWith(child.url)">
              <a v-if="route.external" class="va-sidebar__item va-sidebar-item" :href="child.url">
                <va-sidebar-item-content>
                  <div class="va-sidebar-item__icon" />

                  <va-sidebar-item-title>
                    {{ child.name }}
                  </va-sidebar-item-title>
                </va-sidebar-item-content>
              </a>
              <Link v-else class="va-sidebar__item va-sidebar-item" :href="child.url" @click="updatePath(child)">
                <va-sidebar-item-content>
                  <div class="va-sidebar-item__icon" />

                  <va-sidebar-item-title>
                    {{ child.name }}
                  </va-sidebar-item-title>
                </va-sidebar-item-content>
              </Link>
            </va-sidebar-item>
          </template>
        </template>
      </va-collapse>
    </va-accordion>
  </template>
</template>

<script>
export default {
  props: {
    items: Object
  },
  data () {
    return {
      accordionValue: [],
      pathname: (new URL(window.location.href)).pathname
    }
  },
  mounted () {
    this.accordionValue = this.items.map((item) => [this.isItemExpanded(item)])
  },
  computed: {
    menuItems () {
      return this.$page.props.items
    }
  },
  methods: {
    isRouteActive (item) {
      return this.pathname.startsWith(item.url)
    },
    isItemExpanded (item) {
      if (!item.submenu) {
        return false
      }

      const isCurrentItemActive = this.isRouteActive(item)
      const isChildActive = !!item.submenu.find((child) =>
        this.isRouteActive(child)
      )

      return isCurrentItemActive || isChildActive
    },
    updatePath (item) {
      this.pathname = item.url
      this.$emit('toggleMenu')
    }
  }
}
</script>
