<script setup lang="ts">
// The domain a shared app is reached at: one of the organization's, the
// default one, or a new subdomain of one of its domains.
const domain = defineModel<number | string>('domain')
const subdomain = defineModel<string>('subdomain')
const parentDomain = defineModel<number | null>('parentDomain')

defineProps<{
  domains: Array<{ id: number | string, name: string }>
  parentDomains: Array<{ id: number, name: string }>
}>()
</script>

<template>
  <va-select
    v-model="domain"
    :label="$t('admin.sharedApps.domain')"
    :options="domains"
    text-by="name"
    value-by="id"
    placement="auto"
    class="mb-3"
    immediateValidation
    :error="!!$page.props.errors.domain"
    :error-messages="$page.props.errors.domain"
  />
  <template v-if="domain == 'new'">
    <va-input
      v-if="parentDomains.length > 0"
      v-model="subdomain"
      :label="$t('admin.sharedApps.subdomain')"
      immediateValidation
      :error="!!$page.props.errors.subdomain"
      :error-messages="$page.props.errors.subdomain"
      class="mb-3"
      :placeholder="$t('admin.sharedApps.subdomainPlaceholder')"
    >
      <template #append>
        <va-select
          v-model="parentDomain"
          :options="parentDomains"
          text-by="name"
          value-by="id"
          immediateValidation
          :error="!!$page.props.errors.parent_domain"
          :error-messages="$page.props.errors.parent_domain"
          :placeholder="$t('admin.sharedApps.primaryDomainPlaceholder')"
        >
          <template #prepend>
            <div class="mx-1">.</div>
          </template>
        </va-select>
      </template>
    </va-input>
    <p v-else class="text-color-danger">
      {{ $t('admin.sharedApps.noDomainsWarning') }}
    </p>
  </template>
</template>
