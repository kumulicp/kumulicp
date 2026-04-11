<script setup>
import { router, usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useToast } from 'vuestic-ui'
const { t } = useI18n()

const page = usePage()
const toast = useToast()

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
      message: page.props.notices.error,
      customClass: 'success-toast',
      color: 'danger',
      duration: 10000
    })
  }
})
router.on('error', () => {
  if (page.props.notices.error) {
    toast.init({
      message: page.props.notices.error,
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
        message,
        customClass: 'success-toast',
        color: 'danger',
        duration: 10000
      })
    }
  }
})
</script>
<template>
  <slot></slot>
</template>

<script>
export default {
  name: 'BlankLayout'
}
</script>

<style lang="scss">
</style>
