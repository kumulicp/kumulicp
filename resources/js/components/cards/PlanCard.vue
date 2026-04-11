<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
</script>
<template>
  <va-card :class="cardClass" :color="chooseColor()" :gradient="!select" :stripe="!select">
    <va-card-content class="full-height">
      <div class="flex flex-col xs12 h-full">
        <div class="mb-3">
          <div class="mb-3 va-text-center">
            <h5 class="va-h5 mb-1 pb-0">{{ plan.name }}</h5>
            <div color="secondary">{{ plan.description }}</div>
          </div>
          <va-divider class="my-1" />
          <h5 class="mb-1 va-h5">Prices</h5>
          <template v-if="plan.features.prices.length > 0">
            <div v-for="(feature, index) in plan.features.prices" :key="index" class="my-3">
                <span class="va-text-bold">{{ feature.name }}:</span> {{ feature.description }}
            </div>
          </template>
          <template v-else>
            <div class="my-3">
                Free!
            </div>
          </template>
          <template v-if="plan.features.features.length > 0">
            <va-divider class="my-1" />
            <h5 class="mb-1 va-h5">Features</h5>
            <template v-for="(feature, index) in plan.features.features" :key="index">
              <div class="my-3">
                  <span class="va-text-bold">{{ feature.name }}:</span> {{ feature.description }}
              </div>
            </template>
          </template>
        </div>
        <template v-if="select">
          <va-spacer class="spacer" />
          <div style="align-content: flex-end">
            <div v-if="select" class="va-text-center">
              <Link :id="'select'+plan.id" :href="plan.url"><va-button>Select</va-button></Link>
            </div>
          </div>
        </template>
        <template v-if="current">
          <va-spacer class="spacer" />
          <div style="align-content: flex-end">
            <div class="va-text-center">
              <va-button disabled>Current Plan</va-button>
            </div>
          </div>
        </template>
      </div>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  props: {
    plan: Object,
    select: {
      type: Boolean,
      default: false
    },
    current: {
      type: Boolean,
      default: false
    },
    fullHeight: {
      type: Boolean,
      default: false
    }
  },
  data () {
    let cardClass = 'mb-2'

    if (this.fullHeight) {
      cardClass = 'mb-2 full-height'
    }

    return {
      cardClass
    }
  },
  methods: {
    chooseColor () {
      if (this.select) {
        return 'background-secondary'
      } else {
        return 'backgroundElement'
      }
    }
  }
}
</script>
<style>
.full-height {
  height: 100%
}
</style>
