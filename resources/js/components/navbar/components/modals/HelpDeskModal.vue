<script setup>
import axios from 'axios'
import { defineAsyncComponent } from 'vue'
import { useToast } from 'vuestic-ui'

const HelpDeskTinymceEditor = defineAsyncComponent(() =>
  import('./HelpDeskTinymceEditor.vue')
)
</script>
<template>
  <div>
    <va-icon
      name="fa-question"
      :title="$t('navbar.helpDesk')"
      class="ml-3 mr-3 clickable-icon"
      @click="showHelpDesk = !showHelpDesk"
      />
    <va-modal v-model="showHelpDesk" no-outside-dismiss no-padding size="small">
      <template #content="{ ok }">
        <form @submit.prevent="submitTicket">
          <va-card-title>{{ $t('navbar.helpDesk') }}</va-card-title>
          <va-card-content>
            <va-alert v-if="httpError"
              color="danger"
              icon="warning"
              class="mb-4"
            >
              {{ $t('navbar.helpDeskError') }}
            </va-alert>
            <p class="va-p mb-3">{{ $t('navbar.helpDeskDescription') }} <a :href="$page.props.documentation" target="blank">documentation</a></p>
            <va-input v-model="form.subject"
              :label="$t('navbar.subject')"
              type="text"
              class="mb-3"
              :messages="$t('navbar.subjectMessage')"
              :error="$page.props.errors.subject"
              :error-messages="$page.props.errors.subject"
              required-mark
              maxlength="100"
              />
            <div class="va-input-label text-align-left text-color-primary">
              {{ $t('navbar.helpDeskBody') }}
            </div>
            <HelpDeskTinymceEditor
              v-if="showHelpDesk"
              v-model="form.body"
              />
            <div class="va-message-list__message">
              {{ $t('navbar.helpDeskBodyMessage') }}
            </div>
            <va-select
              v-model="form.request"
              :options="requests"
              :label="$t('navbar.typeOfRequest')"
              :error="$page.props.errors.request"
              :error-messages="$page.props.errors.request"
              class="mt-3"
              value-by="value"
              text-by="text"
              auto-select-first-option
              required-mark
              />
          </va-card-content>
          <va-card-actions align="right">
            <va-button color="textInverted" @click="ok">
              {{ $t('common.cancel') }}
            </va-button>
            <va-button type="submit" :disabled="processing" class="mr-2 mb-2">{{ $t('common.submit') }}</va-button>
          </va-card-actions>
        </form>
      </template>
    </va-modal>
  </div>
</template>
<script>
export default {
  data () {
    return {
      showHelpDesk: false,
      form: {
        subject: '',
        body: '',
        request: 'question'
      },
      errors: {
        subject: {},
        body: '',
        request: {}
      },
      processing: false,
      httpError: false,
      toast: useToast(),
      requests: [
        {
          text: this.$t('navbar.question'),
          value: 'question'
        },
        {
          text: this.$t('navbar.reportBug'),
          value: 'bug'
        },
        {
          text: this.$t('navbar.featureRequest'),
          value: 'feature'
        }
      ]
    }
  },
  methods: {
    submitTicket () {
      const vueState = this
      this.processing = true
      axios.post('/support/ticket/submit', this.form, {
        headers: {
          'Content-Type': 'application/json'
        },
        data: {}
      })
        .then(() => {
          vueState.processing = false
          vueState.form = {
            subject: '',
            body: '',
            request: 'question'
          }
          vueState.showHelpDesk = false
          vueState.toast.init({
            message: this.$t('navbar.ticketSubmitted'),
            customClass: 'success-toast',
            color: 'success'
          })
        })
        .catch((error) => {
          const response = error.response
          if (response.status === 422) {
            vueState.errors = response.data.errors
          } else {
            vueState.httpError = true
          }
          this.processing = false

          vueState.toast.init({
            message: this.$t('navbar.ticketError'),
            customClass: 'success-toast',
            color: 'danger'
          })
        })
    }
  }
}
</script>
<style lang="scss">
  .success-toast {
    margin-top: 100px
  }

  .clickable-icon {
    transition: 0.3s;

    &:hover {
      opacity: 0.25;
      cursor: pointer;
      color: var(--va-primary);
    }
  }
</style>
