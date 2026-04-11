<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
<template>
  <Head>
    <title>View Test - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>View Test: {{ test.description }}</va-card-title>
      <va-card-content>
        <div class="row justify-center">
          <Link :href="'/admin/server/tests/'+test.id+'/clear'" v-if="status_values.includes(form.status)"><va-button>Clear Tests Accounts</va-button></Link>
          <Link :href="'/admin/server/tests/'+test.id+'/edit'" v-if="form.status == 'pending'"><va-button>Edit</va-button></Link>
        </div>
        <form @submit.prevent="form.put('/admin/server/tests/'+test.id)">
        <va-list>
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>Test Description</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-list-item-label>
                {{ test.description }}
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>Number of Tests</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              {{ test.test_number }}
            </va-list-item-section>
          </va-list-item>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>Base Plan</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              {{ test.base_plan.name }}
            </va-list-item-section>
          </va-list-item>
          <va-list-item v-if="test.apps.length > 0">
            <va-list-item-section label>
              <va-list-item-label>
                <h3 class="va-h3">App Settings</h3>
              </va-list-item-label>
            </va-list-item-section>
          </va-list-item>
          <template v-for="(app, index) in test.apps" :key="index">
            <template v-if="app.plan.id">
              <va-list-item class="py-3">
                <va-list-item-section label>
                  <va-list-item-label>
                    <h5 class="va-h5">{{ app.app.name }}</h5>
                  </va-list-item-label>
                </va-list-item-section>
              </va-list-item>
              <va-list-item class="py-3">
                <va-list-item-section label>
                  <va-list-item-label>
                    <h5>Plan</h5>
                  </va-list-item-label>
                </va-list-item-section>
                <va-list-item-section>
                  {{ app.plan.name }}
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
                  {{ app.version.version }}
                </va-list-item-section>
              </va-list-item>
            </template>
          </template>

          <va-list-separator class="my-1" fit />
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>Status</h5>
              </va-list-item-label>
              <va-list-item-label v-if="status_values.includes(form.status)" caption>
                Status has to be set manually. Once the test is done, come back and set the test to failed or complete
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              <va-select v-model="form.status"
                v-if="status_values.includes(form.status)"
                :options="statuses"
                value-by="value"
                text-by="text"
              />
              <div v-else>
                {{ form.status }}
              </div>
            </va-list-item-section>
          </va-list-item>
        </va-list>
        <va-button type="submit"
          :disabled="form.processing"
          class="mr-2 mb-2"
          v-if="status_values.includes(form.status)"
        >
          Update Status
        </va-button>
      </form>
    </va-card-content>
  </va-card>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    test: Object,
    errors: Object
  },
  data () {
    return {
      statuses: [
        { value: 'in_progress', text: 'In Progress' },
        { value: 'failed', text: 'Failed' },
        { value: 'succeeded', text: 'Completed Successfully ' }
      ],
      status_values: ['in_progress', 'failed', 'succeeded'],
      showRunTest: false,
      run: useForm({}),
      form: useForm({
        status: this.test.status
      })
    }
  }
}
</script>

<style></style>
