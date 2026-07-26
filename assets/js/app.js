'use strict';

// ── Mobile sidebar (off-canvas) ───────────────────────────
(function () {
    const toggle   = document.getElementById('navToggle');
    const sidebar  = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (!toggle || !sidebar) return;
    const open  = () => { sidebar.classList.add('sidebar--open'); document.body.classList.add('nav-open'); };
    const close = () => { sidebar.classList.remove('sidebar--open'); document.body.classList.remove('nav-open'); };
    toggle.addEventListener('click', () => sidebar.classList.contains('sidebar--open') ? close() : open());
    backdrop && backdrop.addEventListener('click', close);
    // Close after tapping a nav link
    sidebar.querySelectorAll('.nav-item').forEach(a => a.addEventListener('click', close));
    window.addEventListener('resize', () => { if (window.innerWidth > 820) close(); });
})();

// ── Deadline countdown ────────────────────────────────────
(function () {
    const box  = document.getElementById('deadlineBox');
    if (!box) return;
    const text = document.getElementById('deadlineText');
    const iso  = box.dataset.deadline;
    if (!iso) return; // no deadline set

    const target = new Date(iso).getTime();
    const two = n => String(n).padStart(2, '0');

    function tick() {
        const diff = target - Date.now();
        box.classList.remove('deadline-warn', 'deadline-over');
        if (diff <= 0) {
            const over = Math.abs(diff);
            const d = Math.floor(over / 86400000);
            text.textContent = 'เลยกำหนดแล้ว ' + (d > 0 ? d + ' วัน' : '') +
                two(Math.floor(over / 3600000) % 24) + ':' + two(Math.floor(over / 60000) % 60) + ':' + two(Math.floor(over / 1000) % 60);
            box.classList.add('deadline-over');
            return;
        }
        const d = Math.floor(diff / 86400000);
        const h = Math.floor(diff / 3600000) % 24;
        const m = Math.floor(diff / 60000) % 60;
        const s = Math.floor(diff / 1000) % 60;
        text.textContent = 'เหลืออีก ' + (d > 0 ? d + ' วัน ' : '') + two(h) + ':' + two(m) + ':' + two(s);
        if (diff < 3 * 86400000) box.classList.add('deadline-warn'); // < 3 days
    }
    tick();
    setInterval(tick, 1000);
})();

// Set-deadline modal (schooladmin)
function openDeadline() { document.getElementById('deadlineModal')?.classList.remove('hidden'); }
function clearDeadline() {
    apiPost({ action: 'set_deadline', year_code: YEAR_CODE, deadline: '' }).then(r => {
        r.ok ? location.reload() : showToast(r.error, 'error');
    });
}
(function () {
    const form = document.getElementById('deadlineForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const val = document.getElementById('deadlineInput').value;
        if (!val) { showToast('กรุณาเลือกวันเวลา', 'error'); return; }
        apiPost({ action: 'set_deadline', year_code: YEAR_CODE, deadline: val }).then(r => {
            r.ok ? location.reload() : showToast(r.error, 'error');
        });
    });
})();

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
const STATUS_CHIP  = { done:'chip-done', inprogress:'chip-prog', pending:'chip-pend' };
const STATUS_LABEL = { done:'เผยแพร่แล้ว', inprogress:'กำลังดำเนินการ', pending:'ยังไม่ดำเนินการ' };

// Repaint the tree row and the detail panel's status buttons for one indicator
function reflectStatus(indId, status) {
    if (!STATUS_LABEL[status]) return;
    const treeEl = document.querySelector('.tree-ind[data-id="' + indId + '"]');
    if (treeEl) {
        treeEl.className = treeEl.className.replace(/status-\w+/, 'status-' + status);
        const chip = treeEl.querySelector('.chip');
        if (chip) {
            chip.className = 'chip ' + STATUS_CHIP[status];
            chip.textContent = STATUS_LABEL[status];
        }
    }
    document.querySelectorAll('.status-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.status === status);
    });
}

