/* =====================================================================
   G-SMaRT HRIS — client-side helpers (vanilla JavaScript, no libraries)
   ===================================================================== */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ------------------------------------------------------------ sidebar */
  var toggle = document.getElementById('navToggle');
  if (toggle) {
    toggle.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
    document.addEventListener('click', function (e) {
      if (document.body.classList.contains('nav-open') && !e.target.closest('#sidebar') && !e.target.closest('#navToggle')) {
        document.body.classList.remove('nav-open');
      }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.body.classList.remove('nav-open'); });
  }

  /* ------------------------------------------------------------ responsive tables
     Any data table that is not already inside a scrolling frame gets one, so a
     wide table scrolls sideways within its card instead of stretching the page
     on a phone. Print styles remove the frame again. */
  $$('table.table, table.doc-table, table.reg, table.matrix').forEach(function (t) {
    if (t.closest('.table-wrap, .grid-wrap, .table-scroll, .matrix-wrap, .reg-screen')) return;
    var w = document.createElement('div');
    w.className = 'table-wrap table-wrap-auto';
    t.parentNode.insertBefore(w, t);
    w.appendChild(t);
  });

  // menu filter
  var navFind = document.getElementById('navFind');
  if (navFind) {
    var navEmpty = document.getElementById('navEmpty');
    navFind.addEventListener('input', function () {
      var q = navFind.value.toLowerCase().trim();
      var hits = 0;
      $$('#navMenu .nav-label').forEach(function (el) { el.style.display = q ? 'none' : ''; });
      $$('#navMenu .nav-link').forEach(function (a) {
        var on = !q || a.textContent.toLowerCase().indexOf(q) !== -1;
        a.style.display = on ? '' : 'none';
        if (on && q) hits++;
      });
      $$('#navMenu .nav-group').forEach(function (g) {
        var shown = 0;
        $$('.nav-sub', g).forEach(function (a) {
          var on = !q || a.textContent.toLowerCase().indexOf(q) !== -1 || g.querySelector('summary').textContent.toLowerCase().indexOf(q) !== -1;
          a.style.display = on ? '' : 'none';
          if (on) shown++;
        });
        g.style.display = !q || shown ? '' : 'none';
        if (q && shown) { g.open = true; hits += shown; }
        if (!q) { g.open = !!g.querySelector('.nav-sub.active'); }
      });
      if (navEmpty) navEmpty.style.display = q && !hits ? 'block' : 'none';
    });
  }

  /* ------------------------------------------------------------ command palette */
  var cmdk = document.getElementById('cmdk');
  if (cmdk) {
    var input = document.getElementById('cmdkInput');
    var list = document.getElementById('cmdkList');
    var items = [];
    $$('#navMenu a').forEach(function (a) {
      var grp = a.closest('.nav-group');
      items.push({ label: a.textContent.trim(), group: grp ? grp.querySelector('summary').textContent.trim() : '', href: a.getAttribute('href') });
    });
    var sel = 0, shown = [];
    function render(q) {
      q = (q || '').toLowerCase().trim();
      shown = items.filter(function (i) { return !q || (i.label + ' ' + i.group).toLowerCase().indexOf(q) !== -1; }).slice(0, 40);
      sel = 0;
      if (!shown.length) { list.innerHTML = '<div class="cmdk-empty">Nothing found.</div>'; return; }
      list.innerHTML = shown.map(function (i, n) {
        return '<a class="cmdk-item' + (n === 0 ? ' sel' : '') + '" href="' + i.href + '">' + esc(i.label) + (i.group ? '<small>' + esc(i.group) + '</small>' : '') + '</a>';
      }).join('');
    }
    function esc(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function open() { cmdk.classList.add('open'); input.value = ''; render(''); setTimeout(function () { input.focus(); }, 30); }
    function close() { cmdk.classList.remove('open'); }
    function move(d) {
      var els = $$('.cmdk-item', list);
      if (!els.length) return;
      els[sel] && els[sel].classList.remove('sel');
      sel = (sel + d + els.length) % els.length;
      els[sel].classList.add('sel');
      els[sel].scrollIntoView({ block: 'nearest' });
    }
    var btn = document.getElementById('cmdkOpen');
    if (btn) btn.addEventListener('click', open);
    input.addEventListener('input', function () { render(input.value); });
    cmdk.addEventListener('click', function (e) { if (e.target === cmdk) close(); });
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); cmdk.classList.contains('open') ? close() : open(); return; }
      if (!cmdk.classList.contains('open')) return;
      if (e.key === 'Escape') { close(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
      else if (e.key === 'Enter') { var el = $$('.cmdk-item', list)[sel]; if (el) { e.preventDefault(); location.href = el.getAttribute('href'); } }
    });
  }

  /* ------------------------------------------------------------ confirms / double submit */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var msg = f.getAttribute('data-confirm');
    var submitter = e.submitter;
    if (submitter && submitter.getAttribute('data-confirm')) { msg = submitter.getAttribute('data-confirm'); }
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    f.setAttribute('data-submitting', '1');
    if (submitter && !submitter.hasAttribute('data-allow-resubmit')) {
      setTimeout(function () { submitter.disabled = true; }, 0);
      setTimeout(function () { submitter.disabled = false; }, 8000);
    }
  });
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-confirm]');
    if (a && !window.confirm(a.getAttribute('data-confirm'))) { e.preventDefault(); }
  });

  /* ------------------------------------------------------------ table search */
  $$('.table-search').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var t = document.getElementById(inp.getAttribute('data-target'));
      if (!t) return;
      var q = inp.value.toLowerCase().trim();
      var n = 0;
      $$('tbody tr', t).forEach(function (tr) {
        var on = !q || tr.textContent.toLowerCase().indexOf(q) !== -1;
        tr.style.display = on ? '' : 'none';
        if (on) n++;
      });
      var out = inp.getAttribute('data-count') && document.getElementById(inp.getAttribute('data-count'));
      if (out) out.textContent = n;
    });
  });
  // the master list of split (master/detail) pages is narrowed by the v16 incremental search (end of file)

  /* ------------------------------------------------------------ tabs */
  $$('.tabs').forEach(function (tabs) {
    var buttons = $$('.tab', tabs);
    function activate(id, push) {
      buttons.forEach(function (b) {
        var on = b.getAttribute('data-tab') === id;
        b.classList.toggle('active', on);
        var p = document.getElementById(b.getAttribute('data-tab'));
        if (p) p.classList.toggle('active', on);
      });
      if (push) { history.replaceState(null, '', '#' + id); }
    }
    buttons.forEach(function (b) { b.addEventListener('click', function () { activate(b.getAttribute('data-tab'), true); }); });
    var hash = location.hash.replace('#', '');
    var start = tabs.getAttribute('data-initial');
    if (!(start && tabs.querySelector('.tab[data-tab="' + start + '"]'))) start = buttons[0] && buttons[0].getAttribute('data-tab');
    var initial = hash && tabs.querySelector('.tab[data-tab="' + hash + '"]') ? hash : start;
    if (initial) activate(initial, false);
  });

  /* ------------------------------------------------------------ modals */
  document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-modal-open]');
    if (o) {
      e.preventDefault();
      var m = document.getElementById(o.getAttribute('data-modal-open'));
      if (m) {
        m.classList.add('open');
        Object.keys(o.dataset).forEach(function (k) {
          if (k.indexOf('field') === 0) {
            var name = k.substring(5).replace(/^./, function (c) { return c.toLowerCase(); }).replace(/[A-Z]/g, function (c) { return '_' + c.toLowerCase(); });
            var inp = m.querySelector('[name="' + name + '"]');
            if (inp) inp.value = o.dataset[k];
            var txt = m.querySelector('[data-text="' + name + '"]');
            if (txt) txt.textContent = o.dataset[k];
          }
        });
        var first = m.querySelector('input:not([type=hidden]), textarea, select');
        if (first) setTimeout(function () { first.focus(); }, 50);
      }
    }
    if (e.target.closest('[data-modal-close]') || e.target.classList.contains('modal')) {
      var mm = e.target.closest('.modal');
      if (mm) mm.classList.remove('open');
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') $$('.modal.open').forEach(function (m) { m.classList.remove('open'); });
  });

  /* ------------------------------------------------------------ misc form helpers */
  $$('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form && el.form.submit(); });
  });
  $$('[data-check-all]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      $$('input[type=checkbox][name="' + cb.getAttribute('data-check-all') + '"]').forEach(function (x) {
        if (x.closest('tr') && x.closest('tr').style.display === 'none') return;
        x.checked = cb.checked;
      });
    });
  });
  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add-row]');
    if (add) {
      e.preventDefault();
      var id = add.getAttribute('data-add-row');
      var tpl = document.getElementById(id + '-tpl');
      var body = document.getElementById(id);
      if (tpl && body) body.appendChild(tpl.content.cloneNode(true));
    }
    var rm = e.target.closest('.remove-row');
    if (rm) { e.preventDefault(); var tr = rm.closest('tr'); if (tr) tr.remove(); }
  });

  /* ------------------------------------------------------------ number cells: select on focus, arrow navigation */
  document.addEventListener('focusin', function (e) {
    var el = e.target;
    if (el.matches && el.matches('.grid-entry input, .hours-grid input, .matrix input')) {
      if (el.type === 'number' || el.type === 'text') { setTimeout(function () { el.select(); }, 0); }
    }
  });
  document.addEventListener('keydown', function (e) {
    var el = e.target;
    if (!el.matches || !el.matches('.grid-entry input')) return;
    if (['ArrowDown', 'ArrowUp', 'Enter'].indexOf(e.key) === -1) return;
    if (el.type === 'number' && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) e.preventDefault();
    var td = el.closest('td'), tr = el.closest('tr');
    if (!td || !tr) return;
    var idx = Array.prototype.indexOf.call(tr.children, td);
    var next = e.key === 'ArrowUp' ? tr.previousElementSibling : tr.nextElementSibling;
    if (e.key === 'Enter') { e.preventDefault(); next = tr.nextElementSibling; }
    while (next && !next.children[idx]) { next = e.key === 'ArrowUp' ? next.previousElementSibling : next.nextElementSibling; }
    var target = next && next.children[idx] && next.children[idx].querySelector('input');
    if (target) { target.focus(); }
  });

  /* ------------------------------------------------------------ entry grids: dirty rows, totals, zero dimming */
  function fmt(n) { return (Math.round(n * 100) / 100).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  function dim(inp) {
    // v17: a log time is never dimmed (00:58 is a real time after midnight, not an empty value)
    if (!inp.classList.contains('cell') || inp.type === 'time') return;
    var v = parseFloat(inp.value || '0');
    inp.classList.toggle('zero', !v);
  }
  function recalcGrid(grid) {
    // column totals in tfoot: <td data-total="field">
    var totals = {};
    $$('input.cell', grid).forEach(function (inp) {
      var f = inp.getAttribute('data-field');
      totals[f] = (totals[f] || 0) + (parseFloat(inp.value || '0') || 0);
    });
    $$('[data-total]', grid).forEach(function (td) {
      var f = td.getAttribute('data-total');
      td.textContent = totals[f] ? fmt(totals[f]) : '';
    });
    // row totals: <td data-row-total> sums inputs of that row
    $$('tr', grid).forEach(function (tr) {
      var out = tr.querySelector('[data-row-total]');
      if (!out) return;
      var s = 0;
      $$('input.cell', tr).forEach(function (i) { s += parseFloat(i.value || '0') || 0; });
      out.textContent = s ? fmt(s) : '';
    });
  }
  $$('.grid-entry').forEach(function (grid) {
    $$('input.cell', grid).forEach(dim);
    recalcGrid(grid);
    grid.addEventListener('input', function (e) {
      if (!e.target.classList.contains('cell')) return;
      dim(e.target);
      var tr = e.target.closest('tr');
      if (tr) tr.classList.add('dirty');
      var f = e.target.closest('form');
      if (f) f.setAttribute('data-dirty', '1');
      recalcGrid(grid);
      var c = document.getElementById('dirtyCount');
      if (c) c.textContent = $$('tr.dirty', grid).length;
    });
  });
  // live totals for single-record hour forms
  $$('[data-live-total]').forEach(function (form) {
    var out = document.getElementById(form.getAttribute('data-live-total'));
    function calc() {
      var groups = {};
      $$('input[data-group]', form).forEach(function (i) {
        var g = i.getAttribute('data-group');
        groups[g] = (groups[g] || 0) + (parseFloat(i.value || '0') || 0);
      });
      Object.keys(groups).forEach(function (g) {
        var el = form.querySelector('[data-group-total="' + g + '"]');
        if (el) el.textContent = fmt(groups[g]);
      });
      if (out) {
        var t = 0;
        $$('input[data-hours]', form).forEach(function (i) { t += parseFloat(i.value || '0') || 0; });
        out.textContent = fmt(t);
      }
    }
    form.addEventListener('input', calc);
    calc();
  });

  /* ------------------------------------------------------------ payroll register: pick an employee, open a screen for them
     Clicking a row of the register selects that employee (row-sel). Every toolbar link with
     data-emp-link="<param>" then carries that employee (emp=<id>, or ids[]=<payroll item> for the
     payslip reprint), so Fixed Transaction Setup, Payroll Transaction, Employee's Transactions,
     Transaction Payment and the Payroll run open already positioned on that person. */
  var regTable = $('table[data-select-emp]');
  if (regTable) {
    var regLinks = $$('[data-emp-link]');
    regLinks.forEach(function (a) { a.setAttribute('data-base', a.getAttribute('href')); });
    var regSel = document.getElementById('regSel');
    function withParam(base, name, value) {
      var url = base.replace(new RegExp('([?&])' + name.replace(/[\[\]]/g, '\\$&') + '=[^&]*'), '$1').replace(/[?&]$/, '').replace(/&&+/g, '&');
      return value ? url + (url.indexOf('?') === -1 ? '?' : '&') + encodeURIComponent(name) + '=' + encodeURIComponent(value) : url;
    }
    function selectRow(tr) {
      var on = tr && !tr.classList.contains('row-sel');
      $$('tr.row-sel', regTable).forEach(function (r) { r.classList.remove('row-sel'); });
      if (on) tr.classList.add('row-sel');
      var emp = on ? tr.getAttribute('data-emp') : '', item = on ? tr.getAttribute('data-item') : '';
      regLinks.forEach(function (a) {
        var from = a.getAttribute('data-emp-from') === 'item' ? item : emp;
        a.setAttribute('href', withParam(a.getAttribute('data-base'), a.getAttribute('data-emp-link'), from));
        if (a.hasAttribute('data-needs-emp')) a.classList.toggle('disabled', !from);
      });
      if (regSel) regSel.textContent = on ? 'Selected: ' + tr.getAttribute('data-name') + ' (the buttons open this employee)' : regSel.getAttribute('data-empty');
    }
    $$('tr[data-emp]', regTable).forEach(function (tr) {
      tr.addEventListener('click', function (e) { if (!e.target.closest('a, button, input')) selectRow(tr); });
    });
    var pre = new URLSearchParams(location.search).get('emp');
    var preRow = pre ? $('tr[data-emp="' + pre + '"]', regTable) : null;
    if (preRow) { selectRow(preRow); preRow.scrollIntoView({ block: 'center' }); }
    else regLinks.forEach(function (a) { if (a.hasAttribute('data-needs-emp')) a.classList.add('disabled'); });
  }
  // a payroll run opened for one employee (?emp=) scrolls to that person's row
  var empAnchor = new URLSearchParams(location.search).get('emp');
  if (empAnchor && document.getElementById('emp-' + empAnchor) && !regTable) {
    document.getElementById('emp-' + empAnchor).scrollIntoView({ block: 'center' });
  }

  /* ------------------------------------------------------------ monthly-salaried: leaves reduce the hours work
     A container with data-perfect-hours (the single-entry forms and each batch grid row) holds the
     perfect attendance hours of the period. Whenever a leave field changes, hours work becomes
     perfect hours less all leave hours, never below zero. */
  var LEAVE_FIELDS = ['vl_hrs', 'sl_hrs', 'el_hrs', 'ol_hrs', 'pm_hrs'];
  function leaveRule(box) {
    var perfect = parseFloat(box.getAttribute('data-perfect-hours') || '0');
    if (!perfect) return;
    var find = function (f) { return box.querySelector('[name="' + f + '"], [data-field="' + f + '"]'); };
    var work = find('hours_work');
    if (!work || work.readOnly || work.disabled) return;
    var leaves = 0;
    LEAVE_FIELDS.forEach(function (f) { var i = find(f); if (i) leaves += parseFloat(i.value || '0') || 0; });
    var target = Math.max(0, Math.round((perfect - leaves) * 100) / 100);
    var cur = parseFloat(work.value || '0') || 0;
    if (leaves > 0 && Math.abs(cur - target) > 0.004) {
      work.value = target.toFixed(2);
      work.classList.remove('zero');
      work.dispatchEvent(new Event('input', { bubbles: true }));
      var tr = work.closest('tr'); if (tr) tr.classList.add('dirty');
    }
    var calc = box.querySelector('[data-perfect-calc]');
    if (calc) calc.textContent = perfect.toFixed(2) + (leaves ? ' less ' + leaves.toFixed(2) + ' leave hours = ' + target.toFixed(2) : ' less leaves');
  }
  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el.matches) return;
    var f = el.getAttribute('name') || el.getAttribute('data-field') || '';
    if (LEAVE_FIELDS.indexOf(f) === -1) return;
    var box = el.closest('[data-perfect-hours]');
    if (box) leaveRule(box);
  });
  $$('.pt-body[data-perfect-hours]').forEach(function (box) {
    var calc = box.querySelector('[data-perfect-calc]');
    if (!calc) return;
    var perfect = parseFloat(box.getAttribute('data-perfect-hours') || '0'), leaves = 0;
    LEAVE_FIELDS.forEach(function (f) { var i = box.querySelector('[name="' + f + '"]'); if (i) leaves += parseFloat(i.value || '0') || 0; });
    if (leaves) calc.textContent = perfect.toFixed(2) + ' less ' + leaves.toFixed(2) + ' leave hours = ' + Math.max(0, perfect - leaves).toFixed(2);
  });

  /* ------------------------------------------------------------ JSON grid submit (avoids max_input_vars) */
  $$('form[data-json-grid]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var payload = {};
      $$('[data-cell]', form).forEach(function (inp) {
        var row = inp.getAttribute('data-row'), field = inp.getAttribute('data-field');
        if (!payload[row]) payload[row] = {};
        payload[row][field] = inp.type === 'checkbox' ? (inp.checked ? 1 : 0) : inp.value;
      });
      var hidden = form.querySelector('input[name="payload"]');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden'; hidden.name = 'payload';
        form.appendChild(hidden);
      }
      hidden.value = JSON.stringify(payload);
      // the individual cells are not posted (keeps the request small)
      $$('[data-cell]', form).forEach(function (inp) { inp.disabled = true; });
      setTimeout(function () { $$('[data-cell]', form).forEach(function (inp) { inp.disabled = false; }); }, 4000);
    });
  });
  // only send changed rows when the grid says so
  $$('form[data-changed-only]').forEach(function (form) {
    form.addEventListener('submit', function () {
      $$('tbody tr', form).forEach(function (tr) {
        if (!tr.classList.contains('dirty')) {
          $$('input.cell, select.cell', tr).forEach(function (i) { i.disabled = true; });
        }
      });
    });
  });

  /* ------------------------------------------------------------ unsaved changes warning */
  window.addEventListener('beforeunload', function (e) {
    var dirty = $$('form[data-dirty="1"]').filter(function (f) { return !f.getAttribute('data-submitting'); });
    if (dirty.length) { e.preventDefault(); e.returnValue = ''; }
  });

  /* ------------------------------------------------------------ column-group visibility (View Column) */
  $$('[data-toggle-group]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var g = cb.getAttribute('data-toggle-group');
      var grid = document.getElementById(cb.getAttribute('data-grid') || '');
      $$('[data-col-group="' + g + '"]', grid || document).forEach(function (el) { el.style.display = cb.checked ? '' : 'none'; });
      try { localStorage.setItem('cols_' + g, cb.checked ? '1' : '0'); } catch (err) { /* ignore */ }
    });
    try {
      var v = localStorage.getItem('cols_' + cb.getAttribute('data-toggle-group'));
      if (v === '0') { cb.checked = false; cb.dispatchEvent(new Event('change')); }
    } catch (err) { /* ignore */ }
  });

  /* ------------------------------------------------------------ employee form: grade / step / pay group */
  var empForm = document.querySelector('form[data-steps]');
  if (empForm) {
    var steps = JSON.parse(empForm.getAttribute('data-steps') || '{}');
    var grade = empForm.querySelector('[name=salary_grade_id]');
    var step = empForm.querySelector('[name=salary_step]');
    var rate = empForm.querySelector('[name=basic_rate]');
    var rtype = empForm.querySelector('[name=rate_type]');
    var hint = document.getElementById('gradeHint');
    var fillSteps = function () {
      var g = steps[grade.value] || null;
      var cur = step.getAttribute('data-current') || step.value;
      step.innerHTML = '<option value="">—</option>';
      if (g) {
        Object.keys(g.steps).forEach(function (s) {
          var o = document.createElement('option');
          o.value = s; o.textContent = g.hidden ? 'Step ' + s : 'Step ' + s + ' — ₱' + Number(g.steps[s]).toLocaleString(undefined, { minimumFractionDigits: 2 });
          if (String(cur) === String(s)) o.selected = true;
          step.appendChild(o);
        });
      }
      step.removeAttribute('data-current');
      showHint();
    };
    var showHint = function () {
      var g = steps[grade.value];
      if (!hint) return;
      if (!g || g.hidden) { hint.textContent = ''; return; }
      var r = parseFloat((rate && rate.value) || '0');
      var monthly = rtype && rtype.value === 'daily' ? r * (parseFloat((empForm.querySelector('[name=days_per_year]') || {}).value || '261')) / 12 : r;
      var msg = 'Grade range: ₱' + Number(g.min).toLocaleString() + ' – ₱' + Number(g.max).toLocaleString() + ' / month.';
      if (monthly && (monthly < g.min || monthly > g.max)) msg += ' ⚠ Current monthly rate is outside the grade range.';
      hint.textContent = msg;
    };
    if (grade && step) {
      grade.addEventListener('change', fillSteps);
      step.addEventListener('change', function () {
        var g = steps[grade.value];
        if (g && !g.hidden && g.steps[step.value] && rate && !rate.disabled && rtype && rtype.value === 'monthly') { rate.value = g.steps[step.value]; }
        showHint();
      });
      if (rate) rate.addEventListener('input', showHint);
      fillSteps();
    }
    var ou = empForm.querySelector('[name=org_unit_id]');
    var pg = empForm.querySelector('[name=pay_group_id]');
    var ouPg = JSON.parse(empForm.getAttribute('data-ou-paygroup') || '{}');
    if (ou && pg) {
      ou.addEventListener('change', function () { if (ouPg[ou.value] && !pg.getAttribute('data-touched')) pg.value = ouPg[ou.value]; });
      pg.addEventListener('change', function () { pg.setAttribute('data-touched', '1'); });
    }
    // employee type -> days per year (conversion factor x 12)
    var etype = empForm.querySelector('[name=employee_type_id]');
    var dpy = empForm.querySelector('[name=days_per_year]');
    var factors = JSON.parse(empForm.getAttribute('data-type-factor') || '{}');
    if (etype && dpy) {
      etype.addEventListener('change', function () {
        if (factors[etype.value]) { dpy.value = Math.round(factors[etype.value] * 12 * 100) / 100; showHint(); }
      });
    }
  }

  /* ------------------------------------------------------------ evaluation KPI preview */
  document.addEventListener('input', function (e) {
    if (!e.target.closest('#kpiRows')) return;
    var tr = e.target.closest('tr');
    var t = parseFloat(tr.querySelector('[name="kpi_target[]"]').value);
    var a = parseFloat(tr.querySelector('[name="kpi_actual[]"]').value);
    var low = tr.querySelector('[name="kpi_lower_flag[]"]');
    var out = tr.querySelector('.ach');
    if (!out) return;
    if (!isNaN(t) && !isNaN(a) && t !== 0) {
      var pct = low && low.value === '1' ? (a <= 0 ? 150 : t / a * 100) : a / t * 100;
      out.textContent = Math.max(0, Math.min(150, pct)).toFixed(1) + '%';
    } else { out.textContent = '—'; }
  });

  /* ------------------------------------------------------------ import preview: fixed-width ruler */
  $$('[data-ruler]').forEach(function (box) {
    var src = document.getElementById(box.getAttribute('data-ruler'));
    if (!src) return;
    function draw() {
      var line = (src.value || '').split('\n')[0] || '';
      var ruler = '';
      for (var i = 1; i <= Math.max(60, line.length); i++) { ruler += (i % 10 === 0 ? String(i / 10 % 10) : (i % 5 === 0 ? '+' : '.')); }
      box.textContent = ruler;
    }
    src.addEventListener('input', draw);
    draw();
  });

  /* ------------------------------------------------------------ user manual: live filter
     Narrows the articles already on screen while typing. Pressing Enter still
     submits the form, which runs the full search inside the article text. */
  var manFind = document.getElementById('manFind');
  if (manFind && document.querySelector('[data-man-item]')) {
    var manEmpty = document.getElementById('manEmpty');
    var manJump = document.querySelector('.man-jump');
    var manFilter = function () {
      var terms = manFind.value.toLowerCase().split(/\s+/).filter(function (t) { return t.length > 1; });
      var hits = 0;
      $$('[data-man-item]').forEach(function (a) {
        var hay = a.textContent.toLowerCase();
        var on = terms.every(function (t) { return hay.indexOf(t) !== -1; });
        a.style.display = on ? '' : 'none';
        if (on) hits++;
      });
      $$('[data-man-section]').forEach(function (s) {
        var shown = $$('[data-man-item]', s).filter(function (a) { return a.style.display !== 'none'; }).length;
        s.style.display = shown ? '' : 'none';
      });
      if (manJump) manJump.style.display = terms.length ? 'none' : '';
      if (manEmpty) manEmpty.hidden = !(terms.length && !hits);
    };
    manFind.addEventListener('input', manFilter);
    if (manFind.value) manFilter();
  }

  /* ------------------------------------------------------------ copy to clipboard (Company Code) */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b) return;
    var src = document.getElementById(b.getAttribute('data-copy'));
    var text = src ? (src.value || src.textContent || '').trim() : '';
    if (!text) return;
    var done = function () { var t = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = t; }, 1400); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); }
    else {
      var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (err) {} document.body.removeChild(ta);
    }
  });

  /* ------------------------------------------------------------ payroll register: details of Others (double-click the header) */
  $$('table[data-reg-others]').forEach(function (t) {
    function apply() {
      ['e', 'd'].forEach(function (g) {
        var head = t.querySelector('th[data-grp="' + g + '"]');
        if (head) head.colSpan = parseInt(t.classList.contains('exp-' + g) ? head.getAttribute('data-span-exp') : head.getAttribute('data-span'), 10);
      });
      var exp = (t.classList.contains('exp-e') ? 'e' : '') + (t.classList.contains('exp-d') ? 'd' : '');
      $$('[data-expand-link]').forEach(function (a) {
        try {
          var u = new URL(a.getAttribute('href'), location.href);
          if (exp) u.searchParams.set('expand', exp); else u.searchParams.delete('expand');
          a.setAttribute('href', u.pathname.split('/').pop() + u.search);
        } catch (err) {}
      });
      try {
        var cur = new URL(location.href);
        if (exp) cur.searchParams.set('expand', exp); else cur.searchParams.delete('expand');
        history.replaceState(null, '', cur.pathname + cur.search + cur.hash);
      } catch (err) {}
    }
    t.addEventListener('dblclick', function (e) {
      var th = e.target.closest('th[data-oth]');
      if (!th) return;
      e.preventDefault();
      if (window.getSelection) { try { window.getSelection().removeAllRanges(); } catch (err) {} }
      t.classList.toggle('exp-' + th.getAttribute('data-oth'));
      apply();
    });
  });

  /* ------------------------------------------------------------ permissions: modes, role grid, user Inherit / Allow / Deny grid */
  var permModes = $$('[data-perm-mode]');
  if (permModes.length) {
    var showPermPanel = function () {
      var cur = (permModes.filter(function (m) { return m.checked; })[0] || {}).value;
      var superMap = {};
      try { superMap = JSON.parse(($('#roleSuperMap') || {}).textContent || '{}'); } catch (err) {}
      var rs = $('select[data-perm-role]');
      var isSuper = rs && superMap[rs.value] === 1;
      $$('[data-perm-panel]').forEach(function (p) { p.style.display = p.getAttribute('data-perm-panel') === cur && !isSuper ? '' : 'none'; });
    };
    permModes.forEach(function (m) { m.addEventListener('change', showPermPanel); });
    var rsel = $('select[data-perm-role]');
    if (rsel) rsel.addEventListener('change', showPermPanel);
    showPermPanel();
  }

  $$('table.perm-grid').forEach(function (grid) {
    var mode = grid.getAttribute('data-mode');
    var ro = grid.classList.contains('is-readonly');
    var rows = function (mod) { return $$('tr.pg-row[data-in="' + mod + '"]', grid); };
    var countEl = $('[data-perm-count="' + grid.id + '"]');
    var syncCount = function () {
      if (!countEl) return;
      var n = $$('input[name="perm[]"]:checked', grid).length;
      countEl.textContent = n + ' permission(s) ticked';
    };
    if (mode === 'role') {
      var syncHeads = function () {
        $$('input[data-mod-all]', grid).forEach(function (h) {
          var boxes = [];
          rows(h.getAttribute('data-mod-all')).forEach(function (tr) { var b = tr.querySelector('input[data-a="' + h.getAttribute('data-a') + '"]'); if (b) boxes.push(b); });
          var on = boxes.filter(function (b) { return b.checked; }).length;
          h.checked = boxes.length > 0 && on === boxes.length;
          h.indeterminate = on > 0 && on < boxes.length;
        });
        $$('tr.pg-row', grid).forEach(function (tr) { tr.classList.toggle('has-on', !!tr.querySelector('input[name="perm[]"]:checked')); });
        syncCount();
      };
      grid.addEventListener('change', function (e) {
        var t = e.target;
        if (t.hasAttribute('data-mod-all')) {
          rows(t.getAttribute('data-mod-all')).forEach(function (tr) {
            var b = tr.querySelector('input[data-a="' + t.getAttribute('data-a') + '"]');
            if (!b) return;
            b.checked = t.checked;
            var v = tr.querySelector('input[data-a="view"]');
            if (t.checked && v) v.checked = true;
            if (!t.checked && t.getAttribute('data-a') === 'view') $$('input[name="perm[]"]', tr).forEach(function (x) { x.checked = false; });
          });
        } else if (t.name === 'perm[]') {
          var tr = t.closest('tr');
          // an action needs View of its page; removing View removes the actions
          if (t.getAttribute('data-a') === 'view' && !t.checked) $$('input[name="perm[]"]', tr).forEach(function (x) { x.checked = false; });
          if (t.getAttribute('data-a') !== 'view' && t.checked) { var v2 = tr.querySelector('input[data-a="view"]'); if (v2) v2.checked = true; }
        }
        syncHeads();
      });
      syncHeads();
    } else if (mode === 'user') {
      var order = ['inherit', 'allow', 'deny'];
      var setState = function (btn, st) {
        order.forEach(function (o) { btn.classList.remove('st-' + o); });
        btn.classList.add('st-' + st);
        var inp = btn.parentNode.querySelector('input[type=hidden]');
        if (inp) inp.value = st === 'inherit' ? '' : st;
        var on = btn.classList.contains('on');
        var eff = st === 'allow' || (st === 'inherit' && on);
        btn.setAttribute('aria-pressed', eff ? 'true' : 'false');
        btn.textContent = st === 'deny' ? '✕' : (eff ? '✓' : '–');
        btn.title = btn.getAttribute('data-k') + ': ' + (st === 'inherit' ? 'inherited from role (' + (on ? 'allowed' : 'denied') + ')' : (st === 'allow' ? 'custom allow' : 'custom deny'));
      };
      var stateOf = function (btn) { return btn.classList.contains('st-allow') ? 'allow' : (btn.classList.contains('st-deny') ? 'deny' : 'inherit'); };
      var refreshRows = function () {
        $$('tr.pg-row', grid).forEach(function (tr) {
          var any = false;
          $$('button.tri[data-k]', tr).forEach(function (b) { var st = stateOf(b); if (st === 'allow' || (st === 'inherit' && b.classList.contains('on'))) any = true; });
          tr.classList.toggle('has-on', any);
        });
      };
      $$('button.tri[data-k]', grid).forEach(function (b) { setState(b, stateOf(b)); });
      refreshRows();
      if (!ro) {
        grid.addEventListener('click', function (e) {
          var b = e.target.closest('button.tri');
          if (!b) return;
          e.preventDefault();
          if (b.hasAttribute('data-k')) {
            setState(b, order[(order.indexOf(stateOf(b)) + 1) % 3]);
          } else if (b.hasAttribute('data-mod-all')) {
            var targets = [];
            rows(b.getAttribute('data-mod-all')).forEach(function (tr) { var x = tr.querySelector('button.tri[data-a="' + b.getAttribute('data-a') + '"]'); if (x) targets.push(x); });
            var next = targets.length ? order[(order.indexOf(stateOf(targets[0])) + 1) % 3] : 'inherit';
            targets.forEach(function (x) { setState(x, next); });
          }
          refreshRows();
        });
      }
      // the role chosen on the same form decides what "inherit" means
      var roles = {};
      try { roles = JSON.parse(grid.getAttribute('data-roles') || '{}'); } catch (err) {}
      var rs = $('select[data-perm-role="' + grid.id + '"]');
      if (rs) rs.addEventListener('change', function () {
        var keys = {};
        (roles[rs.value] || []).forEach(function (k) { keys[k] = true; });
        $$('button.tri[data-k]', grid).forEach(function (b) { b.classList.toggle('on', !!keys[b.getAttribute('data-k')]); setState(b, stateOf(b)); });
        refreshRows();
      });
    }
  });

  // find a page and "only pages with access" in permission grids
  var permFilter = function (id) {
    var grid = document.getElementById(id);
    if (!grid) return;
    var f = $('[data-perm-find="' + id + '"]');
    var only = $('[data-perm-onlyon="' + id + '"]');
    var q = f ? f.value.trim().toLowerCase() : '';
    var on = only && only.checked;
    $$('tr.pg-row', grid).forEach(function (tr) {
      var show = (!q || tr.textContent.toLowerCase().indexOf(q) >= 0) && (!on || tr.classList.contains('has-on'));
      tr.style.display = show ? '' : 'none';
    });
    $$('tr.pg-mod', grid).forEach(function (m) {
      var vis = $$('tr.pg-row[data-in="' + m.getAttribute('data-mod') + '"]', grid).some(function (r) { return r.style.display !== 'none'; });
      m.style.display = vis ? '' : 'none';
    });
  };
  $$('[data-perm-find]').forEach(function (f) { f.addEventListener('input', function () { permFilter(f.getAttribute('data-perm-find')); }); });
  $$('[data-perm-onlyon]').forEach(function (c) { c.addEventListener('change', function () { permFilter(c.getAttribute('data-perm-onlyon')); }); permFilter(c.getAttribute('data-perm-onlyon')); });

  // Role Management: load the permissions of another role into the form
  $$('[data-copy-role]').forEach(function (b) {
    b.addEventListener('click', function () {
      var s = document.getElementById('copyFrom');
      if (!s || !s.value) return;
      location.href = b.getAttribute('data-copy-role') + '&copy_from=' + encodeURIComponent(s.value);
    });
  });

  /* ------------------------------------------------------------ repeatable rows (201: children, education, previous employers) */
  document.addEventListener('click', function (e) {
    var add = e.target.closest('[data-repeat-add]');
    if (add) {
      var id = add.getAttribute('data-repeat-add');
      var body = document.getElementById(id), tpl = document.getElementById(id + '-tpl');
      if (body && tpl) {
        var frag = tpl.content.cloneNode(true);
        body.appendChild(frag);
        var last = body.lastElementChild, first = last && last.querySelector('input, select');
        if (first) first.focus();
      }
      return;
    }
    var rm = e.target.closest('[data-repeat-remove]');
    if (rm) {
      var row = rm.closest('[data-repeat-row]');
      if (row) row.parentNode.removeChild(row);
    }
  });

  /* ------------------------------------------------------------ employee picker: one, many or all */
  $$('[data-emp-pick]').forEach(function (box) {
    var radios = $$('input[name="emp_scope"]', box.closest('form') || document);
    var sync = function () {
      var cur = (radios.filter(function (r) { return r.checked; })[0] || {}).value;
      box.classList.toggle('is-off', cur === 'all');
      $$('input[type=checkbox]', box).forEach(function (c) { c.disabled = cur === 'all'; });
    };
    radios.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  });
})();

