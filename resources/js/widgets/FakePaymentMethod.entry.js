import { createApp } from 'vue'
import FakePaymentMethod from './FakePaymentMethod.vue'
import { createVuestic } from 'vuestic-ui'
import vuesticGlobalConfig from '../services/global-config'

export function mount (el, props = {}) {
  return createApp(FakePaymentMethod, props)
    .use(createVuestic({ config: vuesticGlobalConfig }))
    .mount(el)
}
