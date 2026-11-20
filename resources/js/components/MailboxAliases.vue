<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'

interface Domain { id: number, name: string }

const props = defineProps<{
  mailboxId: number
  aliases: string[]
  domains: Domain[]
  enabled: boolean
}>()

// Not a <form>: this sits inside the pages' own forms
const form = useForm({
  local_part: '',
  domain: props.domains[0]?.id ?? null
})

function add () {
  form.post(`/mailboxes/${props.mailboxId}/aliases`, {
    preserveScroll: true,
    onSuccess: () => form.reset('local_part')
  })
}

function remove (address: string) {
  router.delete(`/mailboxes/${props.mailboxId}/aliases/${encodeURIComponent(address)}`, { preserveScroll: true })
}
</script>

<template>
  <div id="mailboxAliases" class="mailbox-aliases mb-4">
    <h6 class="va-h6 mb-2">{{ $t('mailboxAliases.title') }}</h6>
    <va-alert v-if="!enabled" id="aliasesOff" color="warning" outline class="mb-2">{{ $t('mailboxAliases.disabled') }}</va-alert>
    <template v-else>
      <p v-if="aliases.length === 0" id="noAliases" class="va-text-secondary mb-2">{{ $t('mailboxAliases.none') }}</p>
      <ul v-else class="mb-2" id="aliasList">
        <li v-for="alias in aliases" :key="alias">
          {{ alias }}
          <a href="#" class="removeAlias ml-2" role="button" @click.prevent="remove(alias)">
            <va-icon name="entypo-cancel" color="danger" size="small" />{{ $t('mailboxAliases.remove') }}
          </a>
        </li>
      </ul>
      <div v-if="domains.length > 0" class="row">
        <div class="flex flex-col xs12 md5 mb-2">
          <va-input v-model="form.local_part"
            id="aliasName"
            :label="$t('mailboxAliases.name')"
            :error="!!$page.props.errors.local_part"
            :error-messages="$page.props.errors.local_part"
            @keyup.enter="add"
          />
        </div>
        <div class="flex flex-col xs12 md4 mb-2">
          <va-select v-model="form.domain"
            id="aliasDomain"
            :label="$t('mailboxAliases.domain')"
            :options="domains"
            value-by="id"
            text-by="name"
            :error="!!$page.props.errors.domain"
            :error-messages="$page.props.errors.domain"
          />
        </div>
        <div class="flex flex-col xs12 md3 mb-2">
          <va-button id="addAlias" :disabled="form.processing || !form.local_part" @click="add">{{ $t('mailboxAliases.add') }}</va-button>
        </div>
      </div>
    </template>
  </div>
</template>