/* report type tiles: keep the chosen report visible on a phone (the row scrolls sideways) */
(function () {
  document.querySelectorAll('.rpt-types').forEach(function (row) {
    var on = row.querySelector('.is-on');
    if (on && row.scrollWidth > row.clientWidth) row.scrollLeft += on.getBoundingClientRect().left - row.getBoundingClientRect().left - 16;
  });
})();

/* Biometric Device Setup: show the fields of the chosen connection method */
(function () {
  var radios = document.querySelectorAll('[data-bio-method]');
  if (!radios.length) return;
  function sync() {
    var m = (document.querySelector('[data-bio-method]:checked') || {}).value || 'native';
    document.querySelectorAll('[data-bio-only]').forEach(function (el) {
      el.style.display = el.getAttribute('data-bio-only').split(' ').indexOf(m) >= 0 ? '' : 'none';
    });
  }
  radios.forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();

/* Employee 201 File Masterlisting: choose and order the columns */
(function () {
  var order = document.getElementById('mlOrder');
  var hidden = document.getElementById('mlCols');
  if (!order || !hidden) return;
  function keys() { return Array.prototype.map.call(order.querySelectorAll('li'), function (li) { return li.getAttribute('data-key'); }); }
  function sync() {
    var k = keys();
    hidden.value = k.join(',');
    document.querySelectorAll('[data-ml-cols-copy]').forEach(function (i) { i.value = hidden.value; });
    document.querySelectorAll('[data-ml-col]').forEach(function (cb) { cb.checked = k.indexOf(cb.value) >= 0; });
  }
  function item(key, label) {
    var li = document.createElement('li');
    li.setAttribute('data-key', key);
    li.innerHTML = '<span></span><button type="button" class="icon-btn" data-ml-move="-1" aria-label="Move up">&uarr;</button>'
      + '<button type="button" class="icon-btn" data-ml-move="1" aria-label="Move down">&darr;</button>'
      + '<button type="button" class="icon-btn" data-ml-remove aria-label="Remove">&times;</button>';
    li.querySelector('span').textContent = label;
    return li;
  }
  document.addEventListener('change', function (e) {
    var cb = e.target.closest('[data-ml-col]');
    if (!cb) return;
    var li = order.querySelector('li[data-key="' + cb.value + '"]');
    if (cb.checked && !li) order.appendChild(item(cb.value, cb.getAttribute('data-label')));
    if (!cb.checked && li) {
      if (order.querySelectorAll('li').length <= 1) { cb.checked = true; return; }
      li.remove();
    }
    sync();
  });
  order.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) return;
    var li = b.closest('li');
    if (b.hasAttribute('data-ml-remove')) {
      if (order.querySelectorAll('li').length > 1) li.remove();
    } else {
      var d = parseInt(b.getAttribute('data-ml-move'), 10);
      if (d < 0 && li.previousElementSibling) order.insertBefore(li, li.previousElementSibling);
      if (d > 0 && li.nextElementSibling) order.insertBefore(li.nextElementSibling, li);
    }
    sync();
  });
  document.querySelectorAll('[data-ml-reset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var want = b.getAttribute('data-ml-reset').split(',');
      order.innerHTML = '';
      want.forEach(function (k) {
        var cb = document.querySelector('[data-ml-col][value="' + k + '"]');
        if (cb) order.appendChild(item(k, cb.getAttribute('data-label')));
      });
      sync();
    });
  });
  var find = document.getElementById('mlFind');
  if (find) find.addEventListener('input', function () {
    var q = find.value.trim().toLowerCase();
    document.querySelectorAll('.ml-group-box').forEach(function (box) {
      var any = false;
      box.querySelectorAll('label').forEach(function (l) {
        var on = !q || l.textContent.toLowerCase().indexOf(q) >= 0;
        l.style.display = on ? '' : 'none';
        if (on) any = true;
      });
      box.style.display = any ? '' : 'none';
    });
  });
  sync();
})();

