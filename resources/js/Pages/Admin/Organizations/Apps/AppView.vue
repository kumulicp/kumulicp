<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import AppsLayout from './AppsLayout.vue'

</script>
<template>
  <Head>
    <title>{{ $t('admin.apps.pageTitleShort') }} - Control Panel</title>
  </Head>
  <va-list>
    <va-list-item class="py-3">
      <va-list-item-section label>
        <va-list-item-label>
          <h5>{{ $t('admin.apps.apiPassword') }}</h5>
        </va-list-item-label>
      </va-list-item-section>
      <va-list-item-section>
        <va-list-item-label>
          <va-input
            :model-value="app.password"
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
          <h5>{{ $t('admin.versions.version') }}</h5>
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
          <h5>{{ $t('admin.plans.plan') }}</h5>
        </va-list-item-label>
      </va-list-item-section>
      <va-list-item-section>
        <va-list-item-label>
        {{ app.plan.name }}
        </va-list-item-label>
      </va-list-item-section>
    </va-list-item>
    <va-list-separator class="my-1" fit />

    <template v-if="pod_security">
      <va-list-separator class="my-1" fit />
      <va-list-item class="py-3">
        <va-list-item-section label>
          <va-list-item-label>
            <h5>{{ $t('admin.apps.podSecurityWarnings') }}</h5>
          </va-list-item-label>
        </va-list-item-section>
        <va-list-item-section>
          <va-list-item-label>
            <va-alert v-if="!pod_security.warnings || pod_security.warnings.length === 0" color="success" outline>
              {{ $t('admin.apps.podSecurityNone', { date: collectedAt }) }}
            </va-alert>
            <template v-else>
              <p class="mb-2">{{ $t('admin.apps.podSecurityWarningsDescription', { date: collectedAt }) }}</p>
              <va-alert
                v-for="(warning, index) in pod_security.warnings"
                :key="index"
                color="warning"
                outline
                class="mb-2"
              >
                <strong>{{ warning.profile }}</strong>
                <ul class="pod-security-violations">
                  <li v-for="(violation, vIndex) in warning.violations" :key="vIndex">{{ violation }}</li>
                </ul>
              </va-alert>
            </template>
          </va-list-item-label>
        </va-list-item-section>
      </va-list-item>
    </template>
    <va-list-separator class="my-1" fit />

    <va-list-item v-for="server in servers" :key="server.type" class="py-3">
      <va-list-item-section label>
        <va-list-item-label>
          <h5>{{ $t('admin.servers.' + server.type) }}</h5>
        </va-list-item-label>
      </va-list-item-section>
      <va-list-item-section>
        <va-list-item-label>
          <Link :href="'/admin/server/servers/' + server.id">{{ server.name }}</Link>
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
    versions: Object,
    servers: Array,
    pod_security: {
      type: Object,
      default: null
    }
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
  },
  computed: {
    collectedAt () {
      return this.pod_security?.collected_at ? new Date(this.pod_security.collected_at).toLocaleString() : ''
    }
  }
}
</script>

<style lang="scss">
.pod-security-violations {
  margin: 0.25rem 0 0 1.25rem;
  list-style: disc;
}

.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
