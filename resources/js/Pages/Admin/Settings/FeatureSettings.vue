<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsLayout from './SettingsLayout.vue'
import { useForm } from '@inertiajs/vue3'
</script>

<template>
  <Head>
    <title>{{ $t('settings.features') }} - Control Panel</title>
  </Head>
  <form @submit.prevent="form.put('/admin/settings/features')">
    <div class="my-2">
      <h4 class="va-h4">{{ $t('settings.features') }}</h4>
      <p class="va-text-secondary mb-1">{{ $t('settings.featuresDescription') }}</p>
      <p class="va-text-secondary text-sm">{{ $t('settings.featuresEnvNote') }}</p>
    </div>

    <va-alert v-if="hasBeta" color="warning" icon="warning" outline class="my-3">
      {{ $t('settings.betaWarning') }}
    </va-alert>

    <div class="feature-list">
      <div
        v-for="feature in features"
        :key="feature.key"
        :id="`feature-row-${feature.key}`"
        class="feature-row"
      >
        <div class="feature-info">
          <div class="feature-title">
            <label :for="`feature-${feature.key}`" class="va-h6">{{ feature.label }}</label>
            <va-badge v-if="feature.beta" color="warning" :text="$t('settings.betaBadge')" />
          </div>
          <p class="va-text-secondary feature-description">{{ feature.description }}</p>
          <p
            v-if="$page.props.errors[`features.${feature.key}`]"
            class="va-text-danger text-sm mt-1"
          >
            {{ $page.props.errors[`features.${feature.key}`] }}
          </p>
        </div>
        <va-switch
          v-model="form.features[feature.key]"
          :id="`feature-${feature.key}`"
          class="feature-switch"
        />
      </div>
    </div>

    <va-button type="submit" id="submit" :disabled="form.processing" class="mr-2 my-2">
      {{ $t('common.update') }}
    </va-button>
  </form>
</template>

<script>
export default {
  layout: (h, page) => h(AppLayout, () => h(SettingsLayout, () => page)),
  props: {
    features: Array,
    errors: Object
  },
  data () {
    const states = {}
    this.features.forEach((feature) => {
      states[feature.key] = feature.enabled
    })

    return {
      form: useForm({
        features: states
      })
    }
  },
  computed: {
    hasBeta () {
      return this.features.some((feature) => feature.beta)
    }
  }
}
</script>

<style lang="scss" scoped>
.feature-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1.5rem;
  padding: 1rem 0;
}

.feature-info {
  flex: 1 1 auto;
  min-width: 0;
}

.feature-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.25rem;

  label {
    margin: 0;
  }
}

.feature-description {
  margin: 0;
}

.feature-switch {
  flex: 0 0 auto;
  width: auto;
}

.feature-row + .feature-row {
  border-top: 1px solid var(--va-background-border);
}
</style>
