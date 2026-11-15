<script setup>
import { router, usePage } from '@inertiajs/vue3'
import { useToast } from 'vuestic-ui'
import { errorToasts, noticeToasts, showToasts } from '@/services/toasts'

const page = usePage()
const toast = useToast()

router.on('success', () => {
  showToasts(toast, noticeToasts({ success: page.props.notices?.success, error: page.props.notices?.error }))
})
router.on('error', () => {
  showToasts(toast, [...noticeToasts({ error: page.props.notices?.error }), ...errorToasts(page.props.errors)])
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
