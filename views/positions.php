<?php
require_role('centraladmin');
?>
<div class="pos-manage">

  <!-- CENTRAL POSITIONS -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">ตำแหน่งกลาง <span class="pos-hint">— ใช้ได้ทุกสถานศึกษา สถานศึกษาแก้ไขไม่ได้</span></span>
    </div>
    <div class="card-body">
      <form id="addCentralForm" class="pos-add-row">
        <input type="text" id="newCentralInput" class="form-input" maxlength="150" placeholder="เพิ่มตำแหน่งกลางใหม่…" required autocomplete="off">
        <button type="submit" class="btn btn-primary">เพิ่มตำแหน่งกลาง</button>
      </form>
      <div id="centralList" class="pos-list"></div>
    </div>
  </div>

  <!-- SCHOOL POSITIONS (candidates to promote) -->
  <div class="card">
    <div class="card-header">
      <span class="card-title">ตำแหน่งของสถานศึกษา</span>
      <input type="search" id="schoolPosSearch" class="form-input pos-search" placeholder="ค้นหาชื่อตำแหน่ง / สถานศึกษา…" autocomplete="off">
    </div>
    <div class="card-body">
      <div class="alert alert-info" style="margin-bottom:14px">
        กด “อนุมัติเป็นตำแหน่งกลาง” เพื่อให้ทุกสถานศึกษาใช้ชื่อตำแหน่งนี้ได้ ระบบจะรวมตำแหน่งชื่อเดียวกันจากทุกสถานศึกษาเข้าด้วยกันโดยอัตโนมัติ
      </div>
      <div id="schoolPosList" class="pos-list"></div>
    </div>
  </div>
</div>

<script>
(function () {
    let schoolRows = [];

    async function load() {
        const r = await apiPost({ action: 'list_central_positions' });
        if (!r.ok) { showToast(r.error, 'error'); return; }
        renderCentral(r.data.central || []);
        schoolRows = r.data.school || [];
        renderSchool();
    }

    function renderCentral(items) {
        const box = document.getElementById('centralList');
        if (!items.length) { box.innerHTML = '<div class="pos-empty">ยังไม่มีตำแหน่งกลาง</div>'; return; }
        box.innerHTML = items.map(p =>
            '<div class="pos-row" data-id="' + p.id + '">'
            + '<input class="form-input pos-name" value="' + escHtml(p.name) + '" maxlength="150">'
            + '<span class="pos-count">' + (p.n > 0 ? (p.n + ' คน') : 'ยังไม่มีผู้ครอง') + '</span>'
            + '<button type="button" class="btn btn-ghost btn-sm" onclick="renameCentral(' + p.id + ', this)">บันทึก</button>'
            + '<button type="button" class="icon-btn icon-btn-danger" title="ลบตำแหน่งกลาง" onclick="delCentral(' + p.id + ')">'
            + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>'
            + '</button></div>').join('');
    }

    function renderSchool() {
        const q = (document.getElementById('schoolPosSearch').value || '').toLowerCase().trim();
        const rows = q ? schoolRows.filter(r => (r.name + ' ' + r.school_name).toLowerCase().indexOf(q) !== -1) : schoolRows;
        const box = document.getElementById('schoolPosList');
        if (!rows.length) { box.innerHTML = '<div class="pos-empty">ไม่พบตำแหน่งของสถานศึกษา</div>'; return; }
        box.innerHTML = rows.map(p =>
            '<div class="pos-row pos-row-school">'
            + '<div class="pos-sch-info"><span class="pos-sch-name">' + escHtml(p.name) + '</span>'
            + '<span class="pos-sch-sub">' + escHtml(p.school_name) + ' · ' + (p.n > 0 ? (p.n + ' คน') : 'ยังไม่มีผู้ครอง') + '</span></div>'
            + '<button type="button" class="btn btn-primary btn-sm" onclick="promotePos(' + p.id + ')">อนุมัติเป็นตำแหน่งกลาง</button>'
            + '</div>').join('');
    }

    document.getElementById('schoolPosSearch').addEventListener('input', renderSchool);
    document.getElementById('addCentralForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const inp = document.getElementById('newCentralInput');
        const name = inp.value.trim();
        if (!name) return;
        const r = await apiPost({ action: 'add_central_position', name });
        if (r.ok) { inp.value = ''; showToast('เพิ่มตำแหน่งกลางแล้ว'); load(); }
        else showToast(r.error, 'error');
    });

    window.renameCentral = async function (id, btn) {
        const name = btn.closest('.pos-row').querySelector('.pos-name').value.trim();
        if (!name) return;
        const r = await apiPost({ action: 'rename_central_position', id, name });
        if (r.ok) { showToast('บันทึกแล้ว'); load(); } else showToast(r.error, 'error');
    };
    window.delCentral = async function (id) {
        if (!await uiConfirm('ลบตำแหน่งกลางนี้? ผู้ที่ครองตำแหน่งนี้ทุกสถานศึกษาจะถูกถอดออก', { title: 'ลบตำแหน่งกลาง', confirmLabel: 'ลบ', danger: true })) return;
        const r = await apiPost({ action: 'delete_central_position', id });
        if (r.ok) { showToast('ลบตำแหน่งกลางแล้ว'); load(); } else showToast(r.error, 'error');
    };
    window.promotePos = async function (id) {
        const name = (schoolRows.find(r => r.id == id) || {}).name || 'ตำแหน่งนี้';
        if (!await uiConfirm('อนุมัติ “' + name + '” เป็นตำแหน่งกลาง? ทุกสถานศึกษาจะใช้ได้ และตำแหน่งชื่อเดียวกันจากทุกสถานศึกษาจะถูกรวมเข้าด้วยกัน', { title: 'อนุมัติเป็นตำแหน่งกลาง', confirmLabel: 'อนุมัติ' })) return;
        const r = await apiPost({ action: 'promote_position', id });
        if (r.ok) { showToast('อนุมัติเป็นตำแหน่งกลางแล้ว'); load(); } else showToast(r.error, 'error');
    };

    // Defer initial load until app.js (apiPost) is available — this inline
    // script runs before app.js in the page source.
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
    else load();
})();
</script>
