<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'

</script>
<template>
  <Head>
    <title>{{ $t('admin.tests.viewTest') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>{{ $t('admin.tests.viewTestTitle', { description: test.description }) }}</va-card-title>
      <va-card-content>
        <div class="row justify-center">
          <Link :href="'/admin/server/tests/'+test.id+'/clear'" v-if="status_values.includes(form.status)"><va-button>{{ $t('admin.tests.clearTestsAccounts') }}</va-button></Link>
          <Link :href="'/admin/server/tests/'+test.id+'/edit'" v-if="form.status == 'pending'"><va-button>{{ $t('admin.tests.edit') }}</va-button></Link>
        </div>
        <form @submit.prevent="form.put('/admin/server/tests/'+test.id)">
        <va-list>
          <va-list-item class="py-3">
            <va-list-item-section label>
              <va-list-item-label>
                <h5>{{ $t('admin.tests.testDescription') }}</h5>
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
                <h5>{{ $t('admin.tests.numberOfTests') }}</h5>
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
                <h5>{{ $t('admin.tests.basePlan') }}</h5>
              </va-list-item-label>
            </va-list-item-section>
            <va-list-item-section>
              {{ test.base_plan.name }}
            </va-list-item-section>
          </va-list-item>
          <va-list-item v-if="test.apps.length > 0">
            <va-list-item-section label>
              <va-list-item-label>
                <h3 class="va-h3">{{ $t('admin.tests.appSettings') }}</h3>
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
                    <h5>{{ $t('admin.tests.plan') }}</h5>
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
                    <h5>{{ $t('admin.tests.version') }}</h5>
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
                <h5>{{ $t('admin.tests.status') }}</h5>
              </va-list-item-label>
              <va-list-item-label v-if="status_values.includes(form.status)" caption>
                {{ $t('admin.tests.statusCaption') }}
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
          {{ $t('admin.tests.updateStatus') }}
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
        { value: 'in_progress', text: this.$t('admin.tests.inProgress') },
        { value: 'failed', text: this.$t('admin.tests.failed') },
        { value: 'succeeded', text: this.$t('admin.tests.completedSuccessfully') }
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
