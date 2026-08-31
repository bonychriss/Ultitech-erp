/**
 * Sales Report Document Editor
 */
(function () {
    'use strict';

    const cfg = window.SR_CONFIG || {};
    const API = cfg.apiBase || 'api/';
    const reportId = cfg.reportId;
    let sections = cfg.sections || [];
    let editors = {};
    let autosaveTimer = null;
    let isDirty = false;
    let activeEditor = null;

    // ??? TinyMCE init ????????????????????????????????????????????????????????
    function initEditors() {
        if (typeof tinymce === 'undefined') {
            initFallbackEditors();
            return;
        }

        document.querySelectorAll('.sr-section-editor').forEach(el => {
            const sectionId = el.dataset.sectionId;
            tinymce.init({
                target: el,
                height: 300,
                menubar: false,
                plugins: 'lists link table image code pagebreak autoresize',
                toolbar: 'undo redo | bold italic underline | h2 h3 | alignleft aligncenter alignright | bullist numlist | forecolor backcolor | link table image | pagebreak | code',
                content_style: 'body { font-family: Segoe UI, Arial, sans-serif; font-size: 11pt; line-height: 1.6; } h2 { color: #4361ee; } table { border-collapse: collapse; width: 100%; } td, th { border: 1px solid #ccc; padding: 6px; }',
                table_toolbar: 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol',
                table_appearance_options: true,
                table_advtab: true,
                table_cell_advtab: true,
                table_row_advtab: true,
                branding: false,
                promotion: false,
                license_key: 'gpl',
                setup(editor) {
                    editors[sectionId] = editor;
                    editor.on('focus', () => { activeEditor = editor; });
                    editor.on('change input undo redo', () => { markDirty(); scheduleAutosave(); });
                }
            });
        });
    }

    function initFallbackEditors() {
        document.querySelectorAll('.sr-section-editor').forEach(el => {
            el.contentEditable = 'true';
            el.addEventListener('focus', () => { activeEditor = { insertContent: html => { el.innerHTML += html; markDirty(); } }; });
            el.addEventListener('input', () => { markDirty(); scheduleAutosave(); });
        });
    }

    function getEditor(sectionId) {
        return editors[sectionId] || null;
    }

    function getActiveEditor() {
        if (activeEditor) return activeEditor;
        const first = Object.values(editors)[0];
        return first || null;
    }

    // ??? Save ?????????????????????????????????????????????????????????????????
    function collectSections() {
        return sections.filter(s => s.visible !== false).map(s => {
            const ed = getEditor(s.id);
            return {
                ...s,
                content: ed ? ed.getContent() : (document.querySelector(`#sec_${s.id} .sr-section-editor`)?.innerHTML || s.content || '')
            };
        });
    }

    function buildContentHtml(secList) {
        return secList.map(s =>
            `<section class="sr-section" data-section-id="${esc(s.id)}" data-section-key="${esc(s.key || '')}">${s.content || ''}</section>`
        ).join('\n');
    }

    function esc(str) {
        return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
    }

    function markDirty() { isDirty = true; }

    function setSaveStatus(state, msg) {
        const el = document.getElementById('saveStatus');
        if (!el) return;
        el.className = 'sr-save-status ' + state;
        el.textContent = msg;
    }

    async function saveDocument(isAutosave = false) {
        setSaveStatus('saving', 'Saving...');
        const secList = collectSections();
        sections = secList;
        const fd = new FormData();
        fd.append('report_id', reportId);
        fd.append('sections', JSON.stringify(secList));
        fd.append('content_html', buildContentHtml(secList));
        if (isAutosave) fd.append('autosave', '1');

        try {
            const url = API + (isAutosave ? 'autosave.php' : 'save.php') + '?module=' + (cfg.module || 'analytics');
            const r = await fetch(url, { method: 'POST', body: fd });
            const j = await r.json();
            if (j.success) {
                isDirty = false;
                const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                setSaveStatus('saved', 'Saved ? ' + time);
            } else {
                setSaveStatus('', 'Save failed');
                console.error(j.error);
            }
        } catch (e) {
            setSaveStatus('', 'Save error');
            console.error(e);
        }
    }

    function scheduleAutosave() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(() => saveDocument(true), 8000);
    }

    // ??? Outline drag & drop ??????????????????????????????????????????????????
    function initOutlineDrag() {
        const list = document.getElementById('outlineList');
        if (!list) return;
        let dragItem = null;

        list.querySelectorAll('.sr-outline-item').forEach(item => {
            item.addEventListener('dragstart', e => { dragItem = item; item.classList.add('dragging'); });
            item.addEventListener('dragend', () => { item.classList.remove('dragging'); reorderSections(); });
        });

        list.addEventListener('dragover', e => {
            e.preventDefault();
            const after = getDragAfterElement(list, e.clientY);
            if (dragItem) {
                if (after) list.insertBefore(dragItem, after);
                else list.appendChild(dragItem);
            }
        });

        list.querySelectorAll('.sr-outline-del').forEach(btn => {
            btn.addEventListener('click', e => {
                e.stopPropagation();
                removeSection(btn.dataset.sectionId);
            });
        });
    }

    function getDragAfterElement(container, y) {
        const els = [...container.querySelectorAll('.sr-outline-item:not(.dragging)')];
        return els.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            return offset < 0 && offset > closest.offset ? { offset, element: child } : closest;
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    function reorderSections() {
        const list = document.getElementById('outlineList');
        const paper = document.getElementById('documentPaper');
        if (!list || !paper) return;

        const order = [...list.querySelectorAll('.sr-outline-item')].map(el => el.dataset.sectionId);
        order.forEach((id, idx) => {
            const sec = sections.find(s => s.id === id);
            if (sec) sec.order = idx;
            const el = document.getElementById('sec_' + id);
            if (el) paper.appendChild(el);
        });
        sections.sort((a, b) => a.order - b.order);
        markDirty();
        scheduleAutosave();
    }

    function removeSection(sectionId) {
        if (!confirm('Remove this section from the report?')) return;
        sections = sections.map(s => s.id === sectionId ? { ...s, visible: false } : s);
        document.getElementById('sec_' + sectionId)?.remove();
        document.querySelector(`.sr-outline-item[data-section-id="${sectionId}"]`)?.remove();
        markDirty();
        scheduleAutosave();
    }

    // ??? Add section ??????????????????????????????????????????????????????????
    function addSection(key) {
        const catalog = cfg.sectionCatalog || {};
        const id = key + '_' + Math.random().toString(16).slice(2, 10);
        const title = catalog[key] || key;
        const order = sections.length;
        const sec = { id, key, title, order, visible: true, content: `<h2>${title}</h2><p></p>` };
        sections.push(sec);

        const paper = document.getElementById('documentPaper');
        const sectionEl = document.createElement('section');
        sectionEl.className = 'sr-section';
        sectionEl.id = 'sec_' + id;
        sectionEl.dataset.sectionId = id;
        sectionEl.dataset.sectionKey = key;
        sectionEl.innerHTML = `<div class="sr-section-editor" data-section-id="${id}">${sec.content}</div>`;
        paper.appendChild(sectionEl);

        const li = document.createElement('li');
        li.className = 'sr-outline-item';
        li.draggable = true;
        li.dataset.sectionId = id;
        li.innerHTML = `<span class="sr-drag-handle">?</span><a href="#sec_${id}" class="sr-outline-link">${title}</a><button type="button" class="sr-outline-del" data-section-id="${id}">&times;</button>`;
        document.getElementById('outlineList')?.appendChild(li);
        initOutlineDrag();

        if (typeof tinymce !== 'undefined') {
            tinymce.init({
                target: sectionEl.querySelector('.sr-section-editor'),
                height: 300, menubar: false,
                plugins: 'lists link table image code pagebreak autoresize',
                toolbar: 'undo redo | bold italic underline | h2 h3 | alignleft aligncenter alignright | bullist numlist | forecolor backcolor | link table image | pagebreak | code',
                content_style: 'body { font-family: Segoe UI, Arial, sans-serif; font-size: 11pt; }',
                branding: false, promotion: false, license_key: 'gpl',
                setup(editor) {
                    editors[id] = editor;
                    editor.on('focus', () => { activeEditor = editor; });
                    editor.on('change input', () => { markDirty(); scheduleAutosave(); });
                }
            });
        }
        markDirty();
    }

    // ??? ERP Data Insert ??????????????????????????????????????????????????????
    async function insertErpData(source, mode) {
        const ed = getActiveEditor();
        if (!ed) { alert('Click inside a section first.'); return; }

        try {
            const url = `${API}erp-data.php?report_id=${reportId}&source=${encodeURIComponent(source)}&mode=${encodeURIComponent(mode)}&module=${cfg.module || 'analytics'}`;
            const r = await fetch(url);
            const j = await r.json();
            if (j.success && j.html) {
                ed.insertContent(j.html);
                markDirty();
                bootstrap.Modal.getInstance(document.getElementById('erpModal'))?.hide();
                setTimeout(renderCharts, 500);
            } else {
                alert(j.error || 'Failed to fetch ERP data');
            }
        } catch (e) {
            alert('Error fetching ERP data');
            console.error(e);
        }
    }

    async function refreshLiveData() {
        setSaveStatus('saving', 'Refreshing live data...');
        const secList = collectSections();
        for (const sec of secList) {
            const temp = document.createElement('div');
            temp.innerHTML = sec.content || '';
            temp.querySelectorAll('.sr-erp-block[data-erp-mode="live"]').forEach(async block => {
                const source = block.dataset.erpSource;
                if (!source) return;
                try {
                    const url = `${API}erp-data.php?report_id=${reportId}&source=${encodeURIComponent(source)}&mode=live&module=${cfg.module || 'analytics'}`;
                    const r = await fetch(url);
                    const j = await r.json();
                    if (j.success) {
                        const newBlock = document.createElement('div');
                        newBlock.innerHTML = j.html;
                        block.replaceWith(newBlock.firstElementChild || newBlock);
                    }
                } catch (e) { console.error(e); }
            });
            sec.content = temp.innerHTML;
            const ed = getEditor(sec.id);
            if (ed) ed.setContent(sec.content);
        }
        markDirty();
        await saveDocument(false);
        setTimeout(renderCharts, 500);
    }

    // ??? AI Generate ??????????????????????????????????????????????????????????
    async function runAiGenerate() {
        const section = document.getElementById('aiSection')?.value || 'executive_summary';
        const instruction = document.getElementById('aiInstruction')?.value || '';
        const btn = document.getElementById('btnAiRun');
        if (btn) { btn.disabled = true; btn.textContent = 'Generating...'; }

        try {
            const fd = new FormData();
            fd.append('report_id', reportId);
            fd.append('section', section);
            fd.append('instruction', instruction);
            const r = await fetch(`${API}ai-generate.php?module=${cfg.module || 'analytics'}`, { method: 'POST', body: fd });
            const j = await r.json();
            if (j.success && j.text) {
                const sec = sections.find(s => s.key === section && s.visible !== false);
                const ed = sec ? getEditor(sec.id) : getActiveEditor();
                if (ed) {
                    ed.insertContent(j.text);
                    markDirty();
                    bootstrap.Modal.getInstance(document.getElementById('aiModal'))?.hide();
                } else {
                    alert('Section not found in document. Add the section first.');
                }
            } else {
                alert(j.error || 'AI generation failed');
            }
        } catch (e) {
            alert('AI generation error');
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-stars"></i> Generate'; }
        }
    }

    // ??? Charts ???????????????????????????????????????????????????????????????
    function renderCharts() {
        if (typeof Chart === 'undefined') return;
        document.querySelectorAll('.sr-chart-block').forEach(block => {
            const canvas = block.querySelector('canvas');
            if (!canvas || canvas.dataset.rendered) return;
            canvas.dataset.rendered = '1';
            const labels = JSON.parse(block.dataset.labels || '[]');
            const values = JSON.parse(block.dataset.values || '[]');
            new Chart(canvas, {
                type: block.dataset.chartType || 'bar',
                data: { labels, datasets: [{ label: 'Sales', data: values, backgroundColor: '#4361ee88', borderColor: '#4361ee', borderWidth: 1 }] },
                options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });
        });
    }

    // ??? Version history ??????????????????????????????????????????????????????
    async function loadVersions() {
        const list = document.getElementById('versionsList');
        if (!list) return;
        list.innerHTML = '<p class="text-muted">Loading...</p>';
        try {
            const r = await fetch(`${API}versions.php?report_id=${reportId}&module=${cfg.module || 'analytics'}`);
            const j = await r.json();
            if (!j.success || !j.versions?.length) {
                list.innerHTML = '<p class="text-muted">No versions yet.</p>';
                return;
            }
            list.innerHTML = j.versions.map(v => `
                <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                    <div>
                        <strong>Version ${v.version_number}</strong><br>
                        <small class="text-muted">${v.created_at} � ${v.author_name || 'Unknown'}${v.change_summary ? ' � ' + v.change_summary : ''}</small>
                    </div>
                    <button class="btn btn-sm btn-outline-primary sr-restore-btn" data-version="${v.version_number}">Restore</button>
                </div>
            `).join('');
            list.querySelectorAll('.sr-restore-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    if (!confirm('Restore version ' + btn.dataset.version + '? Current content will be saved as a new version.')) return;
                    const fd = new FormData();
                    fd.append('report_id', reportId);
                    fd.append('version', btn.dataset.version);
                    const res = await fetch(`${API}restore-version.php?module=${cfg.module || 'analytics'}`, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) location.reload();
                    else alert(data.error || 'Restore failed');
                });
            });
        } catch (e) {
            list.innerHTML = '<p class="text-danger">Failed to load versions.</p>';
        }
    }

    // ??? Event bindings ???????????????????????????????????????????????????????
    function bindEvents() {
        document.getElementById('btnSave')?.addEventListener('click', () => saveDocument(false));
        document.getElementById('btnPreview')?.addEventListener('click', () => {
            saveDocument(false).then(() => window.open(`editor.php?id=${reportId}&module=${cfg.module || 'analytics'}`, '_blank'));
        });
        document.getElementById('btnInsertErp')?.addEventListener('click', () => {
            new bootstrap.Modal(document.getElementById('erpModal')).show();
        });
        document.getElementById('btnAiGenerate')?.addEventListener('click', () => {
            new bootstrap.Modal(document.getElementById('aiModal')).show();
        });
        document.getElementById('btnAiRun')?.addEventListener('click', runAiGenerate);
        document.getElementById('btnAddSection')?.addEventListener('click', () => {
            new bootstrap.Modal(document.getElementById('addSectionModal')).show();
        });
        document.getElementById('btnConfirmAddSection')?.addEventListener('click', () => {
            const key = document.getElementById('newSectionKey')?.value;
            if (key) addSection(key);
            bootstrap.Modal.getInstance(document.getElementById('addSectionModal'))?.hide();
        });
        document.getElementById('btnRefreshLive')?.addEventListener('click', refreshLiveData);
        document.getElementById('btnVersions')?.addEventListener('click', e => {
            e.preventDefault();
            loadVersions();
            new bootstrap.Modal(document.getElementById('versionsModal')).show();
        });
        document.getElementById('btnRename')?.addEventListener('click', async e => {
            e.preventDefault();
            const name = prompt('New report name:');
            if (!name) return;
            const fd = new FormData(); fd.append('id', reportId); fd.append('report_name', name);
            const r = await fetch(`${API}rename.php?module=${cfg.module || 'analytics'}`, { method: 'POST', body: fd });
            const j = await r.json();
            if (j.success) {
                document.querySelector('.sr-editor-title').textContent = name;
            }
        });
        document.getElementById('btnDuplicate')?.addEventListener('click', async e => {
            e.preventDefault();
            const fd = new FormData(); fd.append('id', reportId);
            const r = await fetch(`${API}duplicate.php?module=${cfg.module || 'analytics'}`, { method: 'POST', body: fd });
            const j = await r.json();
            if (j.success && j.id) location.href = `editor.php?id=${j.id}&module=${cfg.module || 'analytics'}`;
        });
        document.getElementById('btnDelete')?.addEventListener('click', async e => {
            e.preventDefault();
            if (!confirm('Delete this sales report? This action cannot be undone.')) return;
            const fd = new FormData(); fd.append('id', reportId);
            const r = await fetch(`${API}delete.php?module=${cfg.module || 'analytics'}`, { method: 'POST', body: fd });
            const j = await r.json();
            if (j.success) location.href = `index.php?module=${cfg.module || 'analytics'}`;
        });

        document.querySelectorAll('.sr-erp-insert').forEach(btn => {
            btn.addEventListener('click', () => {
                const mode = document.querySelector('input[name="erpMode"]:checked')?.value || 'live';
                insertErpData(btn.dataset.source, mode);
            });
        });

        window.addEventListener('beforeunload', e => {
            if (isDirty) { e.preventDefault(); e.returnValue = ''; }
        });
    }

    // ??? Init ?????????????????????????????????????????????????????????????????
    document.addEventListener('DOMContentLoaded', () => {
        initEditors();
        initOutlineDrag();
        bindEvents();
        setTimeout(renderCharts, 1000);
    });
})();
