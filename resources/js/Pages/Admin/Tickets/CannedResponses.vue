<script setup>
import AppLayout from '@/layouts/AppLayout.vue'
import TicketEditor from '@/components/tickets/TicketEditor.vue'
</script>
<template>
  <Head>
    <title>{{ $t('tickets.canned.title') }} - Control Panel</title>
  </Head>
  <va-card class="mb-4">
    <va-card-title>
      <div class="flex justify-between items-center w-full">
        <span>{{ $t('tickets.canned.title') }}</span>
        <va-button id="newCanned" @click="openNew">{{ $t('tickets.canned.new') }}</va-button>
      </div>
    </va-card-title>
    <va-card-content>
      <p class="va-p mb-2">{{ $t('tickets.canned.intro') }}</p>
      <p class="mb-3">
        <code v-for="variable in variables" :key="variable" class="canned-variable">&#123;&#123;{{ variable }}&#125;&#125;</code>
      </p>

      <div v-if="!responses.length">{{ $t('tickets.canned.none') }}</div>
      <va-card v-for="response in responses" :key="response.id" class="mb-3" outlined>
        <va-card-title>
          <div class="flex justify-between items-center w-full">
            <span>{{ response.title }}</span>
            <va-chip size="small" :color="response.scope === 'shared' ? 'primary' : 'secondary'">{{ $t(`tickets.canned.scopes.${response.scope}`) }}</va-chip>
          </div>
        </va-card-title>
        <va-card-content>
          <div class="canned-preview" v-html="response.body_html"></div>
          <div v-if="response.can_change" class="mt-2">
            <va-button size="small" color="secondary" @click="openEdit(response)">{{ $t('common.edit') }}</va-button>
            <va-button size="small" preset="plain" color="danger" class="ml-2" @click="confirmDelete(response)">{{ $t('common.delete') }}</va-button>
          </div>
        </va-card-content>
      </va-card>
    </va-card-content>
  </va-card>

  <va-modal v-model="showForm" hide-default-actions no-padding size="large">
    <template #content>
      <va-card-title>{{ editing ? $t('tickets.canned.edit') : $t('tickets.canned.new') }}</va-card-title>
      <form @submit.prevent="save">
        <va-card-content>
          <va-input v-model="form.title" class="mb-3" :label="$t('tickets.canned.titleLabel')" maxlength="120" :error="!!form.errors.title" :error-messages="form.errors.title" />
          <va-select v-model="form.scope" class="mb-3" :label="$t('tickets.canned.scope')" :options="scopeOptions" value-by="value" text-by="text" />
          <TicketEditor v-if="showForm" v-model="form.body" :images="false" :error="form.errors.body" />
        </va-card-content>
        <va-card-actions align="right">
          <va-button color="textInverted" @click="showForm = false">{{ $t('common.cancel') }}</va-button>
          <va-button type="submit" :disabled="form.processing">{{ $t('common.save') }}</va-button>
        </va-card-actions>
      </form>
    </template>
  </va-modal>

  <va-modal v-model="showDelete" hide-default-actions :title="$t('tickets.canned.deleteTitle')">
    <template #footer="{ cancel }">
      <va-button color="backgroundSecondary" @click="cancel">{{ $t('common.cancel') }}</va-button>
      <va-button color="danger" @click="destroy">{{ $t('common.delete') }}</va-button>
    </template>
  </va-modal>
</template>
<script>
import { router, useForm } from '@inertiajs/vue3'

export default {
  layout: (h, page) => h(AppLayout, [page]),
  props: {
    responses: Array,
    variables: Array,
  },
  data () {
    return {
      showForm: false,
      showDelete: false,
      editing: null,
      deleting: null,
      form: useForm({ title: '', body: '', scope: 'shared' }),
    }
  },
  computed: {
    scopeOptions () {
      return ['shared', 'personal'].map(value => ({ value, text: this.$t(`tickets.canned.scopes.${value}`) }))
    },
  },
  methods: {
    openNew () {
      this.editing = null
      this.form.reset()
      this.form.clearErrors()
      this.showForm = true
    },
    openEdit (response) {
      this.editing = response
      this.form.title = response.title
      this.form.body = response.body_html
      this.form.scope = response.scope
      this.form.clearErrors()
      this.showForm = true
    },
    save () {
      const options = { preserveScroll: true, onSuccess: () => { this.showForm = false } }
      if (this.editing) {
        this.form.put(`/admin/support/canned/${this.editing.id}`, options)
      } else {
        this.form.post('/admin/support/canned', options)
      }
    },
    confirmDelete (response) {
      this.deleting = response
      this.showDelete = true
    },
    destroy () {
      router.delete(`/admin/support/canned/${this.deleting.id}`, { preserveScroll: true, onSuccess: () => { this.showDelete = false } })
    },
  },
}
</script>
<style lang="scss">
  .canned-variable {
    display: inline-block;
    margin: 0 0.4em 0.3em 0;
    padding: 0 0.4em;
    background: var(--va-background-secondary);
    border-radius: 3px;
    font-size: 0.85em;
  }

  .canned-preview {
    max-height: 10em;
    overflow: auto;
  }
</style>
