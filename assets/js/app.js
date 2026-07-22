'use strict';

// ── Toast ────────────────────────────────────────────────
let toastTimer;
function showToast(msg, type = 'ok') {
    const el = document.getElementById('toast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'toast' + (type === 'error' ? ' toast-error' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.className = 'toast hidden'; }, 3000);
}

// ── Confirm modal ─────────────────────────────────────────
// uiConfirm('message', { title, confirmLabel, danger }) → Promise<boolean>
function uiConfirm(message, opts = {}) {
    return new Promise(resolve => {
        const overlay = document.getElementById('confirmOverlay');
        if (!overlay) { resolve(window.confirm(message)); return; }

        const msgEl   = document.getElementById('confirmMsg');
        const titleEl = document.getElementById('confirmTitle');
        const okBtn   = document.getElementById('confirmOk');
        const cnclBtn = document.getElementById('confirmCancel');
        const iconEl  = document.getElementById('confirmIcon');

        msgEl.textContent   = message;
        titleEl.textContent = opts.title || 'ยืนยันการทำรายการ';
        okBtn.textContent   = opts.confirmLabel || 'ยืนยัน';
        const danger = !!opts.danger;
        okBtn.classList.toggle('btn-modal-danger', danger);
        okBtn.classList.toggle('btn-modal-primary', !danger);
        iconEl.classList.toggle('modal-icon-danger', danger);

        overlay.classList.remove('hidden');
        overlay.setAttribute('aria-hidden', 'false');
        void overlay.offsetWidth; // force reflow so the transition plays reliably
        overlay.classList.add('show');
        okBtn.focus();

        const cleanup = result => {
            if (document.activeElement && overlay.contains(document.activeElement)) document.activeElement.blur();
            overlay.classList.remove('show');
            overlay.setAttribute('aria-hidden', 'true');
            setTimeout(() => overlay.classList.add('hidden'), 180);
            okBtn.removeEventListener('click', onOk);
            cnclBtn.removeEventListener('click', onCancel);
            overlay.removeEventListener('mousedown', onBackdrop);
            document.removeEventListener('keydown', onKey);
            resolve(result);
        };
        const onOk       = () => cleanup(true);
        const onCancel   = () => cleanup(false);
        const onBackdrop = e => { if (e.target === overlay) cleanup(false); };
        const onKey      = e => {
            if (e.key === 'Escape') cleanup(false);
            else if (e.key === 'Enter') cleanup(true);
        };
        okBtn.addEventListener('click', onOk);
        cnclBtn.addEventListener('click', onCancel);
        overlay.addEventListener('mousedown', onBackdrop);
        document.addEventListener('keydown', onKey);
    });
}

// ── API helpers ────────────────────────────────────────────
async function apiPost(data) {
    const fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    const res = await fetch(APP_URL + '/api.php', { method: 'POST', body: fd });
    return res.json();
}

// ── Theme applied on load ─────────────────────────────────
(function () {
    const theme = document.documentElement.dataset.theme;
    if (theme === 'system') {
        // handled by CSS media query — no JS needed
    }
})();

// ── Tree section collapse ─────────────────────────────────
document.querySelectorAll('.tree-sec-header').forEach(hdr => {
    const body = document.getElementById('secBody' + hdr.dataset.sec);
    if (!body) return;
    hdr.addEventListener('click', () => {
        const collapsed = body.style.display === 'none';
        body.style.display = collapsed ? '' : 'none';
        hdr.classList.toggle('collapsed', !collapsed);
    });
});

// ── Tree search ───────────────────────────────────────────
const treeSearch = document.getElementById('treeSearch');
if (treeSearch) {
    treeSearch.addEventListener('input', function () {
        const q = this.value.toLowerCase().trim();
        document.querySelectorAll('.tree-ind').forEach(el => {
            const code  = (el.dataset.code  || '').toLowerCase();
            const title = (el.dataset.title || '').toLowerCase();
            const show  = !q || code.includes(q) || title.includes(q);
            el.style.display = show ? '' : 'none';
        });
        // Show/hide sub-headers if all inds hidden
        document.querySelectorAll('.tree-subs').forEach(subs => {
            const visible = [...subs.querySelectorAll('.tree-ind')].some(i => i.style.display !== 'none');
            subs.style.display = visible || !q ? '' : 'none';
        });
    });
}

// ── Load indicator detail ─────────────────────────────────
async function loadIndicator(id) {
    // Update active class
    document.querySelectorAll('.tree-ind').forEach(el => {
        el.classList.toggle('active', parseInt(el.dataset.id) === id);
    });
    window.selectedIndicatorId = id;

    const panel = document.getElementById('indDetail');
    if (!panel) return;

    panel.innerHTML = '<div class="loading-spinner">กำลังโหลด…</div>';

    const url = APP_URL + '/api.php?action=indicator_detail&id=' + id +
                '&school=' + SCHOOL_ID + '&year=' + encodeURIComponent(YEAR_CODE);
    try {
        const res  = await fetch(url, { headers: { Accept: 'application/json' } });
        const json = await res.json();
        if (json.ok) {
            panel.innerHTML = json.data.html;
            initEvDragDrop();
            initAssignee();
        } else {
            panel.innerHTML = '<div class="detail-empty"><div class="detail-empty-text">เกิดข้อผิดพลาด</div></div>';
        }
    } catch (e) {
        panel.innerHTML = '<div class="detail-empty"><div class="detail-empty-text">ไม่สามารถโหลดข้อมูลได้</div></div>';
    }
}

// Auto-load if indicator pre-selected
if (window.selectedIndicatorId) {
    loadIndicator(window.selectedIndicatorId);
}

// ── Update Status ─────────────────────────────────────────
function updateStatus(indId, status) {
    apiPost({ action: 'update_status', indicator_id: indId, status }).then(r => {
        if (r.ok) {
            showToast('บันทึกสถานะเรียบร้อย');
            // Reflect in tree
            const treeEl = document.querySelector('.tree-ind[data-id="' + indId + '"]');
            if (treeEl) {
                treeEl.className = treeEl.className.replace(/status-\w+/, 'status-' + status);
                const chip = treeEl.querySelector('.chip');
                if (chip) {
                    chip.className = 'chip ' + { done:'chip-done', inprogress:'chip-prog', pending:'chip-pend' }[status];
                    chip.textContent = { done:'เผยแพร่แล้ว', inprogress:'กำลังดำเนินการ', pending:'ยังไม่ดำเนินการ' }[status];
                }
                // Update status buttons in detail panel
                document.querySelectorAll('.status-btn').forEach(btn => {
                    btn.classList.toggle('active', btn.dataset.status === status);
                });
            }
        } else { showToast(r.error, 'error'); }
    });
}

// ── Evidence drag-and-drop reordering ─────────────────────
function initEvDragDrop() {
    const list = document.querySelector('.ev-list[data-ind]');
    if (!list) return;
    let dragEl = null;

    list.querySelectorAll('.ev-item').forEach(item => {
        item.addEventListener('dragstart', e => {
            dragEl = item;
            item.classList.add('ev-dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        item.addEventListener('dragend', () => {
            item.classList.remove('ev-dragging');
            dragEl = null;
            persistEvOrder(list);
        });
    });

    list.addEventListener('dragover', e => {
        e.preventDefault();
        if (!dragEl) return;
        const after = evDragAfter(list, e.clientY);
        if (after == null) list.appendChild(dragEl);
        else list.insertBefore(dragEl, after);
    });
}

function evDragAfter(list, y) {
    const items = [...list.querySelectorAll('.ev-item:not(.ev-dragging)')];
    return items.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) return { offset, element: child };
        return closest;
    }, { offset: -Infinity, element: null }).element;
}

