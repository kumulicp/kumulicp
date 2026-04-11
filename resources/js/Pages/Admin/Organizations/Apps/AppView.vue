<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { useForm } from '@inertiajs/vue3'
import AppsLayout from './AppsLayout.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Organization App - Control Panel</title>
  </Head>
  <va-list>
    <va-list-item class="py-3">
      <va-list-item-section label>
        <va-list-item-label>
          <h5>API Password</h5>
        </va-list-item-label>
      </va-list-item-section>
      <va-list-item-section>
        <va-list-item-label>
          <va-input
            v-model="app.password"
            :type="isPasswordVisible ? 'text' : 'password'"
            placeholder="#########"
            immediateValidation
            @click-append-inner="isPasswordVisible = !isPasswordVisible"
            readonly
          >
            <template #appendInner>
              <va-icon
                :name="isPasswordVisible ? 'visibility_off' : 'visibility'"
                size="small"
                color="primary"
              />
            </template>
          </va-input>
        </va-list-item-label>
      </va-list-item-section>
    </va-list-item>
    <va-list-separator class="my-1" fit />

    <va-list-item class="py-3">
      <va-list-item-section label>
        <va-list-item-label>
          <h5>Version</h5>
        </va-list-item-label>
      </va-list-item-section>
      <va-list-item-section>
        <va-list-item-label>
        {{ app.version.name }}
        </va-list-item-label>
      </va-list-item-section>
    </va-list-item>
    <va-list-separator class="my-1" fit />

    <va-list-item class="py-3">
      <va-list-item-section label>
        <va-list-item-label>
          <h5>Plan</h5>
        </va-list-item-label>
      </va-list-item-section>
      <va-list-item-section>
        <va-list-item-label>
        {{ app.plan.name }}
        </va-list-item-label>
      </va-list-item-section>
    </va-list-item>
  </va-list>
</template>

<script lang="ts">
export default {
  layout: (h, page) => {
    return h(AppLayout, () => h(AppsLayout, () => page))
  },
  props: {
    app: Object,
    organization: Object,
    versions: Object
  },
  data () {
    const settings = this.app.settings ? JSON.stringify(this.app.settings, '', 2) : '{}'

    return {
      curPageValue: 1,
      pageSize: 10,
      form: useForm({
        settings
      }),
      isPasswordVisible: false
    }
  }
}
</script>

<style lang="scss">
.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