/* CSV import facility on the forms: corrections typed on the grid and removed rows go with Check data / Save */
(function () {
  document.querySelectorAll('form[data-dimp-grid]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var edits = {}, remove = [];
      form.querySelectorAll('input[data-k]').forEach(function (inp) {
        if (inp.value !== inp.defaultValue) {
          var r = inp.getAttribute('data-r');
          (edits[r] = edits[r] || {})[inp.getAttribute('data-k')] = inp.value;
        }
        inp.disabled = true;
      });
      form.querySelectorAll('input[data-remove]').forEach(function (cb) {
        if (cb.checked) remove.push(parseInt(cb.getAttribute('data-remove'), 10));
        cb.disabled = true;
      });
      form.querySelector('input[name=dimp_edits]').value = JSON.stringify(edits);
      form.querySelector('input[name=dimp_remove]').value = JSON.stringify(remove);
      setTimeout(function () { form.querySelectorAll('input[data-k], input[data-remove]').forEach(function (i) { i.disabled = false; }); }, 4000);
    });
    form.addEventListener('input', function (e) {
      var td = e.target.closest('td.cell-bad');
      if (td && e.target.value !== e.target.defaultValue) td.classList.add('cell-fixed');
    });
  });
})();

/* v12: New / Edit a referenced record (GL account ...) from the form that uses it (app/engine/quickref.php) */
(function () {
  'use strict';
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var current = null;   // the select the form was opened from

  function data(m) {
    var s = m.querySelector('script.qr-data');
    try { return JSON.parse(s.textContent || '[]'); } catch (e) { return []; }
  }
  function setData(m, list) { m.querySelector('script.qr-data').textContent = JSON.stringify(list); }
  function msg(m, text, ok) {
    var b = m.querySelector('.qr-msg');
    if (!text) { b.hidden = true; b.textContent = ''; return; }
    b.hidden = false; b.className = 'qr-msg alert ' + (ok ? 'alert-success' : 'alert-error');
    b.innerHTML = '';
    (Array.isArray(text) ? text : [text]).forEach(function (t) { var d = document.createElement('div'); d.textContent = t; b.appendChild(d); });
  }
  function fill(form, rec) {
    $$('input, select, textarea', form).forEach(function (el) {
      if (!el.name || el.type === 'hidden') return;
      var v = rec && Object.prototype.hasOwnProperty.call(rec, el.name) ? rec[el.name] : el.getAttribute('data-default');
      if (el.type === 'checkbox') el.checked = String(v) === '1' || v === true;
      else el.value = v == null ? '' : v;
    });
  }
  function toast(text) {
    var t = document.createElement('div');
    t.className = 'qr-toast'; t.setAttribute('role', 'status'); t.textContent = text;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('show'); }, 10);
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 4200);
  }

  // remember the default value of every field of the forms
  $$('.qr-modal form').forEach(function (form) {
    $$('input, select, textarea', form).forEach(function (el) {
      if (!el.name || el.type === 'hidden') return;
      el.setAttribute('data-default', el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value);
    });
  });

  function syncButtons(sel) {
    var wrap = sel.closest('.qr-field');
    if (!wrap) return;
    var e = wrap.querySelector('[data-qr-mode="edit"]');
    if (e) e.disabled = !sel.value;
  }
  $$('select[data-qr]').forEach(function (sel) {
    syncButtons(sel);
    sel.addEventListener('change', function () { syncButtons(sel); });
  });

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-qr-open]');
    if (!b) return;
    e.preventDefault();
    var kind = b.getAttribute('data-qr-open');
    var m = document.getElementById('qr-' + kind);
    var wrap = b.closest('.qr-field');
    current = wrap ? wrap.querySelector('select[data-qr]') : null;
    if (!m) return;
    var form = m.querySelector('form');
    var key = m.getAttribute('data-qr-key');
    var title = m.getAttribute('data-qr-title');
    var edit = b.getAttribute('data-qr-mode') === 'edit';
    var rec = null;
    if (edit) {
      var v = current ? current.value : '';
      data(m).forEach(function (r) { if (String(r[key]) === v) rec = r; });
      if (!rec) { toast('Choose the ' + title + ' to edit first.'); return; }
    }
    fill(form, rec);
    form.querySelector('[name="_action"]').value = edit ? 'quick_edit' : 'quick_add';
    form.querySelector('[name="id"]').value = rec ? rec.id : 0;
    m.querySelector('h2').textContent = edit ? 'Edit ' + title + ' ' + rec[key] : 'New ' + title;
    msg(m, null);
    m.classList.add('open');
    var first = form.querySelector('input:not([type=hidden]):not([type=checkbox])');
    if (first) setTimeout(function () { first.focus(); first.select && first.select(); }, 60);
  });

  document.addEventListener('submit', function (e) {
    var form = e.target.closest('.qr-form');
    if (!form) return;
    e.preventDefault();
    var m = form.closest('.qr-modal');
    var key = m.getAttribute('data-qr-key');
    var kind = m.getAttribute('data-qr-kind');
    var btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    msg(m, null);
    fetch(form.getAttribute('action'), {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    }).then(function (r) {
      return r.text().then(function (t) {
        try { return JSON.parse(t); } catch (x) {
          return { ok: false, errors: [r.status === 403 ? 'You are not allowed to change this table.' : (r.status === 419 ? 'The page has expired. Reload it and try again.' : 'The record could not be saved (' + r.status + ').')] };
        }
      });
    }).then(function (res) {
      btn.disabled = false;
      form.removeAttribute('data-submitting');
      if (!res.ok) { msg(m, res.errors || ['Not saved.'], false); return; }
      var rec = res.record, nk = String(rec[key]), ok = res.old_key;
      var list = data(m).filter(function (r) { return r.id !== rec.id; });
      list.push(rec);
      list.sort(function (a, b) { return String(a[key]).localeCompare(String(b[key]), undefined, { numeric: true }); });
      setData(m, list);
      var label = rec._label + (String(rec.is_active) === '0' ? ' (inactive)' : '');
      $$('select[data-qr="' + kind + '"]').forEach(function (sel) {
        var opt = null;
        $$('option', sel).forEach(function (o) { if (o.value === (ok || nk) || o.value === nk) opt = opt || o; });
        if (!opt) {
          if (String(rec.is_active) === '0' && sel !== current) return;
          opt = document.createElement('option');
          var before = null;
          $$('option', sel).forEach(function (o) { if (!before && o.value !== '' && o.value.localeCompare(nk, undefined, { numeric: true }) > 0) before = o; });
          sel.insertBefore(opt, before);
        }
        opt.value = nk; opt.textContent = label;
      });
      if (current) {
        current.value = nk;
        current.dispatchEvent(new Event('change', { bubbles: true }));
      }
      m.classList.remove('open');
      toast(res.message || 'Saved.');
    }).catch(function () {
      btn.disabled = false;
      msg(m, ['The server could not be reached. Check the connection and try again.'], false);
    });
  });
})();

