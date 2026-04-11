<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

// Everything tinymce
import tinymce from 'tinymce'

// core
import 'tinymce/themes/silver/theme'
import 'tinymce/skins/ui/oxide/skin'
import 'tinymce/skins/ui/oxide/content'
import 'tinymce/icons/default'
import 'tinymce/models/dom/model'

// core plugins
import 'tinymce/plugins/lists'
import 'tinymce/plugins/advlist'
import 'tinymce/plugins/link'
import 'tinymce/plugins/image'
import 'tinymce/plugins/table'
import 'tinymce/plugins/code'
import 'tinymce/plugins/help'
import 'tinymce/plugins/wordcount'
import 'tinymce/plugins/media'
import 'tinymce/plugins/help/js/i18n/keynav/en'

// custom plugins
import '@/components/TinymcePlugins/persistentgrid'

import Editor from '@tinymce/tinymce-vue'
</script>
<template>
  <template v-if="tinymceCssFile">
    <Editor
      v-model:model-value="content"
      id="welcomePage"
      :init="{
        license_key: 'gpl',
        plugins: 'lists advlist link image table code help wordcount media persistentgrid',
        table_class_list: [
          {title: 'Default', value: 'va-table'},
          {title: 'Hoverable', value: 'va-table va-table--hoverable'},
          {title: 'Striped', value: 'va-table va-table--striped'},
        ],
        formats: {
          h1: { block: 'h1', classes: 'va-h1' },
          h2: { block: 'h2', classes: 'va-h2' },
          h3: { block: 'h3', classes: 'va-h3' },
          h4: { block: 'h4', classes: 'va-h4' },
          h5: { block: 'h5', classes: 'va-h5' },
          h6: { block: 'h6', classes: 'va-h6' },
          blockquote: { block: 'blockquote', classes: 'va-blockquote' },
          numlist: { block: 'ol', selector: 'ol', classes: 'va-ordered'},
          bullist: { block: 'ul', selector: 'ul', classes: 'va-unordered'},
          alignleft: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li,table,img,audio,video', classes: 'va-text-left' },
          aligncenter: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li,table,img,audio,video', classes: 'va-text-center' },
          alignright: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li,table,img,audio,video', classes: 'va-text-right' },
          alignjustify: { selector: 'p,h1,h2,h3,h4,h5,h6,td,th,div,ul,ol,li,table,img,audio,video', classes: 'va-text-justify' }
        },
        toolbar: 'undo redo | revisionhistory | aidialog aishortcuts | blocks fontsizeinput | bold italic | align numlist bullist | link image | table persistentgrid math media pageembed | lineheight  outdent indent | strikethrough forecolor backcolor formatpainter removeformat | charmap emoticons checklist | code fullscreen preview | save print | pagebreak anchor codesample footnotes mergetags | addtemplate inserttemplate | addcomment showcomments | ltr rtl casechange | spellcheckdialog a11ycheck',
        promotion: false,
        contextmenu: 'persistentgrid link image table',
        content_css: tinymceCssFile,
        relative_urls: false,
        file_picker_callback : function(callback, value, meta) {

          var cmsURL = '/laravel-filemanager?editor=' + meta.fieldname;
          if (meta.filetype == 'image') {
            cmsURL = cmsURL + '&type=Images';
          } else {
            cmsURL = cmsURL + '&type=Files';
          }

          tinymce.activeEditor.windowManager.openUrl({
            url : cmsURL,
            title : 'Filemanager',
            resizable : 'yes',
            close_previous : 'no',
            onMessage: (api, message) => {
              callback(message.content);
              api.close();
            }
          });
        }
      }"
    />
  </template>
  <va-textarea v-else
    v-model="content"
    class="full-width mb-2"
    immediateValidation
    :error="$page.props.errors.description"
    :error-messages="$page.props.errors.description"
  />
</template>

<script lang="ts">
export default {
  props: {
    htmlContent: String,
    errors: Object
  },
  emits: ['update:content'],
  data () {
    this.loadAppCSS()

    return {
      tinymceCssFile: null,
      content: this.htmlContent
    }
  },
  watch: {
    content () {
      this.$emit('update:htmlContent', this.content)
    }
  },
  methods: {
    tinymce (content) {
      const x = window.innerWidth || document.documentElement.clientWidth || document.getElementsByTagName('body')[0].clientWidth
      const y = window.innerHeight || document.documentElement.clientHeight || document.getElementsByTagName('body')[0].clientHeight
      content.width = x * 0.8
      content.height = y * 0.8
      tinymce.activeEditor.windowManager.openUrl(content)
    },
    async loadAppCSS() {
      try {
        const response = await fetch('/build/manifest.json');
        if (!response.ok) throw new Error('Failed to load JSON');

        const data = await response.json();
        this.tinymceCssFile = '/build/'+data['resources/js/app.js']['css'][0]
      } catch (err) {
        console.error(err);
      }
    }
  }
}
</script>
