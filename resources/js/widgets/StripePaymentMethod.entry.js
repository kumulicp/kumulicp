import { createApp } from 'vue'
import StripePaymentMethod from './StripePaymentMethod.vue'
import { createVuestic } from 'vuestic-ui'
import vuesticGlobalConfig from '../services/global-config'

export function mount (el, props = {}) {
  return createApp(StripePaymentMethod, props)
    .use(createVuestic({ config: vuesticGlobalConfig }))
    .mount(el)
}