/* v13: employee assignment of a user (User Access & Permissions) */
(function () {
  'use strict';
  var wrap = document.querySelector('[data-scope-panels]');
  if (!wrap) return;
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var sum = document.getElementById('scopeSummary');
  function side(key) {
    var all = wrap.querySelector('[data-scope-all="' + key + '"]');
    var list = wrap.querySelector('[data-scope-list="' + key + '"]');
    var n = $$('input[type=checkbox]:checked', list).length;
    list.classList.toggle('is-muted', !!(all && all.checked));
    return { all: !!(all && all.checked), n: n };
  }
  function refresh() {
    var b = side('branches'), u = side('units');
    if (!sum || sum.getAttribute('data-super')) return;
    var none = !b.all && !u.all && !b.n && !u.n;
    var txt;
    if (none) txt = 'No employee: no company / branch or department assigned';
    else {
      var bt = b.all || !b.n ? 'all companies / branches' : b.n + ' company / branch(es)';
      var ut = u.all || !u.n ? 'all departments' : u.n + ' department(s)';
      txt = bt.charAt(0).toUpperCase() + bt.slice(1) + ', ' + ut;
    }
    var now = sum.querySelector('.scope-now');
    if (now && !now.hasAttribute('data-fixed')) now.textContent = txt;
    sum.classList.toggle('is-none', none);
  }
  wrap.addEventListener('change', refresh);
  wrap.addEventListener('click', function (e) {
    var t = e.target.closest('[data-scope-tick],[data-scope-clear]');
    if (!t) return;
    var key = t.getAttribute('data-scope-tick') || t.getAttribute('data-scope-clear');
    var on = t.hasAttribute('data-scope-tick');
    $$('[data-scope-list="' + key + '"] input[type=checkbox]:not(:disabled)', wrap).forEach(function (c) { c.checked = on; });
    refresh();
  });
  refresh();
})();

/* v14: narrow a long select list as you type; Earning / Deduction indicator of a code select */
(function () {
  'use strict';
  Array.prototype.slice.call(document.querySelectorAll('[data-filter-select]')).forEach(function (inp) {
    var sel = document.getElementById(inp.getAttribute('data-filter-select'));
    if (!sel) return;
    inp.addEventListener('input', function () {
      var q = inp.value.toLowerCase().trim();
      Array.prototype.slice.call(sel.options).forEach(function (o) {
        var hit = !q || o.selected || o.text.toLowerCase().indexOf(q) !== -1;
        o.hidden = !hit;
        o.style.display = hit ? '' : 'none';
      });
    });
  });
  Array.prototype.slice.call(document.querySelectorAll('select[data-ed-indicator]')).forEach(function (sel) {
    var out = sel.parentNode.querySelector('.ed-indicator');
    var show = function () {
      var o = sel.options[sel.selectedIndex];
      var t = o ? o.getAttribute('data-type') : '';
      if (!out) return;
      out.className = 'help ed-indicator ' + (t === 'earning' ? 'is-earning' : t === 'deduction' ? 'is-deduction' : '');
      out.textContent = t === 'earning' ? 'Earning: added to the pay of the employee.' : t === 'deduction' ? 'Deduction: taken from the pay of the employee.' : '';
    };
    sel.addEventListener('change', show);
    show();
  });
})();

/* v14: count / clear of the multi-select filters (Payroll Computation) */
(function () {
  'use strict';
  Array.prototype.slice.call(document.querySelectorAll('select[data-pick-count]')).forEach(function (sel) {
    var out = document.querySelector('[data-count-for="' + sel.id + '"]');
    var all = out ? out.textContent : '';
    if (out && /selected$/.test(all)) all = 'All';
    var upd = function () {
      var n = Array.prototype.slice.call(sel.options).filter(function (o) { return o.selected; }).length;
      if (out) out.textContent = n ? n + ' selected' : (out.getAttribute('data-all') || all);
    };
    if (out && !/selected$/.test(out.textContent)) out.setAttribute('data-all', out.textContent);
    sel.addEventListener('change', upd);
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-clear-select]');
    if (!b) return;
    var sel = document.getElementById(b.getAttribute('data-clear-select'));
    if (!sel) return;
    Array.prototype.slice.call(sel.options).forEach(function (o) { o.selected = false; });
    sel.dispatchEvent(new Event('change'));
  });
})();

/* v14: "All reports" of the company logo setting */
(function () {
  'use strict';
  var box = document.querySelector('[data-logo-reports]');
  if (!box) return;
  var all = box.querySelector('[data-logo-all]');
  var items = Array.prototype.slice.call(box.querySelectorAll('input[name="logo_reports[]"]'));
  all.addEventListener('change', function () { items.forEach(function (c) { c.checked = all.checked; }); });
  items.forEach(function (c) { c.addEventListener('change', function () { if (!c.checked) all.checked = false; else if (items.every(function (x) { return x.checked; })) all.checked = true; }); });
})();

