<script setup>
import axios from 'axios'
import { useToast } from 'vuestic-ui'
</script>
<template>
  <div>
    <va-icon
      name="fa-question"
      title="Ask for Help"
      class="ml-3 mr-3 clickable-icon"
      @click="showHelpDesk = !showHelpDesk"
      />
    <va-modal v-model="showHelpDesk" no-outside-dismiss no-padding size="small">
      <template #content="{ ok }">
        <form @submit.prevent="submitTicket">
          <va-card-title>Help Desk</va-card-title>
          <va-card-content>
            <va-alert v-if="httpError"
              color="danger"
              icon="warning"
              class="mb-4"
            >
              There was an error submitting your service request. Please try again shortly.
            </va-alert>
            <p class="va-p mb-3">Before submitting a support ticket, please check out our <a :href="$page.props.documentation" target="blank">documentation</a> to see if that answer is already there.</p>
            <va-input v-model="form.subject"
              label="Subject"
              type="text"
              class="mb-3"
              messages="A brief summary"
              :error="$page.props.errors.subject"
              :error-messages="$page.props.errors.subject"
              required-mark
              maxlength="100"
              />
            <va-textarea v-model="form.body"
              label="Description"
              class="mb-3"
              messages="Be as detailed as possible"
              :error="$page.props.errors.body"
              :error-messages="$page.props.errors.body"
              required-mark
              min-rows="5"
              max-rows="15"
              style="min-width: 100%"
              />
            <va-select
              v-model="form.request"
              :options="requests"
              label="Type of Request"
              :error="$page.props.errors.request"
              :error-messages="$page.props.errors.request"
              value-by="value"
              text-by="text"
              auto-select-first-option
              required-mark
              />
          </va-card-content>
          <va-card-actions align="right">
            <va-button color="textInverted" @click="ok">
              Cancel
            </va-button>
            <va-button type="submit" :disabled="processing" class="mr-2 mb-2">Submit</va-button>
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
      requests: [
        {
          text: 'Question',
          value: 'question'
        },
        {
          text: 'Report as Bug',
          value: 'bug'
        },
        {
          text: 'Feature Request',
          value: 'feauture'
        }
      ],
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
      toast: useToast()
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
            message: 'Ticket Submitted Sucessfully',
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
            message: 'An error occured while submitting your ticket! Please try again in a minute.',
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
