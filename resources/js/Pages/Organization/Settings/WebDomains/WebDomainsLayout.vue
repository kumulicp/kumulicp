<script setup lang="ts">

</script>
<template>
  <div class="web-domains-main">
    <div class="row">
      <div class="flex xs12 lg12">
        <va-card class="mb-4">
          <va-card-title>{{ $t('organization.webDomains.webDomains') }}</va-card-title>
          <va-card-content>
            <slot />
          </va-card-content>
        </va-card>
      </div>
    </div>
  </div>
</template>

<style lang="scss">
  .row-equal .flex {
    .va-card {
      height: 100%;
    }
  }
</style>