/* v14: rich-text editor of the memoranda (bold, lists, alignment, table, placeholders) */
(function () {
  'use strict';
  Array.prototype.slice.call(document.querySelectorAll('[data-rte]')).forEach(function (box) {
    var area = box.querySelector('[data-rte-area]');
    var field = document.getElementById(box.getAttribute('data-rte'));
    if (!area || !field) return;
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* older browsers */ }
    var sync = function () { field.value = area.innerHTML; field.dispatchEvent(new Event('input', { bubbles: true })); };
    area.addEventListener('input', sync);
    area.addEventListener('blur', sync);
    box.addEventListener('mousedown', function (e) { if (e.target.closest('[data-rte-cmd]')) e.preventDefault(); });
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-rte-cmd]');
      if (!b) return;
      var cmd = b.getAttribute('data-rte-cmd');
      area.focus();
      if (cmd === 'table') {
        document.execCommand('insertHTML', false, '<table><tbody><tr><th>Column 1</th><th>Column 2</th><th>Column 3</th></tr><tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr><tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr></tbody></table><p><br></p>');
      } else {
        document.execCommand(cmd, false, b.getAttribute('data-rte-arg') || null);
      }
      sync();
    });
    var block = box.querySelector('[data-rte-block]');
    if (block) block.addEventListener('change', function () { area.focus(); document.execCommand('formatBlock', false, '<' + block.value + '>'); block.value = 'p'; sync(); });
    var ins = box.querySelector('[data-rte-insert]');
    if (ins) ins.addEventListener('change', function () { if (!ins.value) return; area.focus(); document.execCommand('insertText', false, ins.value); ins.value = ''; sync(); });
    var form = box.closest('form');
    if (form) form.addEventListener('submit', sync);
    // paste as plain paragraphs (keeps the memo clean)
    area.addEventListener('paste', function (e) {
      var t = (e.clipboardData || window.clipboardData);
      if (!t) return;
      var html = t.getData('text/html');
      if (html) return;   // formatted text: the server keeps only the safe formatting
      e.preventDefault();
      var txt = t.getData('text/plain') || '';
      var esc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
      document.execCommand('insertHTML', false, txt.split(/\n{2,}/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join(''));
      sync();
    });
  });

  /* live preview of a memo being edited ([data-memo-preview] holds the document) */
  var form = document.querySelector('form[data-memo-form]');
  var prev = document.querySelector('[data-memo-preview]');
  if (form && prev) {
    var ctx = {};
    try { ctx = JSON.parse(prev.getAttribute('data-ctx') || '{}'); } catch (e) { ctx = {}; }
    var val = function (n) { var el = form.querySelector('[name="' + n + '"]'); return el ? el.value : ''; };
    var esc = function (s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
    var fmtDate = function (d) { if (!d) return ''; var x = new Date(d + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }); };
    var fill = function (s, html) {
      var co = ctx.companies && ctx.companies[val('branch_id')] ? ctx.companies[val('branch_id')] : ctx.company;
      var map = { '{company}': co ? co.name : '', '{company_address}': co ? co.address : '', '{date}': fmtDate(val('memo_date')), '{memo_no}': ctx.memo_no || '',
        '{to}': val('to_line'), '{from}': val('from_line'), '{subject}': val('subject'), '{signatory}': val('signatory_name'), '{signatory_title}': val('signatory_title'), '{year}': (val('memo_date') || '').slice(0, 4) };
      return String(s || '').replace(/\{[a-z_]+\}/g, function (k) { return map.hasOwnProperty(k) ? (html ? esc(map[k]) : map[k]) : k; });
    };
    var render = function () {
      var doc = prev.querySelector('.memo-doc');
      if (!doc) return;
      var kind = val('kind') === 'announcement' ? 'ANNOUNCEMENT' : 'MEMORANDUM';
      var t = doc.querySelector('.memo-title'); if (t) t.textContent = kind;
      var co = ctx.companies && ctx.companies[val('branch_id')] ? ctx.companies[val('branch_id')] : ctx.company;
      var cn = doc.querySelector('.memo-co'); if (cn && co) cn.textContent = co.name;
      var ca = doc.querySelector('.memo-addr'); if (ca && co) ca.textContent = co.address;
      var rows = [['To', val('to_line')], ['From', val('from_line')], ['CC', val('cc_line')], ['Date', fmtDate(val('memo_date'))], ['Subject', val('subject')]];
      var tb = doc.querySelector('.memo-fields');
      if (tb) tb.innerHTML = rows.filter(function (r) { return !(r[0] === 'CC' && !r[1].trim()) && !(r[0] === 'To' && !r[1].trim() && val('kind') === 'announcement'); })
        .map(function (r) { return '<tr><th>' + r[0] + '</th><td>:</td><td' + (r[0] === 'Subject' ? ' class="memo-subject"' : '') + '>' + esc(fill(r[1], false)) + '</td></tr>'; }).join('');
      var body = doc.querySelector('.memo-body'); var area = form.querySelector('[data-rte-area]');
      if (body && area) body.innerHTML = fill(area.innerHTML, true);
      var sg = doc.querySelector('.memo-sign');
      if (sg) { sg.style.display = val('signatory_name').trim() ? '' : 'none'; sg.querySelector('.memo-sign-name').textContent = val('signatory_name'); sg.querySelector('.memo-sign-title').textContent = val('signatory_title'); }
      var ack = doc.querySelector('.memo-ack'); if (ack) ack.style.display = val('kind') === 'announcement' ? 'none' : '';
    };
    form.addEventListener('input', render);
    form.addEventListener('change', render);
    render();
  }

  /* template picker of the memo form: fills kind, subject and body */
  var pick = document.querySelector('[data-memo-template]');
  if (pick && form) {
    var tpls = {};
    try { tpls = JSON.parse(document.getElementById('memoTemplates').textContent || '{}'); } catch (e) { tpls = {}; }
    pick.addEventListener('change', function () {
      var t = tpls[pick.value];
      if (!t) return;
      var area = form.querySelector('[data-rte-area]');
      var has = area && area.textContent.trim().length > 0;
      if (has && !window.confirm('Replace the subject and the text with the template "' + t.name + '"?')) { return; }
      form.querySelector('[name=kind]').value = t.kind;
      form.querySelector('[name=subject]').value = t.subject;
      if (area) { area.innerHTML = t.body; area.dispatchEvent(new Event('input')); }
      form.dispatchEvent(new Event('change'));
    });
  }
})();

/* =====================================================================
   v16: incremental searching
   - Employee selects become a type-to-search box (combobox): the list narrows with every letter
     typed (name, employee no.), arrows + Enter pick, the original select keeps the value.
   - Multi-employee selects become a pick list: a search box, tick one, two or more employees,
     "Select all shown", "Clear", "Show selected only" and the count of the employees picked.
   - form[data-live="#a,#b"]: the search box and the selects of a filter form refresh the listed
     regions while typing (no Apply needed); the address keeps the filters, so saving a form of
     the page returns to the same filters.
   ===================================================================== */