function persistEvOrder(list) {
    const ids = [...list.querySelectorAll('.ev-item')].map(el => el.dataset.evId);
    if (!ids.length) return;
    apiPost({ action: 'reorder_evidence', indicator_id: list.dataset.ind, order: ids.join(',') })
        .then(r => { if (!r.ok) showToast(r.error, 'error'); });
}

// ── Assignee autocomplete ─────────────────────────────────
function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
        ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

// First meaningful character of a name, ignoring Thai courtesy prefixes.
function nameInitial(name) {
    const t = String(name || '').replace(/^(นาย|นางสาว|นาง)\s*/, '').trim();
    return (t || String(name || '').trim()).charAt(0);
}

// Small avatar markup: photo if pic URL given, else an initial circle.
function avatarMini(pic, name) {
    return pic
        ? '<span class="avatar avatar-xs avatar-img"><img src="' + escHtml(pic) + '" alt=""></span>'
        : '<span class="avatar avatar-xs">' + escHtml(nameInitial(name)) + '</span>';
}

function initAssignee() {
    const box = document.querySelector('.assignee-ac');
    if (!box) return;
    const input    = box.querySelector('.assignee-input');
    const clearBtn = box.querySelector('.assignee-clear');
    const menu     = box.querySelector('.assignee-menu');
    const indId    = box.dataset.ind;
    let data = { users: [], positions: [], current: { type: 'none', name: '' } };
    try { data = JSON.parse(document.querySelector('.assignee-data').textContent) || data; } catch (e) {}
    let currentDisplay = data.current ? data.current.name : input.value;

    const close = () => { menu.classList.add('hidden'); menu.innerHTML = ''; };
    const pick = (type, id, name) => {
        input.value = name;
        currentDisplay = name;
        clearBtn.style.display = type === 'none' ? 'none' : '';
        close();
        assignIndicator(indId, type, id);
    };
    const render = q => {
        q = (q || '').toLowerCase().trim();
        const posList = (q ? data.positions.filter(p => p.name.toLowerCase().indexOf(q) !== -1) : data.positions).slice(0, 20);
        const usrList = (q ? data.users.filter(u => (u.name + ' ' + u.nick + ' ' + u.pos).toLowerCase().indexOf(q) !== -1) : data.users).slice(0, 40);

        let html = '<div class="assignee-opt" data-type="none" data-id="0">— ยังไม่มอบหมาย —</div>';
        if (posList.length) {
            html += '<div class="assignee-group">ตำแหน่ง</div>';
            html += posList.map(p => '<div class="assignee-opt" data-type="position" data-id="' + p.id + '">'
                + '<span class="ao-name"><span class="ao-postag">ตำแหน่ง</span> ' + escHtml(p.name) + '</span>'
                + '<span class="ao-sub">' + p.n + ' คนในตำแหน่งนี้</span></div>').join('');
        }
        if (usrList.length) {
            html += '<div class="assignee-group">บุคคล</div>';
            html += usrList.map(u => '<div class="assignee-opt assignee-opt-user" data-type="user" data-id="' + u.id + '">'
                + avatarMini(u.pic, u.name)
                + '<span class="ao-text">'
                + '<span class="ao-name">' + escHtml(u.name) + (u.admin ? ' <span class="ao-admin">ผู้ดูแล</span>' : '') + '</span>'
                + ((u.nick || u.pos) ? '<span class="ao-sub">' + [u.nick, u.pos].filter(Boolean).map(escHtml).join(' · ') + '</span>' : '')
                + '</span></div>').join('');
        }
        if (!posList.length && !usrList.length && q) html += '<div class="assignee-empty">ไม่พบบุคคลหรือตำแหน่ง</div>';
        menu.innerHTML = html;
        menu.classList.remove('hidden');
    };

    input.addEventListener('focus', () => render(input.value));
    input.addEventListener('input', () => render(input.value));
    input.addEventListener('blur', () => setTimeout(() => { close(); input.value = currentDisplay; }, 150));
    menu.addEventListener('mousedown', e => {
        const opt = e.target.closest('.assignee-opt');
        if (!opt) return;
        e.preventDefault();
        const type = opt.dataset.type, id = parseInt(opt.dataset.id, 10);
        let name = '';
        if (type === 'user')     name = (data.users.find(u => u.id === id) || {}).name || '';
        else if (type === 'position') name = 'ตำแหน่ง: ' + ((data.positions.find(p => p.id === id) || {}).name || '');
        pick(type, id, name);
    });
    clearBtn.addEventListener('click', () => pick('none', 0, ''));
}

