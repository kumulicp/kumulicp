<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <Head>
    <title>Tests - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>Tests </va-card-title>
    <va-card-content>
      <div class="row justify-center">
        <va-button @click="showAddTest = !showAddTest">Add Test</va-button>
      </div>
        <va-modal v-model="showAddTest" no-outside-dismiss no-padding>
          <template #content>
            <form @submit.prevent="form.post('/admin/server/tests')">
              <va-card-title>Add Test</va-card-title>
              <va-card-content>
                <va-input v-model="form.description"
                  required-mark
                  immediateValidation
                  label="Test Description"
                  class="mb-3"
                  messages="Explain the purpose of this test"
                  :error="$page.props.errors.description"
                  :error-messages="$page.props.errors.description" />
              </va-card-content>
              <va-card-actions align="right">
                <va-button color="textInverted" :disabled="form.processing" @click="ok">Cancel</va-button>
                <va-button type="submit" class="mr-2 mb-2" :disabled="form.processing">Submit</va-button>
              </va-card-actions>
            </form>
          </template>
        </va-modal>
        <va-scroll-container
          color="primary"
          horizontal
        >
          <table class="va-table va-table--hoverable mt-3">
            <thead>
              <tr>
                <th style="width: 90%">Test Purpose</th>
                <th>Created Date</th>
                <th>Status</th>
                <th>Remove</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="test in tests.slice((curPageValue - 1), (curPageValue - 1 + pageSize))" :key="'test' + test">
                <td>
                  <Link v-if="test.status != 'pending'" :href="'/admin/server/tests/'+test.id">{{ test.description }}</Link>
                  <Link v-else :href="'/admin/server/tests/'+test.id+'/edit'">{{ test.description }}</Link>
                </td>
                <td>
                  {{ test.created_date }}
                </td>
                <td>
                  {{ test.status }}
                </td>
                <td class="va-text-center">
                  <va-icon name="entypo-cancel" color="danger" class="clickable-icon"
                    v-if="test.status == 'pending'"
                    @click="showRemoveTestModal(test)" />
                </td>
              </tr>
            </tbody>
          </table>
        </va-scroll-container>
        <va-pagination v-if="tests.length > pageSize" class="mt-3 mb-3 justify-center" v-model="curPageValue" :total="tests.length" direction-links
          :page-size="pageSize" />
    </va-card-content>
  </va-card>
  <va-modal v-model="showRemoveTest" hide-default-actions :title="'Remove ' + showRemoveTest + '?'"
    :message="'Are you sure you want to remove '+ removeTest.description +'? This action is permanent.'">
    <template #footer="{ cancel }">
      <va-button color="backgroundSecondary" @click="cancel">
        Cancel
      </va-button>
      <va-button color="danger"
        @click="remove.delete('/admin/server/tests/' + removeTest.id); showRemoveTest = !showRemoveTest">Delete</va-button>
    </template>
  </va-modal>
</template>

<script lang="ts">
export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    tests: Object,
    errors: Object
  },
  data () {
    return {
      curPageValue: 1,
      pageSize: 10,
      showAddTest: false,
      showRemoveTest: false,
      removeTest: '',
      form: useForm({
        description: ''
      }),
      remove: useForm({})
    }
  },
  methods: {
    showRemoveTestModal (test) {
      this.removeTest = test
      this.showRemoveTest = !this.showRemoveTest
    }
  }
}
</script>

<style lang="scss">
.row-equal .flex {
  .va-card {
    height: 100%;
  }
}

.tests-list {
  .va-card {
    margin-bottom: 0 !important;

    &__title {
      display: flex;
      justify-content: space-between;
    }
  }
}

.clickable-icon {
  transition: 0.3s;

  &:hover {
    opacity: 0.25;
    cursor: pointer;
  }
}
</style>
