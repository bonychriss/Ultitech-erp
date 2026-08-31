import { useRef, useEffect, useState, useId } from 'react'
import { loadTinyMce, getTinyMceBase, destroyTinyMceEditor } from '../lib/loadTinyMce.js'
import { registerTinyMceTableIcons } from '../lib/tinymceTableIcons.js'
import { registerTableRowColumnColor } from '../lib/tinymceTableRowColColor.js'
import { preventEditorScrollJump } from '../lib/tinymceScrollFix.js'
import { prepareHtmlForEditor, loadHtmlIntoEditor } from '../lib/prepareHtmlForEditor.js'

const EDITOR_PAGE_HEIGHT = 1056

const CONTENT_STYLE = `
  @import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');
  html { background: #fff; }
  body {
    font-family: 'DM Sans', sans-serif;
    font-size: 11pt;
    line-height: 1.5;
    color: #000;
    max-width: 816px;
    margin: 0 auto;
    padding: 72px 96px 96px;
    min-height: ${EDITOR_PAGE_HEIGHT - 120}px;
    outline: none;
  }
  body:focus { outline: none; }
  h1 { font-size: 22pt; text-align: center; margin-bottom: 12pt; font-weight: 700; }
  h2 { font-size: 13pt; color: #1a1a2e; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; border: none !important; border-bottom: none !important; padding-bottom: 0; margin-top: 24pt; margin-bottom: 10pt; }
  h3 { font-size: 11pt; color: #333; text-transform: uppercase; font-weight: 700; border: none !important; border-bottom: none !important; padding-bottom: 0; }
  h4 { border: none !important; border-bottom: none !important; padding-bottom: 0; }
  p { margin: 0 0 10pt; }
  table { border-collapse: collapse; width: 100%; margin: 12pt 0; font-size: 10pt; }
  td, th { border: 1px solid #bbb; padding: 6px 8px; vertical-align: top; }
  th { background: #1a1a2e; color: #fff; font-weight: 600; }
  ul { margin: 8pt 0 12pt 18pt; }
  li { margin-bottom: 6pt; }
  .sr-erp-block { background: transparent; border: none; border-radius: 0; padding: 0; margin: 0; }
  .sr-data-table { width: 100%; }
  .sr-section { display: block; margin: 0; padding: 0; border: none; }
  .sr-cover-page { text-align: center; min-height: 640px; padding: 72px 32px 96px; position: relative; }
  .sr-company-logo { margin: 0 auto 28px; text-align: center; }
  .sr-company-logo--top-right { position: absolute; top: 0; right: 0; margin: 0; text-align: right; }
  .sr-company-logo img { max-height: 72px; max-width: 220px; height: auto; width: auto; display: inline-block; }
  .sr-rep-appendix { page-break-before: always; margin-top: 24px; }
`

export default function WordDocument({ initialContent, onChange, onInit, readOnly }) {
  const hostRef = useRef(null)
  const editorRef = useRef(null)
  const onChangeRef = useRef(onChange)
  const onInitRef = useRef(onInit)
  const preparedRef = useRef(prepareHtmlForEditor(initialContent))
  preparedRef.current = prepareHtmlForEditor(initialContent)

  onChangeRef.current = onChange
  onInitRef.current = onInit

  const reactId = useId()
  const editorId = `sr-word-editor-${reactId.replace(/:/g, '')}`

  const [initError, setInitError] = useState(null)
  const [editorReady, setEditorReady] = useState(false)
  const readOnlyRef = useRef(readOnly)
  readOnlyRef.current = readOnly

  useEffect(() => {
    const host = hostRef.current
    if (!host) return undefined

    let cancelled = false
    let localEditor = null
    let initPromise = null

    const textarea = document.createElement('textarea')
    textarea.id = editorId
    textarea.setAttribute('aria-label', 'Sales report document')
    host.appendChild(textarea)

    initPromise = loadTinyMce()
      .then((tinymce) => {
        if (cancelled) return null

        return tinymce.init({
          target: textarea,
          base_url: getTinyMceBase(),
          suffix: '.min',
          height: EDITOR_PAGE_HEIGHT,
          min_height: EDITOR_PAGE_HEIGHT,
          menubar: false,
          toolbar: false,
          statusbar: false,
          branding: false,
          promotion: false,
          license_key: 'gpl',
          highlight_on_focus: false,
          verify_html: false,
          readonly: Boolean(readOnlyRef.current),
          extended_valid_elements: 'div[class|style|id|contenteditable|data-*],section[class|style|id|data-*],span[class|style],h1,h2,h3,p[class|style],table[class|style],thead,tbody,tr,td[colspan|rowspan|class|style],th[colspan|rowspan|class|style],ul,ol,li,img[src|alt|width|height|style],a[href|target|class|style],br,strong,em,u',
          plugins: [
            'lists', 'link', 'table', 'image', 'pagebreak',
            'searchreplace', 'wordcount', 'charmap',
          ],
          content_style: CONTENT_STYLE,
          table_toolbar: 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol | tablerowbackgroundcolor tablecolbackgroundcolor tablecellbackgroundcolor',
          table_appearance_options: true,
          table_advtab: true,
          table_resize_bars: true,
          table_cell_advtab: true,
          resize: false,
          paste_data_images: true,
          image_advtab: true,
          link_default_target: '_blank',
          setup: (editor) => {
            registerTinyMceTableIcons(editor)
            registerTableRowColumnColor(editor)
            editor.on('change input undo redo SetContent', () => {
              onChangeRef.current?.(editor.getContent())
            })
          },
          init_instance_callback: (editor) => {
            if (cancelled) return
            localEditor = editor
            editorRef.current = editor
            preventEditorScrollJump(editor)
            loadHtmlIntoEditor(editor, preparedRef.current)
            if (typeof editor.mode?.set === 'function') {
              editor.mode.set(readOnlyRef.current ? 'readonly' : 'design')
            }
            setEditorReady(true)
            onInitRef.current?.(editor)
          },
        })
      })
      .catch((err) => {
        if (!cancelled) {
          setInitError(err?.message || 'TinyMCE failed to initialize')
        }
      })

    return () => {
      cancelled = true
      setEditorReady(false)
      destroyTinyMceEditor(localEditor)
      if (editorRef.current === localEditor) {
        editorRef.current = null
      }
      localEditor = null
      initPromise?.then((editors) => {
        if (Array.isArray(editors)) {
          editors.forEach((ed) => destroyTinyMceEditor(ed))
        }
      }).catch(() => {})
      try {
        host.replaceChildren()
      } catch {
        host.innerHTML = ''
      }
    }
  }, [editorId])

  if (initError) {
    return (
      <div className="word-doc-page word-doc-error">
        <p>{initError}</p>
      </div>
    )
  }

  return (
    <div className="word-doc-page">
      <div className={`word-doc-loading${editorReady ? ' is-hidden' : ''}`} aria-hidden={editorReady}>
        <div className="word-spinner" />
        <p>Preparing document editor...</p>
      </div>
      <div ref={hostRef} className="word-doc-editor-host" />
    </div>
  )
}
