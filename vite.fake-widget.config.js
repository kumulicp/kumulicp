import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
  plugins: [vue()],
  define: {
    'process.env.NODE_ENV': '"production"'
  },
  publicDir: false,
  build: {
    outDir: 'public/widgets',
    emptyOutDir: false,

    lib: {
      entry: path.resolve(
        __dirname,
        'resources/js/widgets/FakePaymentMethod.entry.js'
      ),
      formats: ['es'],
      fileName: () => 'FakePaymentMethod.js',
      cssFileName: 'FakePaymentMethod'
    }
  }
})