(function () {
  'use strict';
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var fold = function (s) {
    s = String(s || '').toLowerCase();
    return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
  };
  var tokens = function (q) { return fold(q).split(/[\s,]+/).filter(Boolean); };
  var hits = function (hay, toks) {
    if (!toks.length) return true;
    hay = fold(hay);
    for (var i = 0; i < toks.length; i++) { if (hay.indexOf(toks[i]) === -1) return false; }
    return true;
  };
  window.ihrisMatch = function (hay, q) { return hits(hay, tokens(q)); };

  var COMBO = 'select[data-combo], select[name=employee_id], select[name=emp], select[name=emp_id], select[name=supervisor_id], select[name=head_employee_id], select[name=evaluator_employee_id]';
  var PICK = 'select[multiple][data-picklist], select[multiple][name="employee_ids[]"]';

  /* ------------------------------------------------------------ combobox (one employee) */
  function combo(sel) {
    if (sel.multiple || sel.hasAttribute('data-no-combo') || sel.closest('.combo')) return;
    var wrap = document.createElement('div');
    wrap.className = 'combo';
    var inp = document.createElement('input');
    inp.type = 'text';
    inp.className = 'combo-input';
    inp.setAttribute('autocomplete', 'off');
    inp.setAttribute('role', 'combobox');
    inp.setAttribute('aria-expanded', 'false');
    inp.setAttribute('aria-label', sel.getAttribute('aria-label') || 'Search an employee');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'combo-toggle';
    btn.tabIndex = -1;
    btn.setAttribute('aria-label', 'Show the list');
    var list = document.createElement('div');
    list.className = 'combo-list';
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(inp);
    wrap.appendChild(btn);
    wrap.appendChild(list);
    wrap.appendChild(sel);
    sel.classList.add('combo-native');
    sel.tabIndex = -1;
    if (sel.id) { var lb = document.querySelector('label[for="' + sel.id + '"]'); if (lb) lb.setAttribute('for', ''); }
    var active = -1, shown = [], open = false;
    var current = function () { return sel.options[sel.selectedIndex] || null; };
    var showValue = function () {
      var o = current();
      var empty = sel.querySelector('option[value=""]');
      inp.placeholder = empty ? empty.text : 'Type a name or employee no.';
      inp.value = o && o.value !== '' ? o.text : '';
      inp.disabled = sel.disabled;
      wrap.classList.toggle('is-disabled', sel.disabled);
    };
    var render = function (q) {
      var toks = tokens(q);
      shown = [];
      var html = '', more = 0;
      Array.prototype.forEach.call(sel.options, function (o, i) {
        if (o.disabled && o.value === '') return;
        if (o.value !== '' && !hits(o.text, toks)) return;
        if (o.value === '' && toks.length) return;
        if (shown.length >= 250) { more++; return; }
        shown.push(i);
        html += '<div class="combo-opt' + (o.value === '' ? ' is-empty' : '') + (i === sel.selectedIndex ? ' is-sel' : '') + '" role="option" data-i="' + i + '">' + esc(o.text) + '</div>';
      });
      if (!shown.length) html = '<div class="combo-none">No employee matches "' + esc(q) + '"</div>';
      if (more) html += '<div class="combo-none">' + more + ' more, keep typing to narrow the list</div>';
      list.innerHTML = html;
      active = -1;
      for (var k = 0; k < shown.length; k++) { if (shown[k] === sel.selectedIndex && !toks.length) { active = k; break; } }
      if (active < 0 && toks.length && shown.length) active = 0;
      mark();
    };
    var mark = function () {
      $$('.combo-opt', list).forEach(function (el, k) { el.classList.toggle('is-active', k === active); });
      var a = list.querySelector('.combo-opt.is-active');
      if (a) { var top = a.offsetTop, bot = top + a.offsetHeight; if (top < list.scrollTop) list.scrollTop = top; else if (bot > list.scrollTop + list.clientHeight) list.scrollTop = bot - list.clientHeight; }
    };
    var show = function (q) {
      if (sel.disabled) return;
      render(q || '');
      list.hidden = false;
      open = true;
      wrap.classList.add('is-open');
      inp.setAttribute('aria-expanded', 'true');
      var r = wrap.getBoundingClientRect();
      wrap.classList.toggle('drop-up', window.innerHeight - r.bottom < 240 && r.top > 300);
    };
    var hide = function (revert) {
      list.hidden = true;
      open = false;
      wrap.classList.remove('is-open');
      inp.setAttribute('aria-expanded', 'false');
      if (revert) showValue();
    };
    var choose = function (i) {
      var changed = sel.selectedIndex !== i;
      sel.selectedIndex = i;
      hide(true);
      wrap.classList.remove('is-invalid');
      if (changed) sel.dispatchEvent(new Event('change', { bubbles: true }));
    };
    inp.addEventListener('focus', function () { inp.select(); show(''); });
    inp.addEventListener('click', function () { if (!open) show(''); });
    inp.addEventListener('input', function () { show(inp.value); });
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!open) { show(''); return; }
        if (!shown.length) return;
        active = e.key === 'ArrowDown' ? Math.min(shown.length - 1, active + 1) : Math.max(0, active - 1);
        mark();
      } else if (e.key === 'Enter') {
        if (open && active >= 0 && shown[active] !== undefined) { e.preventDefault(); choose(shown[active]); }
        else if (open) { e.preventDefault(); }
      } else if (e.key === 'Escape') {
        if (open) { e.preventDefault(); e.stopPropagation(); hide(true); }
      } else if (e.key === 'Tab' && open) {
        if (inp.value.trim() && active >= 0 && shown[active] !== undefined && tokens(inp.value).length) choose(shown[active]); else hide(true);
      }
    });
    inp.addEventListener('blur', function () { setTimeout(function () { if (open && !wrap.contains(document.activeElement)) hide(true); }, 150); });
    list.addEventListener('mousedown', function (e) {
      e.preventDefault();
      var o = e.target.closest('.combo-opt');
      if (o) choose(parseInt(o.getAttribute('data-i'), 10));
    });
    btn.addEventListener('mousedown', function (e) { e.preventDefault(); if (open) hide(true); else { inp.focus(); } });
    sel.addEventListener('change', function () { if (!open) showValue(); });
    sel.addEventListener('invalid', function () { wrap.classList.add('is-invalid'); setTimeout(function () { inp.focus(); }, 0); });
    sel.addEventListener('live:updated', showValue);
    document.addEventListener('mousedown', function (e) { if (open && !wrap.contains(e.target)) hide(true); });
    showValue();
  }

  /* ------------------------------------------------------------ pick list (one, two or more employees) */
  function picklist(sel) {
    if (!sel.multiple || sel.hasAttribute('data-no-picklist') || sel.closest('.picklist')) return;
    var req = sel.required;
    sel.required = false;
    var wrap = document.createElement('div');
    wrap.className = 'picklist';
    wrap.innerHTML = '<div class="pl-head"><input type="search" class="pl-find" placeholder="Type a name or employee no." aria-label="Search employees">'
      + '<span class="pl-count" aria-live="polite"></span></div>'
      + '<div class="pl-tools"><button type="button" class="pl-btn" data-pl-all>Select all shown</button><button type="button" class="pl-btn" data-pl-none>Clear</button>'
      + '<label class="check pl-only"><input type="checkbox" data-pl-only> Show selected only</label></div>'
      + '<div class="pl-box" role="listbox" aria-multiselectable="true"></div><div class="pl-msg" hidden></div>';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);
    sel.classList.add('combo-native');
    sel.tabIndex = -1;
    var find = wrap.querySelector('.pl-find'), box = wrap.querySelector('.pl-box'), count = wrap.querySelector('.pl-count'),
        msg = wrap.querySelector('.pl-msg'), only = wrap.querySelector('[data-pl-only]');
    var size = parseInt(sel.getAttribute('size') || '8', 10);
    box.style.maxHeight = Math.max(6, Math.min(size, 12)) * 34 + 'px';
    var build = function () {
      var html = '';
      Array.prototype.forEach.call(sel.options, function (o, i) {
        if (o.value === '') return;
        var t = o.text, m = t.match(/^(.*?)\s+(?:—|-)\s+([^—]+)$/) || t.match(/^(.*?)\s+\(([^()]+)\)$/);
        var name = m ? m[1] : t, no = m ? m[2] : '';
        html += '<label class="pl-item" data-i="' + i + '"><input type="checkbox"' + (o.selected ? ' checked' : '') + (o.disabled ? ' disabled' : '') + '>'
          + '<span class="pl-name">' + esc(name) + '</span>' + (no ? '<small class="pl-no">' + esc(no) + '</small>' : '') + '</label>';
      });
      box.innerHTML = html || '<div class="pl-none">No employee for these filters.</div>';
      filter();
    };
    var filter = function () {
      var toks = tokens(find.value), n = 0, onlySel = only.checked;
      $$('.pl-item', box).forEach(function (it) {
        var o = sel.options[parseInt(it.getAttribute('data-i'), 10)];
        var on = o && hits(o.text, toks) && (!onlySel || o.selected);
        it.hidden = !on;
        it.classList.toggle('is-sel', !!(o && o.selected));
        if (on) n++;
      });
      var none = box.querySelector('.pl-none-q');
      if (!n && $$('.pl-item', box).length) {
        if (!none) { none = document.createElement('div'); none.className = 'pl-none pl-none-q'; box.appendChild(none); }
        none.textContent = onlySel && !toks.length ? 'No employee selected yet.' : 'No employee matches "' + find.value + '".';
        none.hidden = false;
      } else if (none) { none.hidden = true; }
      tally(n);
    };
    var tally = function (shownN) {
      var picked = Array.prototype.filter.call(sel.options, function (o) { return o.selected && o.value !== ''; }).length;
      var total = Array.prototype.filter.call(sel.options, function (o) { return o.value !== ''; }).length;
      count.textContent = picked + ' of ' + total + ' selected' + (shownN !== undefined && shownN !== total ? ', ' + shownN + ' shown' : '');
      wrap.classList.toggle('has-pick', picked > 0);
      if (picked) { msg.hidden = true; wrap.classList.remove('is-invalid'); }
    };
    var changed = function () { sel.dispatchEvent(new Event('change', { bubbles: true })); };
    box.addEventListener('change', function (e) {
      var it = e.target.closest('.pl-item');
      if (!it) return;
      var o = sel.options[parseInt(it.getAttribute('data-i'), 10)];
      if (o) o.selected = e.target.checked;
      it.classList.toggle('is-sel', e.target.checked);
      tally();
      changed();
    });
    wrap.querySelector('[data-pl-all]').addEventListener('click', function () {
      $$('.pl-item', box).forEach(function (it) {
        if (it.hidden) return;
        var cb = it.querySelector('input'), o = sel.options[parseInt(it.getAttribute('data-i'), 10)];
        if (cb.disabled || !o) return;
        cb.checked = true; o.selected = true; it.classList.add('is-sel');
      });
      tally(); changed();
    });
    wrap.querySelector('[data-pl-none]').addEventListener('click', function () {
      Array.prototype.forEach.call(sel.options, function (o) { o.selected = false; });
      $$('.pl-item input', box).forEach(function (cb) { cb.checked = false; });
      filter(); changed();
    });
    find.addEventListener('input', filter);
    find.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();   // Enter ticks the only employee shown instead of sending the form
      var vis = $$('.pl-item', box).filter(function (it) { return !it.hidden; });
      if (vis.length === 1) { var cb = vis[0].querySelector('input'); if (!cb.disabled) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change', { bubbles: true })); } }
    });
    only.addEventListener('change', filter);
    sel.addEventListener('live:updated', build);
    if (req && sel.form) {
      sel.form.addEventListener('submit', function (e) {
        var picked = Array.prototype.some.call(sel.options, function (o) { return o.selected && o.value !== ''; });
        if (!picked) {
          e.preventDefault();
          e.stopPropagation();
          msg.textContent = 'Select at least one employee.';
          msg.hidden = false;
          wrap.classList.add('is-invalid');
          find.focus();
        }
      });
    }
    build();
  }

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function enhance(root) {
    root = root || document;
    $$(PICK, root).forEach(picklist);
    $$(COMBO, root).forEach(function (s) { if (!s.multiple) combo(s); });
  }
  window.ihrisEnhance = enhance;

  /* ------------------------------------------------------------ master lists of the split pages */
  $$('[data-filter-list]').forEach(function (inp) {
    var run = function () {
      var box = document.getElementById(inp.getAttribute('data-filter-list'));
      if (!box) return;
      var toks = tokens(inp.value), n = 0;
      $$('a', box).forEach(function (a) {
        var on = hits(a.textContent + ' ' + (a.getAttribute('data-search') || ''), toks);
        a.style.display = on ? '' : 'none';
        if (on) n++;
      });
      var none = box.querySelector('.list-none');
      if (!n && toks.length) {
        if (!none) { none = document.createElement('div'); none.className = 'list-none muted small'; box.appendChild(none); }
        none.textContent = 'No employee matches "' + inp.value + '".';
        none.hidden = false;
      } else if (none) { none.hidden = true; }
    };
    inp.addEventListener('input', run);
    inp.addEventListener('live:run', run);
  });

  /* ------------------------------------------------------------ live filter forms */
  $$('form[data-live]').forEach(function (form) {
    var regions = form.getAttribute('data-live').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
    var timer = null, ctl = null, seq = 0;
    var quick = function (q) {
      // instant narrowing of what is on the page while the server answers
      var toks = tokens(q);
      regions.forEach(function (r) {
        var el = document.querySelector(r);
        if (!el || el.tagName === 'SELECT') return;
        $$('.list-nav a', el).concat(el.classList.contains('list-nav') ? $$('a', el) : []).forEach(function (a) {
          a.style.display = hits(a.textContent + ' ' + (a.getAttribute('data-search') || ''), toks) ? '' : 'none';
        });
        $$('tbody tr[data-search], tbody tr.live-row', el).forEach(function (tr) {
          tr.style.display = hits(tr.textContent + ' ' + (tr.getAttribute('data-search') || ''), toks) ? '' : 'none';
        });
      });
    };
    var refresh = function () {
      var fd = new FormData(form), params = new URLSearchParams();
      fd.forEach(function (v, k) { if (typeof v === 'string' && v !== '') params.append(k, v); });
      var url = (form.getAttribute('action') || 'index.php').split('?')[0] + '?' + params.toString();
      if (ctl && ctl.abort) ctl.abort();
      ctl = window.AbortController ? new AbortController() : null;
      var my = ++seq;
      regions.forEach(function (r) { var el = document.querySelector(r); if (el) el.classList.add('is-refreshing'); });
      fetch(url, { credentials: 'same-origin', headers: { 'X-Live-Search': '1' }, signal: ctl ? ctl.signal : undefined })
        .then(function (res) { return res.text(); })
        .then(function (html) {
          if (my !== seq) return;
          var doc = new DOMParser().parseFromString(html, 'text/html');
          regions.forEach(function (r) {
            var cur = document.querySelector(r), nu = doc.querySelector(r);
            if (!cur) return;
            cur.classList.remove('is-refreshing');
            if (!nu) return;
            if (cur.tagName === 'SELECT') {
              var keep = {};
              Array.prototype.forEach.call(cur.options, function (o) { if (o.selected) keep[o.value] = 1; });
              cur.innerHTML = nu.innerHTML;
              Array.prototype.forEach.call(cur.options, function (o) { if (keep[o.value]) o.selected = true; });
            } else {
              cur.innerHTML = nu.innerHTML;
              enhance(cur);
            }
            cur.dispatchEvent(new Event('live:updated', { bubbles: false }));
          });
          try { history.replaceState(null, '', url); } catch (e) {}
          $$('[data-filter-list]').forEach(function (i) { if (i.value) i.dispatchEvent(new Event('live:run')); });
        })
        .catch(function () { regions.forEach(function (r) { var el = document.querySelector(r); if (el) el.classList.remove('is-refreshing'); }); });
    };
    form.addEventListener('input', function (e) {
      var t = e.target;
      if (!(t.matches('input[type=search], input[data-live-input]'))) return;
      quick(t.value);
      clearTimeout(timer);
      timer = setTimeout(refresh, 280);
    });
    form.addEventListener('change', function (e) {
      var t = e.target;
      if (t.hasAttribute('data-autosubmit') || t.matches('input[type=search]')) return;
      if (t.closest('.picklist') && !t.matches('select')) return;
      clearTimeout(timer);
      timer = setTimeout(refresh, 60);
    });
  });

  enhance(document);
})();

/* =====================================================================
   v17: stay put. Picking an employee in a list (Attendance / Payroll Transaction, Summary Entry II,
   Attendance Entry ...), clicking a row of the period list or saving a form of the same screen
   reloads the page; the page comes back at the same place: the window, the lists and tables that
   scroll on their own, and the element that was clicked are kept where they were.
   ===================================================================== */
(function () {
  'use strict';
  var KEY = 'ihris.stay';
  var BOXES = '.list-nav, .table-scroll, .grid-wrap, .table-wrap, .pl-box, .prpt-wrap';
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var routeOf = function (u) {
    try { return new URL(u, location.href).searchParams.get('p') || 'dashboard'; } catch (e) { return ''; }
  };
  var cur = routeOf(location.href);
  var anchorOf = function (el) {
    var a = el && el.closest ? el.closest('[id]:not(html):not(body)') : null;
    return a ? { id: a.id, top: a.getBoundingClientRect().top } : null;
  };
  var save = function (from) {
    try {
      var boxes = $$(BOXES).map(function (b) { return [b.scrollTop, b.scrollLeft]; });
      sessionStorage.setItem(KEY, JSON.stringify({ p: cur, y: window.scrollY, x: window.scrollX, boxes: boxes, anchor: anchorOf(from), t: Date.now() }));
    } catch (e) { /* storage blocked: the page simply opens at the top */ }
  };
  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
    var a = e.target.closest('a[href]');
    if (a && !a.target && !a.hasAttribute('download') && a.getAttribute('href').charAt(0) !== '#' && routeOf(a.href) === cur) { save(a); return; }
    var tr = e.target.closest('tr.clickable, tr[onclick]');
    if (tr && !e.target.closest('a, button, input, select, textarea')) save(tr);
  }, true);
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.hasAttribute('data-no-stay')) return;
    var target = f.getAttribute('action') ? routeOf(f.getAttribute('action')) : cur;
    if (target === cur || (f.method || '').toLowerCase() === 'post') save(e.submitter || f);
  }, true);
  // a select that opens another employee of the same screen (data-autosubmit) also stays put
  document.addEventListener('change', function (e) {
    if (e.target.hasAttribute && e.target.hasAttribute('data-autosubmit')) save(e.target);
  }, true);

  var s = null;
  try { s = JSON.parse(sessionStorage.getItem(KEY) || 'null'); sessionStorage.removeItem(KEY); } catch (e) { s = null; }
  if (!s || s.p !== cur || Date.now() - s.t > 60000) return;
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  var restore = function () {
    var html = document.documentElement, sb = html.style.scrollBehavior;
    html.style.scrollBehavior = 'auto';   // jump straight back (no smooth scrolling from the top)
    $$(BOXES).forEach(function (b, i) {
      if (s.boxes && s.boxes[i]) { b.scrollTop = s.boxes[i][0]; b.scrollLeft = s.boxes[i][1]; }
    });
    window.scrollTo(s.x || 0, s.y || 0);
    if (s.anchor) {
      var el = document.getElementById(s.anchor.id);
      if (el) {
        var d = el.getBoundingClientRect().top - s.anchor.top;
        if (Math.abs(d) > 1) window.scrollBy(0, d);   // a message shown above the form does not push it away
      }
    }
    html.style.scrollBehavior = sb;
  };
  restore();
  window.addEventListener('load', restore);
})();

