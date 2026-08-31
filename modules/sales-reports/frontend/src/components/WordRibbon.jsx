import InsertTablePicker from './InsertTablePicker.jsx'
import TableRibbonTools from './TableRibbonTools.jsx'
import EditorUndoRedo from './EditorUndoRedo.jsx'
import RibbonToolButton from './RibbonToolButton.jsx'

export default function WordRibbon({ editor, activeTab, onTabChange }) {
  const exec = (cmd, value) => {
    if (!editor) return
    editor.execCommand(cmd, false, value)
    editor.focus()
  }

  const tabs = ['Home', 'Insert', 'Layout']

  return (
    <div className="word-ribbon">
      <div className="word-ribbon-tabs">
        {tabs.map((tab) => (
          <button
            key={tab}
            type="button"
            className={`word-ribbon-tab ${activeTab === tab ? 'active' : ''}`}
            onClick={() => onTabChange(tab)}
          >
            {tab}
          </button>
        ))}
      </div>

      <div className="word-ribbon-panel">
        <div className="word-ribbon-group word-ribbon-group--clipboard">
          <span className="word-ribbon-label">
            <i className="bi bi-clock-history" aria-hidden="true" />
            History
          </span>
          <div className="word-ribbon-buttons word-ribbon-buttons--tools">
            <EditorUndoRedo editor={editor} variant="ribbon" showLabels={false} />
          </div>
        </div>

        {activeTab === 'Home' && (
          <>
            <div className="word-ribbon-group">
              <span className="word-ribbon-label">Font</span>
              <div className="word-ribbon-buttons">
                <select defaultValue="11pt" onChange={(e) => exec('FontSize', e.target.value.replace('pt', ''))}>
                  <option value="10pt">10</option>
                  <option value="11pt">11</option>
                  <option value="12pt">12</option>
                  <option value="14pt">14</option>
                  <option value="18pt">18</option>
                  <option value="24pt">24</option>
                </select>
                <button type="button" className="word-btn-bold" title="Bold" onClick={() => exec('Bold')}>B</button>
                <button type="button" className="word-btn-italic" title="Italic" onClick={() => exec('Italic')}>I</button>
                <button type="button" className="word-btn-underline" title="Underline" onClick={() => exec('Underline')}>U</button>
                <input type="color" title="Text color" defaultValue="#000000" onChange={(e) => exec('ForeColor', e.target.value)} />
                <input type="color" title="Highlight" defaultValue="#ffff00" onChange={(e) => exec('HiliteColor', e.target.value)} />
              </div>
            </div>
            <div className="word-ribbon-group">
              <span className="word-ribbon-label">Paragraph</span>
              <div className="word-ribbon-buttons">
                <button type="button" className="word-icon-btn" title="Align left" onClick={() => exec('JustifyLeft')}>
                  <i className="bi bi-text-left" aria-hidden="true" />
                </button>
                <button type="button" className="word-icon-btn" title="Align center" onClick={() => exec('JustifyCenter')}>
                  <i className="bi bi-text-center" aria-hidden="true" />
                </button>
                <button type="button" className="word-icon-btn" title="Align right" onClick={() => exec('JustifyRight')}>
                  <i className="bi bi-text-right" aria-hidden="true" />
                </button>
                <button type="button" className="word-icon-btn" title="Bullet list" onClick={() => exec('InsertUnorderedList')}>
                  <i className="bi bi-list-ul" aria-hidden="true" />
                </button>
                <button type="button" className="word-icon-btn" title="Numbered list" onClick={() => exec('InsertOrderedList')}>
                  <i className="bi bi-list-ol" aria-hidden="true" />
                </button>
              </div>
            </div>
            <div className="word-ribbon-group">
              <span className="word-ribbon-label">Styles</span>
              <div className="word-ribbon-buttons">
                <button type="button" onClick={() => exec('FormatBlock', 'h1')}>Title</button>
                <button type="button" onClick={() => exec('FormatBlock', 'h2')}>Heading</button>
                <button type="button" onClick={() => exec('FormatBlock', 'p')}>Normal</button>
              </div>
            </div>
            <TableRibbonTools editor={editor} />
          </>
        )}

        {activeTab === 'Insert' && (
          <div className="word-ribbon-group">
            <span className="word-ribbon-label">
              <i className="bi bi-plus-square" aria-hidden="true" />
              Insert
            </span>
            <div className="word-ribbon-buttons word-ribbon-buttons--tools">
              <InsertTablePicker editor={editor} />
              <RibbonToolButton icon="bi-image" label="Image" onClick={() => editor?.execCommand('mceImage')} />
              <RibbonToolButton icon="bi-link-45deg" label="Link" onClick={() => exec('mceInsertLink')} />
              <RibbonToolButton icon="bi-hr" label="Line" onClick={() => exec('InsertHorizontalRule')} />
              <RibbonToolButton icon="bi-file-break" label="Page Break" onClick={() => exec('mcePageBreak')} />
            </div>
          </div>
        )}

        {activeTab === 'Layout' && (
          <div className="word-ribbon-group">
            <span className="word-ribbon-label">
              <i className="bi bi-file-earmark-text" aria-hidden="true" />
              Document
            </span>
            <div className="word-ribbon-buttons word-ribbon-buttons--tools">
              <RibbonToolButton icon="bi-file-break" label="Page Break" onClick={() => exec('mcePageBreak')} />
              <RibbonToolButton icon="bi-eraser" label="Clear Formatting" onClick={() => exec('RemoveFormat')} />
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
