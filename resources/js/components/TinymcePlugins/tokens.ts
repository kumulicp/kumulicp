import tinymce from 'tinymce'

const TOKEN_PATTERN = /\{\{([a-z_]+\.[a-z_]+)\}\}/g

function makeChipHtml(key: string): string {
  return (
    `<span class="token-chip" data-token="${key}" contenteditable="false"` +
    ` style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;` +
    `border-radius:12px;background:var(--va-primary,#154EC1);color:#fff;` +
    `font-size:0.8em;font-family:monospace;white-space:nowrap;cursor:default;user-select:none;">` +
    `<span style="opacity:0.7;font-size:0.9em;">{ }</span>` +
    `{{${key}}}` +
    `</span>`
  )
}

tinymce.PluginManager.add('tokens', function (editor) {
  // When loading existing content: convert raw {{key}} into styled chip spans
  editor.on('BeforeSetContent', (e: { content: string; format?: string }) => {
    if (e.format !== 'raw') {
      e.content = e.content.replace(TOKEN_PATTERN, (_, key) => makeChipHtml(key))
    }
  })

  // When saving: strip chip spans back to raw {{key}} for clean storage
  editor.on('GetContent', (e: { content: string; format?: string }) => {
    if (e.format === 'raw') return
    const temp = document.createElement('div')
    temp.innerHTML = e.content
    temp.querySelectorAll('span[data-token]').forEach((span) => {
      const key = span.getAttribute('data-token')
      const text = document.createTextNode(`{{${key}}}`)
      span.parentNode?.replaceChild(text, span)
    })
    e.content = temp.innerHTML
  })

  // Command used by the Vue wrapper to insert a token programmatically
  editor.addCommand('mceInsertToken', (_ui: unknown, tokenKey: string) => {
    editor.insertContent(makeChipHtml(tokenKey))
  })

  // Toolbar button: signals the Vue wrapper to open the token picker modal
  editor.ui.registry.addButton('tokeninsert', {
    text: '{ }',
    tooltip: 'Insert Token',
    onAction: () => editor.fire('token-picker-open'),
  })
})