function updateStatus(indId, status) {
    apiPost({ action: 'update_status', indicator_id: indId, status }).then(r => {
        if (r.ok) {
            showToast('บันทึกสถานะเรียบร้อย');
            reflectStatus(indId, status);
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

// ── Team workflow: assistants + document tasks + accept ───
function teamData() {
    try { return JSON.parse(document.querySelector('#indDetail .team-data').textContent); }
    catch (e) { return { schoolUsers: [], assistants: [], proposeOnly: true, ind: 0 }; }
}
function reloadPanel() { if (window.selectedIndicatorId) loadIndicator(window.selectedIndicatorId); }

// Assistants
function openAssistantPicker(indId) {
    const d = teamData();
    document.getElementById('asstIndId').value = indId;
    document.getElementById('asstModalTitle').textContent = d.proposeOnly ? 'เสนอผู้ช่วยผู้รับผิดชอบ' : 'เพิ่มผู้ช่วยผู้รับผิดชอบ';
    const search = document.getElementById('asstSearch');
    search.value = '';
    const render = () => {
        const q = search.value.toLowerCase().trim();
        const positions = (d.positions || []).filter(p => !q || p.name.toLowerCase().indexOf(q) !== -1).slice(0, 20);
        const users = d.schoolUsers.filter(u => !q || (u.name + ' ' + u.nick + ' ' + (u.pos || '')).toLowerCase().indexOf(q) !== -1).slice(0, 60);
        let html = '';
        if (positions.length) {
            html += '<div class="pick-group">ตำแหน่ง</div>';
            html += positions.map(p => '<button type="button" class="pick-row" onclick="addAssistant(\'position\',' + p.id + ')">'
                + '<span class="avatar avatar-sm track-avatar-position"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>'
                + '<span>' + escHtml(p.name) + ' <span class="user-nick">(' + p.n + ' คน)</span></span></button>').join('');
        }
        if (users.length) {
            html += '<div class="pick-group">บุคคล</div>';
            html += users.map(u => '<button type="button" class="pick-row" onclick="addAssistant(\'user\',' + u.id + ')">'
                + avatarMini(u.pic, u.name) + '<span>' + escHtml(u.name) + (u.nick ? ' <span class="user-nick">(' + escHtml(u.nick) + ')</span>' : '') + '</span></button>').join('');
        }
        document.getElementById('asstPickList').innerHTML = html || '<div class="pick-empty">ไม่พบบุคคลหรือตำแหน่ง</div>';
    };
    search.oninput = render; render();
    document.getElementById('asstModal').classList.remove('hidden');
    setTimeout(() => search.focus(), 50);
}
function addAssistant(type, id) {
    const indId = document.getElementById('asstIndId').value;
    apiPost({ action: 'add_assistant', indicator_id: indId, target_type: type, target_id: id }).then(r => {
        if (!r.ok) { showToast(r.error, 'error'); return; }
        document.getElementById('asstModal').classList.add('hidden');
        showToast(r.data && r.data.status === 'proposed' ? 'เสนอผู้ช่วยแล้ว รอผู้ดูแลอนุมัติ' : 'เพิ่มผู้ช่วยแล้ว');
        reloadPanel();
    });
}
function approveAssistant(id) {
    apiPost({ action: 'approve_assistant', id }).then(r => {
        r.ok ? (showToast('อนุมัติผู้ช่วยแล้ว'), reloadPanel()) : showToast(r.error, 'error');
    });
}
async function removeAssistant(id, name) {
    if (!await uiConfirm('นำ ' + name + ' ออกจากผู้ช่วยผู้รับผิดชอบ?', { title: 'นำผู้ช่วยออก', confirmLabel: 'นำออก', danger: true })) return;
    apiPost({ action: 'remove_assistant', id }).then(r => {
        r.ok ? (showToast('นำผู้ช่วยออกแล้ว'), reloadPanel()) : showToast(r.error, 'error');
    });
}

// Document tasks
function openDocTaskModal(indId, task) {
    const d = teamData();
    document.getElementById('docTaskIndId').value = indId;
    document.getElementById('docTaskId').value    = task ? task.id : '';
    document.getElementById('docTaskTitle').value = task ? task.title : '';
    document.getElementById('docTaskDesc').value  = task ? task.description : '';
    document.getElementById('docTaskModalTitle').textContent = task ? 'แก้ไขหัวข้อเอกสาร' : 'เพิ่มหัวข้อเอกสาร';
    const chosen = task && task.assignees ? task.assignees : [];
    document.getElementById('docTaskAsgnList').innerHTML = d.assistants.length
        ? d.assistants.map(a => '<label class="pick-check"><input type="checkbox" value="' + a.id + '"' + (chosen.indexOf(a.id) !== -1 ? ' checked' : '') + '>'
            + avatarMini(a.pic, a.name) + '<span>' + escHtml(a.name) + '</span></label>').join('')
        : '<div class="pick-empty">ยังไม่มีผู้ช่วยที่อนุมัติแล้ว</div>';
    document.getElementById('docTaskModal').classList.remove('hidden');
}
function docTaskSubmit(e) {
    e.preventDefault();
    const id    = document.getElementById('docTaskId').value;
    const indId = document.getElementById('docTaskIndId').value;
    const title = document.getElementById('docTaskTitle').value.trim();
    const desc  = document.getElementById('docTaskDesc').value.trim();
    if (desc.length < 10) { showToast('คำอธิบายต้องมีอย่างน้อย 10 ตัวอักษร', 'error'); return; }
    const assignees = [...document.querySelectorAll('#docTaskAsgnList input:checked')].map(c => parseInt(c.value, 10));
    const payload = { action: id ? 'edit_doc_task' : 'add_doc_task', indicator_id: indId, title, description: desc, assignees: JSON.stringify(assignees) };
    if (id) payload.id = id;
    apiPost(payload).then(r => {
        if (!r.ok) { showToast(r.error, 'error'); return; }
        document.getElementById('docTaskModal').classList.add('hidden');
        showToast('บันทึกหัวข้อเอกสารแล้ว');
        reloadPanel();
    });
}
async function deleteDocTask(id, title) {
    if (!await uiConfirm('ลบหัวข้อเอกสาร "' + title + '"? (ไฟล์ที่แนบไว้จะย้ายไปหลักฐานทั่วไป)', { title: 'ลบหัวข้อเอกสาร', confirmLabel: 'ลบ', danger: true })) return;
    apiPost({ action: 'delete_doc_task', id }).then(r => {
        r.ok ? (showToast('ลบหัวข้อเอกสารแล้ว'), reloadPanel()) : showToast(r.error, 'error');
    });
}

// Evidence acceptance
function acceptEvidence(id) {
    apiPost({ action: 'accept_evidence', evidence_id: id }).then(r => {
        r.ok ? (showToast('ยอมรับหลักฐานให้เผยแพร่ได้'), reloadPanel()) : showToast(r.error, 'error');
    });
}
async function unacceptEvidence(id) {
    if (!await uiConfirm('ยกเลิกการยอมรับหลักฐานนี้? จะถูกถอนจากการเผยแพร่', { title: 'ยกเลิกการยอมรับ', confirmLabel: 'ยกเลิกรับ', danger: true })) return;
    apiPost({ action: 'unaccept_evidence', evidence_id: id }).then(r => {
        r.ok ? (showToast('ยกเลิกการยอมรับแล้ว'), reloadPanel()) : showToast(r.error, 'error');
    });
}

document.getElementById('docTaskForm')?.addEventListener('submit', docTaskSubmit);

// ── Evidence Modal ────────────────────────────────────────
function openEvModal(indId, taskId) {
    const modal = document.getElementById('evModal');
    if (!modal) return;
    document.getElementById('evForm')?.reset();
    document.getElementById('evAction').value = 'add_evidence';
    document.getElementById('evEvId').value   = '';
    document.getElementById('evIndId').value  = indId;
    document.getElementById('evTaskId').value = taskId || '';
    document.getElementById('evModalTitle').textContent = taskId ? 'แนบไฟล์ในหัวข้อเอกสาร' : 'เพิ่มหลักฐาน';
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
    document.getElementById('evTaskId').value = '';
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
    // Cancelling mid-upload aborts the request instead of leaving it running
    if (evUploadXhr) { evUploadXhr.abort(); evUploadXhr = null; }
    evProgReset();
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

// ── Upload progress ───────────────────────────────────────
// Uploading a large evidence file can take a while with no visible feedback,
// so the submit goes through XMLHttpRequest (fetch cannot report upload
// progress) and drives a bar in the modal.
let evUploadXhr = null;

function fmtBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
    return (b / 1048576).toFixed(1) + ' MB';
}

function evProgShow(fileCount) {
    const box = document.getElementById('evProgress');
    if (!box) return;
    box.classList.remove('hidden');
    document.getElementById('evProgTrack').classList.remove('indeterminate');
    document.getElementById('evProgFill').style.width = '0';
    document.getElementById('evProgPct').textContent = '0%';
    document.getElementById('evProgLabel').textContent =
        fileCount > 1 ? 'กำลังอัปโหลด ' + fileCount + ' ไฟล์…' : 'กำลังอัปโหลด…';
    document.getElementById('evProgSub').textContent = 'กรุณาอย่าปิดหน้านี้';
    const btn = document.getElementById('evSubmitBtn');
    btn.classList.add('btn-uploading');
    btn.dataset.label = btn.textContent;
    btn.textContent = 'กำลังอัปโหลด…';
}

function evProgUpdate(loaded, total) {
    const pct = total ? Math.round(loaded / total * 100) : 0;
    document.getElementById('evProgFill').style.width = pct + '%';
    document.getElementById('evProgPct').textContent = pct + '%';
    document.getElementById('evProgSub').textContent = fmtBytes(loaded) + ' / ' + fmtBytes(total);
}

// Bytes are all sent but the server is still writing files — switch the bar to
// the indeterminate sweep so it does not look frozen at 100%.
function evProgProcessing() {
    document.getElementById('evProgTrack')?.classList.add('indeterminate');
    document.getElementById('evProgPct').textContent = '';
    document.getElementById('evProgLabel').textContent = 'กำลังบันทึกที่เซิร์ฟเวอร์…';
    document.getElementById('evProgSub').textContent = 'อีกสักครู่';
}

function evProgReset() {
    document.getElementById('evProgress')?.classList.add('hidden');
    document.getElementById('evProgTrack')?.classList.remove('indeterminate');
    const btn = document.getElementById('evSubmitBtn');
    if (btn) {
        btn.classList.remove('btn-uploading');
        btn.disabled = false;
        if (btn.dataset.label) btn.textContent = btn.dataset.label;
    }
}

// Evidence form submit
const evForm = document.getElementById('evForm');
if (evForm) {
    evForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = document.getElementById('evSubmitBtn');
        btn.disabled = true;
        const fd = new FormData(this);
        fd.set('csrf_token', CSRF_TOKEN);

        const files = document.getElementById('evFileInput')?.files;
        const isFile = document.querySelector('[name="link_type"]:checked')?.value === 'file';
        const nFiles = isFile && files ? files.length : 0;

        // Catch an oversized file here rather than after uploading all of it
        for (let i = 0; i < nFiles; i++) {
            if (files[i].size > MAX_UPLOAD) {
                btn.disabled = false;
                showToast('ไฟล์ "' + files[i].name + '" ขนาด ' + fmtBytes(files[i].size)
                        + ' ใหญ่เกิน ' + MAX_UPLOAD_MB + ' MB', 'error');
                return;
            }
        }
        if (nFiles) evProgShow(nFiles);

        const done = json => {
            if (json.ok) {
                const isEdit = document.getElementById('evAction').value === 'edit_evidence';
                const n = json.data && json.data.created ? json.data.created : 1;
                evProgReset();
                closeEvModal();
                showToast(isEdit ? 'บันทึกการแก้ไขเรียบร้อย'
                                 : (n > 1 ? 'เพิ่มหลักฐาน ' + n + ' รายการเรียบร้อย' : 'เพิ่มหลักฐานเรียบร้อย'));
                // The server lifts a pending indicator to "กำลังดำเนินการ" on attach
                const indId = document.getElementById('evIndId').value;
                if (json.data && json.data.status) reflectStatus(indId, json.data.status);
                // Reload detail panel
                if (window.selectedIndicatorId) loadIndicator(window.selectedIndicatorId);
            } else {
                evProgReset();
                showToast(json.error, 'error');
            }
        };

        const xhr = new XMLHttpRequest();
        evUploadXhr = xhr;
        xhr.open('POST', APP_URL + '/api.php');
        xhr.upload.addEventListener('progress', ev => {
            if (!nFiles || !ev.lengthComputable) return;
            evProgUpdate(ev.loaded, ev.total);
            if (ev.loaded >= ev.total) evProgProcessing();
        });
        xhr.addEventListener('load', () => {
            evUploadXhr = null;
            let json;
            try { json = JSON.parse(xhr.responseText); }
            catch { evProgReset(); showToast('เกิดข้อผิดพลาด', 'error'); return; }
            done(json);
        });
        xhr.addEventListener('error', () => {
            evUploadXhr = null; evProgReset();
            showToast('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ', 'error');
        });
        xhr.addEventListener('abort', () => { evUploadXhr = null; evProgReset(); });
        xhr.send(fd);
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

// Form modals close only via their ✕ / cancel buttons (prevents accidental
// dismissal that would lose entered data). Backdrop-click and Escape are
// intentionally NOT wired up here.

// hidden class for link-type toggle
document.head.insertAdjacentHTML('beforeend', '<style>.hidden{display:none!important}</style>');