/* v17: pick list of codes: tick one, two, three or more codes, each row with its own amount */
(function () {
  'use strict';
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  $$('.codepick').forEach(function (cp) {
    var find = cp.querySelector('.cp-find'), count = cp.querySelector('.cp-count'), msg = cp.querySelector('.pl-msg');
    var rows = $$('.cp-row', cp);
    var sync = function (tr) {
      var cb = tr.querySelector('input[type=checkbox]');
      tr.classList.toggle('is-on', cb.checked);
      $$('input:not([type=checkbox]), select', tr).forEach(function (i) { i.disabled = !cb.checked; });
    };
    var tally = function () {
      var n = rows.filter(function (tr) { return tr.querySelector('input[type=checkbox]').checked; }).length;
      count.textContent = n + ' of ' + rows.length + ' selected';
      cp.classList.toggle('has-pick', n > 0);
      if (n) { msg.hidden = true; cp.classList.remove('is-invalid'); }
      cp.dispatchEvent(new CustomEvent('codepick:change', { bubbles: true, detail: { count: n } }));
    };
    cp.addEventListener('change', function (e) {
      var tr = e.target.closest('.cp-row');
      if (tr && e.target.type === 'checkbox') {
        sync(tr);
        tally();
        if (e.target.checked) { var first = tr.querySelector('input:not([type=checkbox]), select'); if (first) first.focus(); }
      }
    });
    cp.addEventListener('click', function (e) {
      // a click on the name ticks the row
      var name = e.target.closest('.cp-name');
      if (name) { var cb = name.parentNode.querySelector('input[type=checkbox]'); cb.checked = !cb.checked; cb.dispatchEvent(new Event('change', { bubbles: true })); }
      if (e.target.closest('[data-cp-clear]')) {
        rows.forEach(function (tr) { tr.querySelector('input[type=checkbox]').checked = false; sync(tr); });
        tally();
      }
    });
    if (find) {
      find.addEventListener('input', function () {
        var q = find.value.toLowerCase().trim();
        rows.forEach(function (tr) { tr.hidden = q !== '' && (tr.getAttribute('data-search') || '').indexOf(q) === -1 && !tr.classList.contains('is-on'); });
      });
      find.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    }
    var form = cp.closest('form');
    if (form && cp.hasAttribute('data-required')) {
      form.addEventListener('submit', function (e) {
        if (rows.some(function (tr) { return tr.querySelector('input[type=checkbox]').checked; })) return;
        e.preventDefault();
        e.stopPropagation();
        msg.textContent = 'Tick at least one code.';
        msg.hidden = false;
        cp.classList.add('is-invalid');
      });
    }
    rows.forEach(sync);
    tally();
  });
})();

/* v18: shift schedule popup. Pointing at a shift code (hover, keyboard focus or a tap on a phone) shows the
   complete shift: every log of the day (2, 4, 6 or 8 logs with the pre-arranged overtime), the working hours
   and the day-off. One popup for the page, positioned on the screen so a scrolling table never clips it. */
(function () {
  var pop = null, cur = null, shownAt = 0, lastShow = 0, lastTouch = 0;
  var touchy = function () { return Date.now() - lastTouch < 900; };   // mouse events a phone makes up after a tap
  var hide = function () { if (pop) { pop.hidden = true; } cur = null; };
  var show = function (el) {
    if (!pop) {
      pop = document.createElement('div');
      pop.className = 'shift-pop';
      pop.setAttribute('role', 'tooltip');
      pop.id = 'shiftPop';
      pop.hidden = true;
      document.body.appendChild(pop);
    }
    if (cur !== el) { shownAt = Date.now(); }
    lastShow = Date.now();
    cur = el;
    pop.innerHTML = el.getAttribute('data-shift-tip');
    pop.hidden = false;
    el.setAttribute('aria-describedby', 'shiftPop');
    var r = el.getBoundingClientRect(), pw = pop.offsetWidth, ph = pop.offsetHeight, m = 8;
    var top = r.bottom + 6;
    if (top + ph > window.innerHeight - m && r.top - ph - 6 > m) { top = r.top - ph - 6; }
    var left = Math.min(Math.max(m, r.left + r.width / 2 - pw / 2), window.innerWidth - pw - m);
    pop.style.top = Math.max(m, top) + 'px';
    pop.style.left = left + 'px';
  };
  var tipOf = function (t) { return t && t.closest ? t.closest('[data-shift-tip]') : null; };
  document.addEventListener('touchstart', function () { lastTouch = Date.now(); }, { passive: true, capture: true });
  document.addEventListener('mouseover', function (e) { var el = tipOf(e.target); if (el && el !== cur && !touchy()) { show(el); } });
  document.addEventListener('mouseout', function (e) { var el = tipOf(e.target); if (el && el === cur && !el.contains(e.relatedTarget) && !touchy()) { hide(); } });
  document.addEventListener('focusin', function (e) { var el = tipOf(e.target); if (el) { show(el); } });
  document.addEventListener('focusout', function (e) { if (tipOf(e.target) === cur && !touchy()) { hide(); } });
  document.addEventListener('click', function (e) {
    var el = tipOf(e.target);
    if (el) { if (el === cur && pop && !pop.hidden && Date.now() - shownAt > 400) { hide(); } else { show(el); } return; }   // a second tap closes it
    if (cur && !(pop && pop.contains(e.target))) { hide(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { hide(); } });
  window.addEventListener('scroll', function () { if (Date.now() - lastShow > 350) { hide(); } }, true);   // not the scroll a tap itself causes
  window.addEventListener('resize', function () { if (Date.now() - lastShow > 350) { hide(); } });
})();

/* v19: dates typed with the number keys. Every date field of the system (filters, forms, grids, pop-ups and the
   lists refreshed while typing) takes the date typed as numbers, the fastest way to encode many dates:
     09192026 or 091926 or 0919 (this year) = 09/19/2026;  9/19/26, 9-19-2026, 2026-09-19 also work;
     t = today; + / - or the arrow keys = one day later / earlier; Alt + Down or the calendar icon opens the calendar.
   The field shows mm/dd/yyyy; the form still sends the date as YYYY-MM-DD, so nothing changes on the server.
   The original field stays in the form (hidden) with its name, id, value, events and validation rules. */
(function () {
  'use strict';
  var proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  if (!proto || !proto.set) return;
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var toIso = function (y, m, d) { return y + '-' + pad(m) + '-' + pad(d); };
  var isoOf = function (dt) { return toIso(dt.getFullYear(), dt.getMonth() + 1, dt.getDate()); };
  var show = function (iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    return m ? m[2] + '/' + m[3] + '/' + m[1] : '';
  };
  var ok = function (y, m, d) {
    var dt = new Date(y, m - 1, d);
    return y >= 1900 && y <= 2199 && dt.getFullYear() === y && dt.getMonth() === m - 1 && dt.getDate() === d;
  };
  var year = function (y) { y = +y; return y < 100 ? (y < 70 ? 2000 + y : 1900 + y) : y; };
  // the date typed: '' (blank), 'YYYY-MM-DD', or null (not a date)
  var parse = function (s) {
    s = String(s || '').trim().toLowerCase();
    if (s === '') return '';
    var now = new Date(), m, y, mo, d;
    if (s === 't' || s === 'today') return isoOf(now);
    if ((m = /^(\d{4})[-\/. ](\d{1,2})[-\/. ](\d{1,2})$/.exec(s))) { y = +m[1]; mo = +m[2]; d = +m[3]; }
    else if ((m = /^(\d{1,2})[-\/. ]+(\d{1,2})(?:[-\/. ]+(\d{2}|\d{4}))?$/.exec(s))) { mo = +m[1]; d = +m[2]; y = m[3] ? year(m[3]) : now.getFullYear(); }
    else if (/^\d+$/.test(s)) {
      if (s.length === 8 && ok(+s.slice(4), +s.slice(0, 2), +s.slice(2, 4))) { mo = +s.slice(0, 2); d = +s.slice(2, 4); y = +s.slice(4); }
      else if (s.length === 8) { y = +s.slice(0, 4); mo = +s.slice(4, 6); d = +s.slice(6); }   // YYYYMMDD
      else if (s.length === 6) { mo = +s.slice(0, 2); d = +s.slice(2, 4); y = year(s.slice(4)); }
      else if (s.length === 4) { mo = +s.slice(0, 2); d = +s.slice(2); y = now.getFullYear(); }
      else return null;
    } else return null;
    return ok(y, mo, d) ? toIso(y, mo, d) : null;
  };
  var addDays = function (iso, n) {
    var p = iso.split('-'), dt = new Date(+p[0], +p[1] - 1, +p[2] + n);
    return isoOf(dt);
  };

  // one hidden native date field opens the calendar of whichever date field asked for it
  var helper = null, helperFor = null;
  var openCalendar = function (box) {
    if (box.disabled || box.readOnly) return;
    if (!helper) {
      helper = document.createElement('input');
      helper.type = 'date';
      helper.tabIndex = -1;
      helper.setAttribute('aria-hidden', 'true');
      helper.className = 'date-helper';
      document.body.appendChild(helper);
      helper.addEventListener('change', function () { if (helperFor && helper.value) { helperFor.__dateSet(helper.value, true); helperFor.focus(); } });
    }
    var r = box.getBoundingClientRect();
    helper.style.left = r.left + 'px'; helper.style.top = r.top + 'px'; helper.style.width = r.width + 'px'; helper.style.height = r.height + 'px';
    helperFor = box;
    proto.set.call(helper, box.__dateOrig.value || '');
    helper.min = box.__dateOrig.min || ''; helper.max = box.__dateOrig.max || '';
    try { helper.showPicker(); } catch (e) { helper.focus(); try { helper.click(); } catch (x) {} }
  };

  var enhance = function (orig) {
    if (orig.__dateBox || orig.type !== 'date') return;
    var box = document.createElement('input');
    box.type = 'text';
    box.inputMode = 'numeric';
    box.autocomplete = 'off';
    box.placeholder = 'mm/dd/yyyy';
    box.maxLength = 10;
    box.className = (orig.className ? orig.className.replace(/\bcell\b/, '').trim() + ' ' : '') + 'date-typed';
    if (orig.getAttribute('style')) box.setAttribute('style', orig.getAttribute('style'));
    box.id = (orig.id || ('d' + Math.random().toString(36).slice(2))) + '__typed';
    box.title = 'Type the date as numbers (e.g. 09192026) or pick it from the calendar';
    var aria = orig.getAttribute('aria-label');
    if (aria) box.setAttribute('aria-label', aria);
    var sync = function () {
      box.disabled = orig.disabled; box.readOnly = orig.readOnly; box.required = orig.required;
      box.className = (orig.className ? orig.className.replace(/\bcell\b/, '').trim() + ' ' : '') + 'date-typed' + (box.classList.contains('is-bad') ? ' is-bad' : '');
    };
    sync();
    box.__dateOrig = orig;
    orig.__dateBox = box;
    var check = function (iso) {
      var msg = '';
      if (iso === null) msg = 'Type the date as mm/dd/yyyy, e.g. 09192026 or 9/19/26.';
      else if (iso && orig.min && iso < orig.min) msg = 'The date cannot be before ' + show(orig.min) + '.';
      else if (iso && orig.max && iso > orig.max) msg = 'The date cannot be after ' + show(orig.max) + '.';
      box.setCustomValidity(msg);
      box.classList.toggle('is-bad', msg !== '' && box.value !== '');
      return msg === '';
    };
    var fire = function (type) { orig.dispatchEvent(new Event(type, { bubbles: true })); };
    var last = proto.get.call(orig);
    box.__dateSet = function (iso, commit) {
      box.value = show(iso);
      check(iso);
      if (iso !== last) { proto.set.call(orig, iso); last = iso; fire('input'); if (commit) fire('change'); }
    };
    // the field keeps showing whatever a script puts in the original (filters copied, Undo, defaults)
    try {
      Object.defineProperty(orig, 'value', { configurable: true,
        get: function () { return proto.get.call(orig); },
        set: function (v) { proto.set.call(orig, v); last = proto.get.call(orig); box.value = show(last); check(last); } });
    } catch (e) {}
    box.value = show(last);
    box.defaultValue = box.value;
    orig.parentNode.insertBefore(box, orig.nextSibling);
    orig.type = 'hidden';
    if (orig.id) {
      Array.prototype.forEach.call(document.querySelectorAll('label[for="' + orig.id.replace(/"/g, '\\"') + '"]'), function (l) { l.htmlFor = box.id; });
    }
    if (window.MutationObserver) {
      new MutationObserver(sync).observe(orig, { attributes: true, attributeFilter: ['disabled', 'readonly', 'required', 'class'] });
    }
    box.addEventListener('input', function (e) {
      var v = box.value;
      // the slashes come by themselves: 0919 -> 09/19/
      if (e.inputType && e.inputType.indexOf('insert') === 0 && box.selectionStart === v.length) {
        if (/^\d{2}$/.test(v) && +v >= 1 && +v <= 12) v += '/';
        else if (/^\d{1,2}\/\d{2}$/.test(v)) v += '/';
        if (v !== box.value) box.value = v;
      }
      var iso = parse(box.value);
      var digits = box.value.replace(/\D/g, '').length;
      box.classList.remove('is-bad');
      box.setCustomValidity('');
      if (iso === '' || (iso && (digits >= 8 || /\d{1,2}\/\d{1,2}\/(\d{2}|\d{4})$/.test(box.value) && digits >= 6))) {
        if (iso !== last) { proto.set.call(orig, iso); last = iso; fire('input'); }
      }
    });
    box.addEventListener('change', function () {
      var iso = parse(box.value);
      if (iso === null) { check(null); if (last !== '') { proto.set.call(orig, ''); last = ''; fire('input'); } fire('change'); return; }
      box.__dateSet(iso, false);
      fire('change');
    });
    box.addEventListener('keydown', function (e) {
      if ((e.altKey && e.key === 'ArrowDown') || e.key === 'F4') { e.preventDefault(); openCalendar(box); return; }
      if (box.readOnly || box.disabled) return;
      var step = e.key === 'ArrowUp' || e.key === '+' ? 1 : (e.key === 'ArrowDown' || e.key === '-' ? -1 : 0);
      if (step && (e.key !== '-' || /^\d{1,2}\/\d{1,2}\/\d{4}$/.test(box.value) || box.value === '')) {
        var cur = parse(box.value);
        if (cur === null) return;
        e.preventDefault();
        box.__dateSet(cur ? addDays(cur, step) : isoOf(new Date()), true);
      } else if (e.key === 'Enter') {
        var iso = parse(box.value);
        if (iso !== null) box.__dateSet(iso, true);
      }
    });
    box.addEventListener('blur', function () { var iso = parse(box.value); if (iso) box.value = show(iso); });
    // the calendar icon at the right end of the field
    box.addEventListener('click', function (e) {
      if (e.offsetX >= box.clientWidth - 30) openCalendar(box);
    });
    box.addEventListener('mousemove', function (e) { box.style.cursor = e.offsetX >= box.clientWidth - 30 ? 'pointer' : ''; });
  };
  var scan = function (root) {
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches('input[type=date]')) enhance(root);
    Array.prototype.forEach.call(root.querySelectorAll('input[type=date]'), enhance);
  };
  scan(document);
  if (window.MutationObserver) {
    new MutationObserver(function (list) {
      list.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1 && n !== helper) scan(n); }); });
    }).observe(document.body, { childList: true, subtree: true });
  }
})();

