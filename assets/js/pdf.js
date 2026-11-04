/* =====================================================================
   G-SMaRT HRIS — Save as PDF (v26)
   Every printable document (and the reports printed from their own screen) has two
   buttons: Print opens the browser's print dialog; Save as PDF builds the PDF file in
   the browser and downloads it, without the print dialog.

   How the PDF is made:
   1. The document is copied into a hidden frame 1600 px wide (the desktop layout, also
      on a phone) with the print styles of the system applied, at the printable width
      of the paper (A4 portrait, A4 landscape or legal landscape, 8 mm margins — the
      same as printing).
   2. The pages are cut between lines, rows and payslips, never through them; a forced
      page break of the document starts a new page.
   3. Each page is drawn (html2canvas) and placed on a PDF page (jsPDF). Both libraries
      are served with the system (assets/vendor) and loaded only when the button is used.
   Buttons: [data-print] prints; [data-save-pdf] saves. Optional attributes on the save
   button: data-pdf-root (CSS selector of what to save, default .print-page),
   data-pdf-size (a4, landscape, legal-landscape) and data-pdf-name (file name).
   ===================================================================== */
(function () {
  'use strict';
  var MM = 96 / 25.4;              // CSS pixels per millimetre
  var MARGIN = 8;                  // mm, the margin of the printed page
  var SIZES = { a4: [210, 297], landscape: [297, 210], legal: [215.9, 355.6], 'legal-landscape': [355.6, 215.9] };
  var FRAME_W = 1600;              // width of the hidden frame: the desktop layout
  var CHUNK = 5200;                // CSS px drawn at a time (keeps the canvas inside browser limits)
  var base = (function () {
    var s = document.querySelector('script[src*="assets/js/pdf.js"]');
    return s ? s.src.replace(/assets\/js\/pdf\.js.*$/, '') : '';
  })();

  function load(src) {
    return new Promise(function (ok, fail) {
      var s = document.createElement('script');
      s.src = base + src;
      s.onload = ok;
      s.onerror = function () { fail(new Error('Could not load ' + src)); };
      document.head.appendChild(s);
    });
  }
  function libs() {
    return Promise.all([
      window.html2canvas ? null : load('assets/vendor/html2canvas.min.js'),
      window.jspdf ? null : load('assets/vendor/jspdf.umd.min.js')
    ]);
  }
  function wait(ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); }

  function paperOf(btn, root) {
    var want = btn.getAttribute('data-pdf-size');
    if (!want) {
      want = root.classList.contains('legal-landscape') ? 'legal-landscape' : (root.classList.contains('landscape') ? 'landscape' : 'a4');
    }
    return SIZES[want] || SIZES.a4;
  }
  function fileName(btn) {
    var n = btn.getAttribute('data-pdf-name') || document.title || 'document';
    var app = document.body.getAttribute('data-app-name');
    if (app && n.slice(-(app.length + 3)) === ' - ' + app) { n = n.slice(0, -(app.length + 3)); }   // "Title - G-SMaRT HRIS" on the app pages
    n = n.replace(/[\\\/:*?"<>|]+/g, ' ').replace(/[—–]/g, '-').replace(/\s+/g, ' ').trim();
    n = n.replace(/[.\s]+$/, '');
    return (n || 'document') + '.pdf';
  }

  /* The document in a hidden frame, laid out for the paper with the print styles applied. */
  function buildFrame(root, widthPx) {
    return new Promise(function (ok, fail) {
      var head = document.head.innerHTML.replace(/<script[\s\S]*?<\/script>/gi, '');
      var clone = root.cloneNode(true);
      clone.id = 'pdf-root';
      // values typed into fields are properties, not attributes: copy them so they show
      var src = root.querySelectorAll('input, select, textarea');
      var dst = clone.querySelectorAll('input, select, textarea');
      for (var i = 0; i < src.length && i < dst.length; i++) {
        if (src[i].tagName === 'SELECT') { dst[i].querySelectorAll('option').forEach(function (o, k) { if (src[i].options[k] && src[i].options[k].selected) o.setAttribute('selected', 'selected'); }); }
        else if (src[i].type === 'checkbox' || src[i].type === 'radio') { if (src[i].checked) dst[i].setAttribute('checked', 'checked'); }
        else if (src[i].tagName === 'TEXTAREA') { dst[i].textContent = src[i].value; }
        else { dst[i].setAttribute('value', src[i].value); }
      }
      var f = document.createElement('iframe');
      f.setAttribute('aria-hidden', 'true');
      f.style.cssText = 'position:fixed;left:-' + (FRAME_W + 200) + 'px;top:0;width:' + FRAME_W + 'px;height:1200px;border:0;visibility:hidden;';
      f.srcdoc = '<!DOCTYPE html><html><head><base href="' + location.href.replace(/"/g, '&quot;') + '">' + head + '</head><body class="' + document.body.className + ' pdf-capture">' + clone.outerHTML + '</body></html>';
      var done = false;
      f.onload = function () {
        if (done) return;
        done = true;
        var d = f.contentDocument;
        // the print styles (@media print) of the stylesheets, applied on screen inside the frame
        var css = '';
        Array.prototype.forEach.call(d.styleSheets, function (sh) {
          var rules;
          try { rules = sh.cssRules; } catch (e) { return; }
          Array.prototype.forEach.call(rules || [], function (r) {
            if (r.type === 4 && /print/.test(r.media.mediaText) && !/screen/.test(r.media.mediaText)) {
              Array.prototype.forEach.call(r.cssRules, function (x) { css += x.cssText + '\n'; });
            }
          });
        });
        css += 'html,body{background:#fff!important;margin:0!important;padding:0!important;overflow:visible!important}'
          + 'body::before{display:none!important}'
          + '#pdf-root{width:' + widthPx + 'px!important;max-width:none!important;min-height:0!important;margin:0!important;padding:0!important;box-shadow:none!important;border:0!important;background:#fff!important;transform:none!important}'
          + '#pdf-root .table-wrap,#pdf-root .grid-wrap,#pdf-root .matrix-wrap,#pdf-root .table-scroll{overflow:visible!important;max-height:none!important}'
          + '#pdf-root .table th,#pdf-root .reg th,#pdf-root thead th{position:static!important}';
        var st = d.createElement('style');
        st.textContent = css;
        d.head.appendChild(st);
        ok(f);
      };
      setTimeout(function () { if (!done) { done = true; fail(new Error('The document took too long to prepare.')); } }, 30000);
      document.body.appendChild(f);
    });
  }

  /* Where each page ends: the lowest point above the page bottom that does not cut through a line, a table
     row, an image or a block that must stay whole; a forced page break starts a new page. */
  function pageCuts(d, el, pagePx) {
    var top = el.getBoundingClientRect().top;
    var H = Math.ceil(el.scrollHeight || el.getBoundingClientRect().height);
    var covered = new Uint8Array(H + 2);
    var forced = [];
    var mark = function (a, b) {
      a = Math.max(0, Math.round(a) + 1); b = Math.min(H, Math.round(b) - 1);   // rows that touch leave their shared edge free
      for (var y = a; y <= b; y++) covered[y] = 1;
    };
    var all = el.querySelectorAll('*');
    for (var i = 0; i < all.length; i++) {
      var n = all[i];
      var cs = d.defaultView.getComputedStyle(n);
      if (cs.display === 'none') continue;
      var r = n.getBoundingClientRect();
      if (!r.height) continue;
      var a = r.top - top, b = r.bottom - top;
      var keep = n.children.length === 0 || n.tagName === 'TR' || n.tagName === 'IMG' || n.tagName === 'svg' || n.tagName === 'CANVAS'
        || ((cs.breakInside === 'avoid' || cs.pageBreakInside === 'avoid') && (b - a) < pagePx * 0.9);
      if (keep) mark(a, b);
      if (cs.breakAfter === 'page' || cs.pageBreakAfter === 'always' || n.classList.contains('page-break')) forced.push(Math.round(b));
      if (cs.breakBefore === 'page' || cs.pageBreakBefore === 'always') forced.push(Math.round(a));
    }
    forced = forced.filter(function (y) { return y > 4 && y < H - 4; }).sort(function (p, q) { return p - q; });
    var cuts = [];
    var start = 0;
    while (start < H - 1) {
      var limit = Math.min(H, start + Math.floor(pagePx));
      var f = forced.filter(function (y) { return y > start + 4 && y <= limit; })[0];
      var end;
      if (f) {
        end = f;
      } else if (limit >= H) {
        end = H;
      } else {
        end = limit;
        var lowest = start + Math.floor(pagePx * 0.45);
        while (end > lowest && covered[end]) end--;
        if (end <= lowest) end = limit;    // nothing to cut between: cut at the page bottom
      }
      cuts.push([start, end]);
      start = end;
    }
    return { cuts: cuts, height: H };
  }

  async function save(btn) {
    var root = document.querySelector(btn.getAttribute('data-pdf-root') || '.print-page');
    if (!root) { alert('Nothing to save on this page.'); return; }
    var label = btn.textContent;
    var say = function (t) { btn.textContent = t; };
    btn.disabled = true;
    say('Preparing PDF…');
    var frame = null;
    try {
      await libs();
      var paper = paperOf(btn, root);
      var cw = paper[0] - MARGIN * 2, ch = paper[1] - MARGIN * 2;   // printable area in mm
      var widthPx = Math.round(cw * MM);
      frame = await buildFrame(root, widthPx);
      var d = frame.contentDocument;
      if (d.fonts && d.fonts.ready) { await d.fonts.ready; }
      await wait(60);
      var el = d.getElementById('pdf-root');
      // a table wider than the paper (a wide report) is laid out at its own width and the page is shrunk to fit,
      // instead of cutting off the columns on the right
      var need = widthPx;
      var left0 = el.getBoundingClientRect().left;
      Array.prototype.forEach.call(el.querySelectorAll('table, img, .reg, .doc-table'), function (t) {
        need = Math.max(need, Math.ceil(t.getBoundingClientRect().right - left0), Math.ceil(t.scrollWidth || 0));
      });
      if (need > widthPx + 2) {
        widthPx = Math.min(need + 4, widthPx * 3);
        el.style.setProperty('width', widthPx + 'px', 'important');
        await wait(30);
      }
      var pxmm = widthPx / cw;            // CSS px per mm of paper
      var pagePx = ch * pxmm;
      var plan = pageCuts(d, el, pagePx);
      var total = plan.cuts.length;
      var scale = total > 60 ? 1.25 : (total > 25 ? 1.6 : 2);
      var Pdf = window.jspdf.jsPDF;
      var pdf = new Pdf({ orientation: paper[0] > paper[1] ? 'landscape' : 'portrait', unit: 'mm', format: [paper[0], paper[1]], compress: true });
      var box = el.getBoundingClientRect();
      var docTop = box.top + d.defaultView.scrollY, docLeft = box.left + d.defaultView.scrollX;
      var page = 0;
      // draw a few pages at a time, then slice them
      for (var i = 0; i < plan.cuts.length;) {
        var j = i, chunkTop = plan.cuts[i][0];
        while (j < plan.cuts.length && plan.cuts[j][1] - chunkTop <= CHUNK) j++;
        if (j === i) j = i + 1;
        var chunkEnd = plan.cuts[j - 1][1];
        say('Preparing PDF… ' + Math.min(total, page + 1) + ' / ' + total);
        var canvas = await window.html2canvas(el, {
          scale: scale, backgroundColor: '#ffffff', logging: false, useCORS: true,
          windowWidth: FRAME_W, windowHeight: 1200,
          x: docLeft, y: docTop + chunkTop, width: widthPx, height: Math.ceil(chunkEnd - chunkTop), scrollX: 0, scrollY: 0
        });
        for (var k = i; k < j; k++) {
          var a = plan.cuts[k][0] - chunkTop, b = plan.cuts[k][1] - chunkTop;
          var hPx = Math.max(1, Math.round((b - a) * scale));
          var pc = document.createElement('canvas');
          pc.width = canvas.width;
          pc.height = hPx;
          var g = pc.getContext('2d');
          g.fillStyle = '#ffffff';
          g.fillRect(0, 0, pc.width, pc.height);
          g.drawImage(canvas, 0, Math.round(a * scale), canvas.width, hPx, 0, 0, pc.width, hPx);
          if (page > 0) { pdf.addPage([paper[0], paper[1]], paper[0] > paper[1] ? 'landscape' : 'portrait'); }
          pdf.addImage(pc.toDataURL('image/jpeg', 0.92), 'JPEG', MARGIN, MARGIN, cw, (b - a) / pxmm, undefined, 'FAST');
          page++;
        }
        i = j;
        await wait(0);
      }
      say('Saving…');
      pdf.save(fileName(btn));
    } catch (e) {
      alert('The PDF could not be made: ' + (e && e.message ? e.message : e) + '\nUse Print instead.');
    } finally {
      if (frame && frame.parentNode) { frame.parentNode.removeChild(frame); }
      btn.disabled = false;
      btn.textContent = label;
    }
  }

  document.addEventListener('click', function (e) {
    var p = e.target.closest ? e.target.closest('[data-print]') : null;
    if (p) { e.preventDefault(); window.print(); return; }
    var s = e.target.closest ? e.target.closest('[data-save-pdf]') : null;
    if (s && !s.disabled) { e.preventDefault(); save(s); }
  });
})();
