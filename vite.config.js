import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  server: {
    watch: {
      ignored: ['**/*.php', '**/vendor/**', '**/storage/**', '**/bootstrap/cache/**']
    }
  },
  plugins: [
    laravel([
      'resources/js/app.js',
    ]),
    tailwindcss(),
    vue({
      template: {
        transformAssetUrls: {
          base: null,
          includeAbsolute: false
        }
      }
    })
  ],
  resolve: {
    alias: {
      '@': '/resources/js'
    }
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks (id) {
          if (id.includes('node_modules/vuestic-ui')) return 'vendor-vuestic'
          if (id.includes('node_modules/tinymce') || id.includes('node_modules/@tinymce')) return 'vendor-tinymce'
          if (id.includes('node_modules/@codemirror')) return 'vendor-codemirror'
          if (
            id.includes('node_modules/vue/') ||
            id.includes('node_modules/@vue/') ||
            id.includes('node_modules/@inertiajs/') ||
            id.includes('node_modules/pinia/') ||
            id.includes('node_modules/vue-i18n/')
          ) return 'vendor-vue'
        }
      }
    }
  }
})