// ── Assign responsible user/position ──────────────────────
function assignIndicator(indId, type, id) {
    apiPost({ action: 'assign_indicator', indicator_id: indId, target_type: type, target_id: id }).then(r => {
        if (r.ok) {
            showToast(type === 'none' ? 'ยกเลิกการมอบหมายแล้ว'
                    : (type === 'position' ? 'มอบหมายให้ตำแหน่งแล้ว' : 'มอบหมายผู้รับผิดชอบแล้ว'));
            if (window.selectedIndicatorId) loadIndicator(window.selectedIndicatorId);
        } else { showToast(r.error, 'error'); }
    });
}

// ── Evidence Modal ────────────────────────────────────────
function openEvModal(indId) {
    const modal = document.getElementById('evModal');
    if (!modal) return;
    document.getElementById('evForm')?.reset();
    document.getElementById('evAction').value = 'add_evidence';
    document.getElementById('evEvId').value   = '';
    document.getElementById('evIndId').value  = indId;
    document.getElementById('evModalTitle').textContent = 'เพิ่มหลักฐาน';
    document.getElementById('evSubmitBtn').textContent  = 'บันทึกหลักฐาน';
    document.getElementById('evCurrentFile').classList.add('hidden');
    document.getElementById('evFileInput')?.setAttribute('multiple', 'multiple');
    setLinkType('url');
    modal.classList.remove('hidden');
}

