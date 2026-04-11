<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import PlanCard from '@/components/cards/PlanCard.vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>Discover App - Control Panel</title>
  </Head>
  <div class="discover-app">
    <div class="row">
      <div :class="'flex xs12 '+card_size">
        <va-card class="mb-4">
          <va-card-title>{{ app.name }}</va-card-title>
          <va-card-content>
            <va-alert
              v-if="authorization.deny.message"
              color="primary"
              outline
              class="mb-4"
            >
              <template #icon>
                <va-icon name="info" color="primary" />
              </template>
              <template v-if="authorization.deny.message">
                <div style="float:left" class="pt-1 pb-1">
                  {{ authorization.deny.message }}
                </div>
                <div v-if="authorization.deny.code == 'missing_parent_app'">
                  <Link :href="'/discover/'+app.parent_app.slug">
                    <va-button
                      preset="primary"
                      class="ml-4"
                      size="small"
                    >
                      Activate {{ app.parent_app.name }} here
                    </va-button>
                  </Link>
                </div>
                <div v-else-if="authorization.deny.code == 'plan_limit_reached'">
                  <Link href="/subscription/options">
                    <va-button
                      preset="primary"
                      class="ml-4"
                      size="small"
                    >
                      Upgrade Plan Here
                    </va-button>
                  </Link>
                </div>
              </template>
            </va-alert>
            <div id="description" v-html="app.description" style="overflow-x: auto; overflow-y: hidden"></div>
            <va-divider class="mb-3"></va-divider>
            <div v-if="can.activate" class="row">
              <div class="flex flex-col xs12">
                <Link v-if="app.plan_count == 1" :href="'/discover/'+app.slug+'/plans/'+app.plan_id+'/review'"><va-button class="mb-3">Select</va-button></Link>
                <Link v-if="app.plan_count > 1" :href="'/discover/'+app.slug+'/plans'"><va-button class="mb-3">Choose Your Plan</va-button></Link>
              </div>
            </div>
          </va-card-content>
        </va-card>
      </div>
      <div v-if="can.activate && app.plan_count == 1" class="flex xs12 lg3">
        <plan-card :plan="plan" select />
      </div>
    </div>
  </div>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    organization: Object,
    app: Object,
    plan: Object,
    can: Object,
    authorization: Object
  },
  data () {
    return {}
  },
  computed: {
    card_size () {
      if (this.can.activate && this.app.plan_count === 1) {
        return 'lg9'
      }

      return 'lg12'
    }
  }
}
</script>

<style lang="scss">
  .va-table {
    width: 100%;
  }
  #description ol,
  #description li,
  #description ul {
    all: revert
  }
  #description hr {
    border-color: #DEE5F2;
    background-color: #DEE5F2;
    color: #DEE5F2;
    border-bottom: 0px
  }
</style>
