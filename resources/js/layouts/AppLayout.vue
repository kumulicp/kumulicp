<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

import Navbar from '@/components/navbar/NavBar.vue'
import Sidebar from '@/components/sidebar/SideBar.vue'
import { useBreakpoint, useToast, useColors } from 'vuestic-ui'
import { router, usePage } from '@inertiajs/vue3'

const breakpoints = useBreakpoint()

const mobileBreakPointPX = 640
const tabletBreakPointPX = 768

const sidebarWidth = ref('16rem')
const sidebarMinimizedWidth = ref(undefined)

const isMobile = ref(false)
const isTablet = ref(false)
const isSidebarMinimized = ref(true)
const checkIsTablet = () => window.innerWidth <= tabletBreakPointPX
const checkIsMobile = () => window.innerWidth <= mobileBreakPointPX

const page = usePage()
const toast = useToast()

function toggleSidebar () {
  isSidebarMinimized.value = !isSidebarMinimized.value
}

const onResize = () => {
  isSidebarMinimized.value = checkIsTablet()

  isMobile.value = checkIsMobile()
  isTablet.value = checkIsTablet()
  sidebarMinimizedWidth.value = isMobile.value ? '0' : '4.5rem'
  sidebarWidth.value = isTablet.value ? '100%' : '16rem'
}

router.on('success', () => {
  if (page.props.notices.success) {
    toast.init({
      message: page.props.notices.success,
      customClass: 'success-toast',
      color: 'success',
      duration: 10000
    })
  }
  if (page.props.notices.error) {
    toast.init({
      message: page.props.notices.error[1],
      customClass: 'success-toast',
      color: 'danger',
      duration: 10000
    })
  }
})
router.on('error', () => {
  if (page.props.notices.error) {
    toast.init({
      message: page.props.notices.error[1],
      customClass: 'success-toast',
      color: 'danger',
      duration: 10000
    })
  }
})
router.on('error', () => {
  if (page.props.errors) {
    const errors = page.props.errors
    for (const message of Object.entries(errors)) {
      toast.init({
        message: message[1],
        customClass: 'success-toast',
        color: 'danger',
        duration: 10000
      })
    }
  }
})

onMounted(() => {
  window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', onResize)
})

onResize()
</script>
<template>
  <VaLayout
      :top="{ fixed: true, order: 3 }"
      :left="{ fixed: true, absolute: breakpoints.smDown, order: 2, overlay: breakpoints.smDown && !isSidebarMinimized }"
      :bottom="{ fixed: true, order: 4 }"
      @left-overlay-click="isSidebarMinimized = true"
    >
    <template #top>
    <navbar :isSidebarMinimized="isSidebarMinimized"
      @toggle-menu="toggleSidebar" />
    </template>
    <template #left>
       <sidebar
          :width="sidebarWidth"
          :minimized="isSidebarMinimized"
          :minimized-width="sidebarMinimizedWidth"
          animated
          :isTablet="isTablet"
          @toggle-menu="toggleSidebar"
        />
    </template>
    <template #content>
      <div class="layout fluid va-gutter-5">
        <va-alert
          color="warning"
          border="top"
          class="mb-3"
          v-if="$page.props.auth.status === 'deactivating'"
        >
          <template #icon>
            <va-icon name="info" />
          </template>
          {{ $t('messages.status.deactivating') }}
        </va-alert>
        <va-alert
          color="danger"
          border="top"
          class="mb-3"
          v-if="$page.props.auth.status === 'deactivated'"
        >
          <template #icon>
            <va-icon color="danger" name="info" />
          </template>
          {{ $t('messages.status.deactivated') }}
        </va-alert>
        <div class="row justify-center" v-if="$page.props.step < 3">
          <div class="flex flex-col md12 lg8">
            <div class="item">
              <va-stepper
                v-model="step"
                controlsHidden
                :steps="steps"
                @update:modelValue="goToPage"
              />
            </div>
          </div>
        </div>
        <va-breadcrumbs class="mb-3" v-if="$page.props.breadcrumbs"
            active-color="secondary"
            color="primary">
          <va-breadcrumbs-item v-for="(breadcrumb, index) in $page.props.breadcrumbs" :label="breadcrumb.label"
            :href="breadcrumb.url"
            :key="index"
            />
        </va-breadcrumbs>
        <slot></slot>
      </div>
    </template>
    <template #bottom>
      <VaDivider style="margin: 0" />
      <VaNavbar>
        <template #left>
          {{ $page.props.auth.organization.name }}
        </template>
        <template #center>
          Kumuli Control Panel
        </template>
      </VaNavbar>
    </template>
  </VaLayout>
</template>
<script lang="ts">
export default {
  mounted () {
    const { setColors } = useColors()

    setColors({
      primary: this.$page.props.theme.primary_color,
      secondary: this.$page.props.theme.secondary_color
    })
  },
  computed: {
    step () {
      return this.$page.props.step
    },
    steps () {
      return [
        {
          label: 'Choose your Plan',
          disabled: (this.$page.props.step < 0)
        },
        {
          label: 'Activate an App',
          disabled: (this.$page.props.step < 1)
        },
        {
          label: 'Add Users',
          disabled: (this.$page.props.step < 2)
        }
      ]
    }
  },
  methods: {
    goToPage (step) {
      if (step === 0) {
        router.visit('/subscription/options')
      } else if (step === 1) {
        router.visit('/discover')
      } else if (step === 2) {
        router.visit('/users')
      }
    }
  }
}
</script>

<style lang="scss">
  @use '@/scss/main.scss';
  $mobileBreakPointPX: 640px;
  $tabletBreakPointPX: 768px;

  .success-toast {
    margin-top: 100px
  }

  .va-layout__area--content {
    min-height: 100vh
  }
</style>
