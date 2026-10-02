import { useRef, useEffect, useState, useId } from 'react'
import { loadTinyMce, getTinyMceBase, destroyTinyMceEditor } from '../lib/loadTinyMce.js'
import { registerTinyMceTableIcons } from '../lib/tinymceTableIcons.js'
import { registerTableRowColumnColor } from '../lib/tinymceTableRowColColor.js'
import { preventEditorScrollJump } from '../lib/tinymceScrollFix.js'
import { prepareHtmlForEditor, loadHtmlIntoEditor } from '../lib/prepareHtmlForEditor.js'
import { layoutEditorPages, scheduleEditorPages } from '../lib/layoutEditorPages.js'
import { registerLineDeletion } from '../lib/deleteDocumentLine.js'

const EDITOR_PAGE_HEIGHT = 1056

const CONTENT_STYLE = `
  @import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&display=swap');
  html, body { background: #e8e6e3 !important; }
  body {
    font-family: 'DM Sans', sans-serif;
    font-size: 11pt;
    line-height: 1.5;
    color: #000;
    max-width: none;
    margin: 0;
    padding: 0;
    min-height: ${EDITOR_PAGE_HEIGHT - 120}px;
    outline: none;
    border: 0;
    background: #e8e6e3 !important;
  }
  body:focus { outline: none; }
  h1 { font-size: 22pt; text-align: center; margin-bottom: 12pt; font-weight: 700; }
  h2 { font-size: 13pt; color: #1a1a2e; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; border: none !important; border-bottom: none !important; padding-bottom: 0; margin-top: 24pt; margin-bottom: 10pt; }
  h3 { font-size: 11pt; color: #333; text-transform: uppercase; font-weight: 700; border: none !important; border-bottom: none !important; padding-bottom: 0; }
  h4 { border: none !important; border-bottom: none !important; padding-bottom: 0; }
  p { margin: 0 0 10pt; }
  table { border-collapse: collapse; width: 100%; margin: 12pt 0; font-size: 10pt; }
  td, th { border: 1px solid #bbb; padding: 6px 8px; vertical-align: top; }
  td.sr-no-line-bottom, th.sr-no-line-bottom { border-bottom: 0 !important; }
  td.sr-no-line-top, th.sr-no-line-top { border-top: 0 !important; }
  td.sr-no-line-left, th.sr-no-line-left { border-left: 0 !important; }
  td.sr-no-line-right, th.sr-no-line-right { border-right: 0 !important; }
  body.sr-erasing, body.sr-erasing * { cursor: crosshair !important; }
  td.sr-line-mark-top, th.sr-line-mark-top { box-shadow: inset 0 4px 0 #f5c518 !important; }
  td.sr-line-mark-bottom, th.sr-line-mark-bottom { box-shadow: inset 0 -4px 0 #f5c518 !important; }
  td.sr-line-mark-left, th.sr-line-mark-left { box-shadow: inset 4px 0 0 #f5c518 !important; }
  td.sr-line-mark-right, th.sr-line-mark-right { box-shadow: inset -4px 0 0 #f5c518 !important; }
  hr.sr-line-mark { outline: 3px solid #f5c518; outline-offset: 1px; }
  td.sr-line-hot-top, th.sr-line-hot-top { box-shadow: inset 0 3px 0 #d83b01 !important; }
  td.sr-line-hot-bottom, th.sr-line-hot-bottom { box-shadow: inset 0 -3px 0 #d83b01 !important; }
  td.sr-line-hot-left, th.sr-line-hot-left { box-shadow: inset 3px 0 0 #d83b01 !important; }
  td.sr-line-hot-right, th.sr-line-hot-right { box-shadow: inset -3px 0 0 #d83b01 !important; }
  hr.sr-line-hot { outline: 2px solid #d83b01; outline-offset: 2px; }
  hr { border: 0; border-top: 1px solid #9a9a9a; height: 0; margin: 12px 0; background: transparent; cursor: pointer; }
  th { background: #1a1a2e; color: #fff; font-weight: 600; }
  ul { margin: 8pt 0 12pt 18pt; }
  li { margin-bottom: 6pt; }
  .sr-erp-block { background: transparent; border: none; border-radius: 0; padding: 0; margin: 0; }
  .sr-data-table { width: 100%; }
  .sr-section { display: block; margin: 0; padding: 0; border: none; }
  .sr-cover-page { text-align: left; min-height: 980px; padding: 0; margin: 0; background: #fff; border: 0; border-radius: 12px; overflow: hidden; box-shadow: none; page-break-after: always; }
  .sr-editor-page { display: block; background: #fff; min-height: 980px; margin: 0; padding: 72px 96px 96px; border: 0; border-radius: 12px; overflow: hidden; box-shadow: none; }
  .sr-page-gap, .sr-page-gap[contenteditable="false"] { display: block !important; clear: both; height: 36px !important; margin: 0 !important; padding: 0 !important; background: transparent !important; border: 0 !important; outline: 0 !important; box-shadow: none !important; overflow: hidden !important; line-height: 0 !important; font-size: 0 !important; color: transparent !important; user-select: none; pointer-events: none; }
  .sr-cover-page table { width: 100%; margin: 0; border: none !important; border-collapse: collapse; }
  .sr-cover-page td, .sr-cover-page th { border: none !important; }
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
    const editor = editorRef.current
    if (!editor || typeof editor.mode?.set !== 'function') return
    editor.mode.set(readOnly ? 'readonly' : 'design')
  }, [readOnly])

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
          object_resizing: 'img',
          extended_valid_elements: 'div[class|style|id|contenteditable|data-*],section[class|style|id|data-*],span[class|style],h1,h2,h3,p[class|style],table[class|style],thead,tbody,tr,td[colspan|rowspan|class|style],th[colspan|rowspan|class|style],ul,ol,li,img[src|alt|width|height|style],a[href|target|class|style],br,hr,strong,em,u',
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
            registerLineDeletion(editor)
            editor.on('GetContent', (event) => {
              if (typeof event.content !== 'string') return
              if (!event.content.includes('sr-page-gap') && !event.content.includes('sr-editor-page')) return
              const parsed = new DOMParser().parseFromString(`<div id="sr-root">${event.content}</div>`, 'text/html')
              const root = parsed.getElementById('sr-root')
              if (!root) return
              root.querySelectorAll('.sr-page-gap').forEach((node) => node.remove())
              root.querySelectorAll('.sr-editor-page').forEach((page) => {
                const parent = page.parentNode
                while (page.firstChild) parent.insertBefore(page.firstChild, page)
                page.remove()
              })
              event.content = root.innerHTML
            })
            editor.on('change input undo redo SetContent', () => {
              onChangeRef.current?.(editor.getContent())
            })
            editor.on('input undo redo SetContent', () => {
              scheduleEditorPages(editor)
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
            try {
              layoutEditorPages(editor)
            } catch (err) {
              console.error(err)
            }
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
