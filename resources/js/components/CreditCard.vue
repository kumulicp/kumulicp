<template>
  <div id="paymentMethodWidget"></div>
</template>

<script>
export default {
  props: {
    hasDefaultPaymentMethod: Boolean,
    driver: String
  },
  data () {
    console.log(this.driver)
    return {
      paymentMethodWidget: new Map()
    }
  },
  emits: ['update:hasDefaultPaymentMethod'],
  computed: {
    default_payment_method: {
      get () {
        return this.hasDefaultPaymentMethod
      },
      set (value) {
        this.$emit('update:hasDefaultPaymentMethod', value)
      }
    }
  },
  mounted () {
    const el = document.getElementById('paymentMethodWidget')
    this.loadPaymentMethodComponent(this.driver, el, {
      hasDefaultPaymentMethod: this.hasDefaultPaymentMethod,
      csrf_token: this.$page.props.csrf_token,
      onUpdateDefaultPaymentMethod: (value) => {
        this.default_payment_method = value
      }
    })
  },
  methods: {
    async loadPaymentMethodComponent (component, el, props = {}) {
      const module = await import('/widgets/' + component + '.js')
      const mount =
        module.mount ||
        module.default?.mount ||
        module.default

      if (typeof mount !== 'function') {
        throw new Error(`Widget "${component}" does not export a mount() function`)
      }

      const app = mount(el, props)

      this.paymentMethodWidget.set(el, app)
    }
  }
}
</script>

<style>
div.va-input-wrapper__field {
border-color: #DDE5F2 !important
}
</style>