/* v20: filters with one, two or more values (Co. / Branch, Department, Employee group ...): a drop-down of check
   boxes with Find, All and Clear. The choice applies when the list closes (Done, a click outside or Esc): the
   lists that refresh while typing refresh then, and a form marked data-mpick-submit is sent. */
(function () {
  'use strict';
  var all = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var init = function (box) {
    if (box.__mpick) return;
    box.__mpick = true;
    var sum = box.querySelector('.mpick-sum'), n = box.querySelector('.mpick-n'), find = box.querySelector('.mpick-find');
    var boxes = all('.mpick-opt input', box), changed = false, allLabel = box.getAttribute('data-all') || 'All';
    var text = function () {
      var on = boxes.filter(function (c) { return c.checked; });
      var names = on.map(function (c) { return c.parentNode.textContent.trim(); });
      sum.textContent = !on.length ? allLabel : (on.length <= 2 ? names.join(', ') : on.length + ' selected');
      n.textContent = on.length; n.hidden = !on.length;
      box.classList.toggle('has-pick', on.length > 0);
      box.title = on.length ? names.join(', ') : allLabel;
    };
    box.addEventListener('change', function (e) {
      if (e.target.matches('.mpick-opt input')) { changed = true; text(); }
      if (e.target !== box) e.stopPropagation();   // the choice applies when the list closes
    });
    box.addEventListener('input', function (e) { if (e.target === find) e.stopPropagation(); });
    if (find) find.addEventListener('input', function () {
      var q = find.value.toLowerCase().trim();
      all('.mpick-opt', box).forEach(function (o) { o.hidden = q !== '' && o.getAttribute('data-search').indexOf(q) < 0; });
    });
    if (find) find.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-mpick-all], [data-mpick-clear], [data-mpick-done]');
      if (!b) return;
      e.preventDefault();
      if (b.hasAttribute('data-mpick-done')) { box.open = false; return; }
      var on = b.hasAttribute('data-mpick-all');
      boxes.forEach(function (c) { if (!c.closest('.mpick-opt').hidden || !on) c.checked = on && !c.closest('.mpick-opt').hidden; });
      if (on) { /* All = every value shown, the others left as they were */ }
      changed = true; text();
    });
    box.addEventListener('toggle', function () {
      if (box.open) {
        all('details.mpick[open]').forEach(function (o) { if (o !== box) o.open = false; });
        if (find) setTimeout(function () { find.focus(); }, 30);
        return;
      }
      if (!changed) return;
      changed = false;
      box.dispatchEvent(new Event('change', { bubbles: true }));
      var form = box.closest('form');
      if (form && form.hasAttribute('data-mpick-submit')) form.submit();
    });
    text();
  };
  var scan = function (root) { all('details[data-mpick]', root).forEach(init); };
  scan(document);
  document.addEventListener('click', function (e) {
    all('details.mpick[open]').forEach(function (b) { if (!b.contains(e.target)) b.open = false; });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') all('details.mpick[open]').forEach(function (b) { b.open = false; });
  });
  if (window.MutationObserver) {
    new MutationObserver(function (list) {
      list.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (x) { if (x.nodeType === 1) scan(x); }); });
    }).observe(document.body, { childList: true, subtree: true });
  }
})();

/* v20: times typed with the number keys. Every time field (Shift Table, Attendance Entry logs, settings, forms):
     0815 or 815 = 08:15 AM;  1730 = 05:30 PM;  8 = 08:00 AM;  815p, 8:15 pm, 5:30p = PM;  12a = 12:00 AM;  n = now;
     the arrow keys = one minute later / earlier (Shift + arrow = 15 minutes); Alt + Down or the clock icon opens the picker.
   Without a or p the time is read as 24-hour (1730 = 5:30 PM). The field shows hh:mm AM/PM; the form still sends HH:MM. */
(function () {
  'use strict';
  var proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
  if (!proto || !proto.set) return;
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var show = function (v) {
    var m = /^(\d{2}):(\d{2})/.exec(v || '');
    if (!m) return '';
    var h = +m[1], ap = h >= 12 ? 'PM' : 'AM';
    return pad(h % 12 === 0 ? 12 : h % 12) + ':' + m[2] + ' ' + ap;
  };
  // the time typed: '' (blank), 'HH:MM', or null (not a time)
  var parse = function (s) {
    s = String(s || '').trim().toLowerCase().replace(/\s+/g, ' ');
    if (s === '') return '';
    if (s === 'n' || s === 'now') { var d = new Date(); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    var ap = null, m;
    if ((m = /\s*([ap])\.?\s*m?\.?$/.exec(s))) { ap = m[1]; s = s.slice(0, m.index).trim(); }
    var h, mi;
    if ((m = /^(\d{1,2})[:.\s](\d{1,2})(?::\d{2})?$/.exec(s))) { h = +m[1]; mi = +m[2]; }
    else if (/^\d{1,4}$/.test(s)) {
      if (s.length <= 2) { h = +s; mi = 0; } else { h = +s.slice(0, s.length - 2); mi = +s.slice(-2); }
    } else return null;
    if (mi > 59) return null;
    if (ap) {
      if (h < 1 || h > 12) return null;
      h = ap === 'p' ? (h % 12) + 12 : h % 12;
    } else if (h > 23) return null;
    return pad(h) + ':' + pad(mi);
  };
  var helper = null, helperFor = null;
  var openPicker = function (box) {
    if (box.disabled || box.readOnly) return;
    if (!helper) {
      helper = document.createElement('input');
      helper.type = 'time';
      helper.tabIndex = -1;
      helper.setAttribute('aria-hidden', 'true');
      helper.className = 'date-helper';
      document.body.appendChild(helper);
      helper.addEventListener('change', function () { if (helperFor && helper.value) { helperFor.__timeSet(helper.value.slice(0, 5), true); helperFor.focus(); } });
    }
    var r = box.getBoundingClientRect();
    helper.style.left = r.left + 'px'; helper.style.top = r.top + 'px'; helper.style.width = r.width + 'px'; helper.style.height = r.height + 'px';
    helperFor = box;
    proto.set.call(helper, (box.__timeOrig.value || '').slice(0, 5));
    try { helper.showPicker(); } catch (e) { helper.focus(); }
  };
  var enhance = function (orig) {
    if (orig.__timeBox || orig.type !== 'time') return;
    var box = document.createElement('input');
    box.type = 'text';
    box.inputMode = 'numeric';
    box.autocomplete = 'off';
    box.placeholder = orig.closest('.grid-entry, .eb-grid') ? '' : 'hh:mm AM';   // a grid of logs stays quiet
    box.maxLength = 9;
    var cls = function () { return (orig.className ? orig.className.replace(/\bcell\b/, '').trim() + ' ' : '') + 'time-typed' + (box.classList.contains('is-bad') ? ' is-bad' : ''); };
    box.className = cls();
    if (orig.getAttribute('style')) box.setAttribute('style', orig.getAttribute('style'));
    box.id = (orig.id || ('t' + Math.random().toString(36).slice(2))) + '__typed';
    box.title = 'Type the time as numbers (e.g. 0815, 1730 or 530p) or pick it';
    var aria = orig.getAttribute('aria-label');
    if (aria) box.setAttribute('aria-label', aria);
    var sync = function () { box.disabled = orig.disabled; box.readOnly = orig.readOnly; box.required = orig.required; box.className = cls(); };
    sync();
    box.__timeOrig = orig;
    orig.__timeBox = box;
    var fire = function (type) { orig.dispatchEvent(new Event(type, { bubbles: true })); };
    var last = proto.get.call(orig);
    var check = function (t) {
      var msg = t === null ? 'Type the time as hh:mm, e.g. 0815, 1730 or 530p.' : '';
      box.setCustomValidity(msg);
      box.classList.toggle('is-bad', msg !== '' && box.value !== '');
    };
    box.__timeSet = function (t, commit) {
      box.value = show(t);
      check(t);
      if (t !== (last || '').slice(0, 5)) { proto.set.call(orig, t); last = t; fire('input'); if (commit) fire('change'); }
    };
    try {
      Object.defineProperty(orig, 'value', { configurable: true,
        get: function () { return proto.get.call(orig); },
        set: function (v) { proto.set.call(orig, v); last = proto.get.call(orig); box.value = show(last); check(last ? last.slice(0, 5) : ''); } });
    } catch (e) {}
    box.value = show(last);
    box.defaultValue = box.value;
    orig.parentNode.insertBefore(box, orig.nextSibling);
    orig.type = 'hidden';
    if (orig.id) {
      Array.prototype.forEach.call(document.querySelectorAll('label[for="' + orig.id.replace(/"/g, '\\"') + '"]'), function (l) { l.htmlFor = box.id; });
    }
    if (window.MutationObserver) {
      new MutationObserver(sync).observe(orig, { attributes: true, attributeFilter: ['disabled', 'readonly', 'required', 'class'] });
    }
    box.addEventListener('input', function (e) {
      var v = box.value;
      // the colon comes by itself after the hour: 08 -> 08:, 1730 -> 17:30
      if (e.inputType && e.inputType.indexOf('insert') === 0 && box.selectionStart === v.length && /^\d{4}$/.test(v)) {
        box.value = v.slice(0, 2) + ':' + v.slice(2);
      }
      box.classList.remove('is-bad');
      box.setCustomValidity('');
      var t = parse(box.value);
      if (t !== null && (t === '' || box.value.replace(/\D/g, '').length >= 3 || /[ap]/i.test(box.value))) {
        if (t !== (last || '').slice(0, 5)) { proto.set.call(orig, t); last = t; fire('input'); }
      }
    });
    box.addEventListener('change', function () {
      var t = parse(box.value);
      if (t === null) { check(null); if (last) { proto.set.call(orig, ''); last = ''; fire('input'); } fire('change'); return; }
      box.__timeSet(t, false);
      fire('change');
    });
    box.addEventListener('blur', function () { var t = parse(box.value); if (t) box.value = show(t); });
    box.addEventListener('keydown', function (e) {
      if ((e.altKey && e.key === 'ArrowDown') || e.key === 'F4') { e.preventDefault(); openPicker(box); return; }
      if (box.readOnly || box.disabled) return;
      if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        var t = parse(box.value);
        if (t === null) return;
        e.preventDefault();
        var p = (t || '08:00').split(':'), mins = (+p[0] * 60 + +p[1] + (e.key === 'ArrowUp' ? 1 : -1) * (e.shiftKey ? 15 : 1) + 1440) % 1440;
        box.__timeSet(pad(Math.floor(mins / 60)) + ':' + pad(mins % 60), true);
      } else if (e.key === 'Enter') {
        var t2 = parse(box.value);
        if (t2 !== null) box.__timeSet(t2, true);
      }
    });
    box.addEventListener('click', function (e) { if (e.offsetX >= box.clientWidth - 30) openPicker(box); });
    box.addEventListener('mousemove', function (e) { box.style.cursor = e.offsetX >= box.clientWidth - 30 ? 'pointer' : ''; });
  };
  var scan = function (root) {
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches('input[type=time]')) enhance(root);
    Array.prototype.forEach.call(root.querySelectorAll('input[type=time]'), enhance);
  };
  scan(document);
  if (window.MutationObserver) {
    new MutationObserver(function (list) {
      list.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType === 1 && n !== helper && !(n.classList && n.classList.contains('date-helper'))) scan(n); }); });
    }).observe(document.body, { childList: true, subtree: true });
  }
})();

/* v21 Password Resets (Super Admin): the typed temporary password field shows only for "Type the temporary password". */
(function () {
  var radios = document.querySelectorAll('[data-pwr-mode]');
  if (!radios.length) return;
  var box = document.querySelector('[data-pwr-typed]');
  var input = box ? box.querySelector('input') : null;
  function sync() {
    var typed = !!document.querySelector('[data-pwr-mode][value="typed"]:checked');
    if (box) box.hidden = !typed;
    if (input) { input.required = typed; if (!typed) input.value = ''; else input.focus(); }
  }
  Array.prototype.forEach.call(radios, function (r) { r.addEventListener('change', sync); });
  sync();
})();