// Edit an existing evidence — prefill the same modal
function openEvEdit(data) {
    const modal = document.getElementById('evModal');
    if (!modal) return;
    document.getElementById('evForm')?.reset();
    document.getElementById('evAction').value = 'edit_evidence';
    document.getElementById('evEvId').value   = data.id;
    document.getElementById('evIndId').value  = data.ind_id || '';
    document.getElementById('evName').value   = data.title || '';
    document.getElementById('evNote').value   = data.note || '';
    document.getElementById('evModalTitle').textContent = 'แก้ไขหลักฐาน';
    document.getElementById('evSubmitBtn').textContent  = 'บันทึกการแก้ไข';
    document.getElementById('evFileInput')?.removeAttribute('multiple'); // edit = single file

    const curFile = document.getElementById('evCurrentFile');
    if (data.file_path) {
        setLinkType('file');
        document.getElementById('evUrl').value = '';
        curFile.textContent = 'ไฟล์ปัจจุบัน: ' + data.file_path + ' — เลือกไฟล์ใหม่เพื่อแทนที่ (เว้นว่างเพื่อคงไฟล์เดิม)';
        curFile.classList.remove('hidden');
    } else {
        setLinkType('url');
        document.getElementById('evUrl').value = data.url || '';
        curFile.classList.add('hidden');
    }
    modal.classList.remove('hidden');
}

function closeEvModal() {
    document.getElementById('evModal')?.classList.add('hidden');
}

// Switch link-type tab both visually and check the matching radio
function setLinkType(type) {
    const radio = document.querySelector('[name="link_type"][value="' + type + '"]');
    if (radio) radio.checked = true;
    toggleLinkType(type);
}

function toggleLinkType(type) {
    const url  = document.getElementById('urlGroup');
    const file = document.getElementById('fileGroup');
    if (!url || !file) return;
    url.classList.toggle('hidden',  type !== 'url');
    file.classList.toggle('hidden', type !== 'file');
}

document.querySelectorAll('[name="link_type"]').forEach(radio => {
    radio.addEventListener('change', () => toggleLinkType(radio.value));
});

// Evidence form submit
const evForm = document.getElementById('evForm');
if (evForm) {
    evForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn = document.getElementById('evSubmitBtn');
        btn.disabled = true;
        const fd = new FormData(this);
        fd.set('csrf_token', CSRF_TOKEN);
        try {
            const res  = await fetch(APP_URL + '/api.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (json.ok) {
                const isEdit = document.getElementById('evAction').value === 'edit_evidence';
                const n = json.data && json.data.created ? json.data.created : 1;
                closeEvModal();
                showToast(isEdit ? 'บันทึกการแก้ไขเรียบร้อย'
                                 : (n > 1 ? 'เพิ่มหลักฐาน ' + n + ' รายการเรียบร้อย' : 'เพิ่มหลักฐานเรียบร้อย'));
                // Reload detail panel
                if (window.selectedIndicatorId) loadIndicator(window.selectedIndicatorId);
            } else { showToast(json.error, 'error'); }
        } catch { showToast('เกิดข้อผิดพลาด', 'error'); }
        btn.disabled = false;
    });
}

// ── Delete Evidence ───────────────────────────────────────
async function deleteEvidence(evId, indId) {
    if (!await uiConfirm('ต้องการลบหลักฐานนี้ออกจากระบบ?',
        { title:'ลบหลักฐาน', confirmLabel:'ลบ', danger:true })) return;
    apiPost({ action: 'delete_evidence', evidence_id: evId }).then(r => {
        if (r.ok) {
            showToast('ลบหลักฐานแล้ว');
            // Reload detail
            if (indId) loadIndicator(indId);
        } else { showToast(r.error, 'error'); }
    });
}

// ── Close modal on backdrop click ────────────────────────
document.querySelectorAll('.modal-backdrop').forEach(bd => {
    bd.addEventListener('click', function (e) {
        if (e.target === this) this.classList.add('hidden');
    });
});

// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-backdrop:not(.hidden)').forEach(m => m.classList.add('hidden'));
    }
});

// hidden class for link-type toggle
document.head.insertAdjacentHTML('beforeend', '<style>.hidden{display:none!important}</style>');
