<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <va-modal
    v-model="show"
    :title="t('organization.webDomains.newDomain')"
    hide-default-actions
    >
    <template #default>
      <va-alert border="top" border-color="primary" >{{ t('organization.webDomains.chooseManage') }}</va-alert>
      <va-list class="mt-3">
        <va-list-item class="mt-3">
          <va-list-item-section icon>
            <Link id="register" href="/settings/domains/availability"><va-button>{{ t('organization.webDomains.select') }}</va-button></Link>
          </va-list-item-section>

          <va-list-item-section>
            <va-list-item-label label> {{ t('organization.webDomains.registerDomain') }} </va-list-item-label>
            <va-list-item-label caption lines="3">
              {{ t('organization.webDomains.registerDomainDesc') }}
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>

        <va-list-separator fit spaced />

        <va-list-item class="mt-3">
          <va-list-item-section icon>
            <Link id="transfer" href="/settings/domains/transfer"><va-button>{{ t('organization.webDomains.select') }}</va-button></Link>
          </va-list-item-section>

          <va-list-item-section>
            <va-list-item-label label> {{ t('organization.webDomains.transferDomain') }} </va-list-item-label>
            <va-list-item-label caption lines="3">
              {{ t('organization.webDomains.transferDomainDesc') }}
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>

        <va-list-separator fit spaced />

        <va-list-item class="mt-3">
          <va-list-item-section icon>
            <Link id="connect" href="/settings/domains/connect"><va-button>{{ t('organization.webDomains.select') }}</va-button></Link>
          </va-list-item-section>

          <va-list-item-section>
            <va-list-item-label label> {{ t('organization.webDomains.connectExisting') }} </va-list-item-label>
            <va-list-item-label caption lines="3">
              {{ t('organization.webDomains.connectExistingDesc') }}
            </va-list-item-label>
          </va-list-item-section>
        </va-list-item>
      </va-list>
    </template>
    <template #footer>
      <va-button
        color="textInverted"
        @click="show = !show"
        >
        {{ t('common.cancel') }}
      </va-button>
    </template>
  </va-modal>
</template>
<script lang="ts">
export default {
  props: {
    showModal: Boolean
  },
  emits: ['update:showModal'],
  computed: {
    show: {
      get () {
        return this.showModal
      },
      set (value) {
        this.$emit('update:showModal', value)
      }
    }
  }
}
</script>
