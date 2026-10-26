/**
 * Gráficos del panel en SVG propio (sin librerías). Un eje por gráfico; colores por token CSS.
 *   SmartCharts.columns(box, points, { key, format, axis, label, detail, emptyText })
 *   SmartCharts.combo(box, points, { panels: [{ key, type: 'line'|'bars', label, format, axis, color, prevKey }], label, title(p) })
 *   SmartCharts.sparkline(values, { label }) -> SVG
 *   SmartCharts.bars(rows, emptyText, { ranked, color }) -> HTML; rows: [{ label, value, valueText, extra, href }]
 *   SmartCharts.stack(rows, { format }) -> HTML; rows: [{ label, value, color, extra }]
 *   SmartCharts.heatmap(box, grid, { rows, cols, format, label })
 */
(function () {
  'use strict';

  var esc = function (v) { return window.SmartAdmin ? window.SmartAdmin.esc(v) : String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var reduced = function () { return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches; };

  function niceMax(max) {
    if (!(max > 0)) return 4;
    var rawStep = max / 4;
    var pow = Math.pow(10, Math.floor(Math.log10(rawStep)));
    var step = [1, 2, 2.5, 5, 10].find(function (s) { return s * pow >= rawStep; }) * pow;
    return step * 4;
  }

  // Columna con extremo de datos redondeado (4px) y base recta sobre el cero.
  function colPath(x, top, w, h) {
    if (h <= 0) return '';
    var r = Math.min(4, w / 2, h);
    return 'M' + x + ',' + (top + h) + 'V' + (top + r) + 'Q' + x + ',' + top + ' ' + (x + r) + ',' + top +
      'H' + (x + w - r) + 'Q' + (x + w) + ',' + top + ' ' + (x + w) + ',' + (top + r) + 'V' + (top + h) + 'Z';
  }

  function placeTip(tip, box, x) {
    var b = box.getBoundingClientRect();
    var left = Math.min(Math.max(0, x - tip.offsetWidth / 2), b.width - tip.offsetWidth);
    tip.style.left = left + 'px';
    tip.style.top = '0px';
  }

  function columns(box, points, opts) {
    var key = opts.key;
    var format = opts.format || String;
    var axis = opts.axis || format;
    var total = points.reduce(function (sum, p) { return sum + (Number(p[key]) || 0); }, 0);
    if (!points.length || total === 0) {
      box.innerHTML = '<div class="chart-empty">' + esc(opts.emptyText || 'Sin datos en este periodo.') + '</div>';
      return;
    }
    var width = box.clientWidth, height = box.clientHeight;
    var m = { top: 10, right: 6, bottom: 26, left: 52 };
    var plotW = width - m.left - m.right, plotH = height - m.top - m.bottom;
    var max = niceMax(Math.max.apply(null, points.map(function (p) { return Number(p[key]) || 0; })));
    var slot = plotW / points.length;
    var barW = Math.max(2, Math.min(24, slot - 2));
    var y = function (v) { return m.top + plotH - (v / max) * plotH; };
    var labelEvery = Math.ceil(points.length / Math.max(2, Math.floor(plotW / 70)));
    var svg = '<svg viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + esc(opts.label || '') + '">';
    [0, 1, 2, 3, 4].forEach(function (i) {
      var t = (max / 4) * i;
      svg += '<line class="' + (i === 0 ? 'baseline' : 'grid-line') + '" x1="' + m.left + '" x2="' + (width - m.right) + '" y1="' + y(t) + '" y2="' + y(t) + '"/>';
      svg += '<text class="tick" x="' + (m.left - 8) + '" y="' + (y(t) + 4) + '" text-anchor="end">' + esc(axis(t)) + '</text>';
    });
    points.forEach(function (p, i) {
      var v = Number(p[key]) || 0, cx = m.left + slot * i + slot / 2, top = y(v), h = m.top + plotH - top;
      svg += '<rect class="hit" x="' + (m.left + slot * i) + '" y="' + m.top + '" width="' + slot + '" height="' + plotH + '" tabindex="0" data-i="' + i + '" aria-label="' + esc(p.title) + ': ' + esc(format(v)) + '"/>';
      svg += '<path class="col" d="' + colPath(cx - barW / 2, top, barW, h) + '"/>';
      if (i % labelEvery === 0) svg += '<text class="tick" x="' + cx + '" y="' + (height - 6) + '" text-anchor="middle">' + esc(p.label) + '</text>';
    });
    svg += '</svg><div class="tooltip" hidden></div>';
    box.innerHTML = svg;
    var tip = box.querySelector('.tooltip');
    var show = function (target) {
      var p = points[Number(target.dataset.i)];
      var detail = opts.detail ? opts.detail(p) : '';
      tip.innerHTML = '<strong>' + esc(format(Number(p[key]) || 0)) + '</strong><span>' + esc(p.title) + '</span>' + (detail ? '<br><span>' + esc(detail) + '</span>' : '');
      tip.hidden = false;
      var r = target.getBoundingClientRect(), b = box.getBoundingClientRect();
      placeTip(tip, box, r.left - b.left + r.width / 2);
    };
    var hide = function () { tip.hidden = true; };
    box.querySelectorAll('.hit').forEach(function (hit) {
      hit.addEventListener('pointerenter', function () { show(hit); });
      hit.addEventListener('focus', function () { show(hit); });
      hit.addEventListener('pointerleave', hide);
      hit.addEventListener('blur', hide);
    });
  }

  /*
   * Paneles apilados con el mismo eje X (p. ej. ingresos en línea arriba, pedidos en barras abajo):
   * cada panel tiene su propia escala, así no hay doble eje en un mismo gráfico. Cruz y tooltip
   * compartidos; flechas del teclado recorren los puntos.
   */
  function combo(box, points, opts) {
    var panels = opts.panels;
    var hasData = points.length && panels.some(function (pn) { return points.some(function (p) { return Number(p[pn.key]) > 0; }); });
    if (!hasData) {
      box.innerHTML = '<div class="chart-empty" style="height:' + (opts.height || 300) + 'px">' + esc(opts.emptyText || 'Sin movimiento en este periodo.') + '</div>';
      return;
    }
    var width = Math.max(280, box.clientWidth);
    var gap = 22;
    var heights = panels.map(function (pn) { return pn.height || 120; });
    var m = { top: 18, right: 10, bottom: 24, left: 56 };
    var height = m.top + heights.reduce(function (a, b) { return a + b; }, 0) + gap * (panels.length - 1) + m.bottom;
    var plotW = width - m.left - m.right;
    var n = points.length;
    var slot = plotW / n;
    var xc = function (i) { return m.left + slot * i + slot / 2; };
    var labelEvery = Math.ceil(n / Math.max(2, Math.floor(plotW / 64)));
    var svg = '<svg viewBox="0 0 ' + width + ' ' + height + '" height="' + height + '" role="img" aria-label="' + esc(opts.label || '') + '"' + (reduced() ? '' : ' class="sc-draw"') + '>';
    var top = m.top;
    var scales = [];
    panels.forEach(function (pn, pi) {
      var h = heights[pi];
      var vals = points.map(function (p) { return Number(p[pn.key]) || 0; });
      if (pn.prevKey) vals = vals.concat(points.map(function (p) { return Number(p[pn.prevKey]) || 0; }));
      var max = niceMax(Math.max.apply(null, vals));
      var y0 = top + h;
      var y = function (v) { return y0 - (v / max) * h; };
      scales.push({ y: y, top: top, h: h });
      var axis = pn.axis || pn.format || String;
      [0, 2, 4].forEach(function (k) {
        var t = (max / 4) * k;
        svg += '<line class="' + (k === 0 ? 'baseline' : 'grid-line') + '" x1="' + m.left + '" x2="' + (width - m.right) + '" y1="' + y(t) + '" y2="' + y(t) + '"/>';
        svg += '<text class="tick" x="' + (m.left - 8) + '" y="' + (y(t) + 4) + '" text-anchor="end">' + esc(axis(t)) + '</text>';
      });
      svg += '<text class="panel-label" x="' + m.left + '" y="' + (top - 6) + '">' + esc(pn.label) + '</text>';
      var color = pn.color || 'var(--series-1)';
      if (pn.type === 'bars') {
        var bw = Math.max(2, Math.min(22, slot - 3));
        points.forEach(function (p, i) {
          var v = Number(p[pn.key]) || 0;
          var yt = y(v);
          svg += '<path class="bar" style="fill:' + color + ';animation-delay:' + Math.min(400, i * 12) + 'ms" d="' + colPath(xc(i) - bw / 2, yt, bw, y0 - yt) + '"/>';
        });
      } else {
        var line = function (k) { return points.map(function (p, i) { return (i ? 'L' : 'M') + xc(i).toFixed(1) + ',' + y(Number(p[k]) || 0).toFixed(1); }).join(''); };
        if (pn.prevKey) svg += '<path class="line prev" d="' + line(pn.prevKey) + '"/>';
        var d = line(pn.key);
        svg += '<path class="area" style="fill:' + color + '" d="' + d + 'L' + xc(n - 1).toFixed(1) + ',' + y0 + 'L' + xc(0).toFixed(1) + ',' + y0 + 'Z"/>';
        svg += '<path class="line" style="stroke:' + color + '" d="' + d + '"/>';
        if (n <= 14) points.forEach(function (p, i) { if (Number(p[pn.key]) > 0) svg += '<circle class="dot" style="fill:' + color + '" cx="' + xc(i) + '" cy="' + y(Number(p[pn.key]) || 0) + '" r="3.5"/>'; });
      }
      top += h + gap;
    });
    points.forEach(function (p, i) {
      if (i % labelEvery === 0) svg += '<text class="tick" x="' + xc(i) + '" y="' + (height - 6) + '" text-anchor="middle">' + esc(p.label) + '</text>';
    });
    svg += '<line class="cross" x1="0" x2="0" y1="' + m.top + '" y2="' + (height - m.bottom) + '" visibility="hidden"/>';
    svg += '<g class="dots"></g>';
    svg += '<rect class="sc-hit" x="' + m.left + '" y="' + m.top + '" width="' + plotW + '" height="' + (height - m.top - m.bottom) + '" fill="transparent" tabindex="0" aria-label="Recorre los días con las flechas"/>';
    svg += '</svg><div class="tooltip" hidden></div>';
    box.classList.add('sc');
    box.innerHTML = svg;
    box.querySelectorAll('.line:not(.prev)').forEach(function (path) { try { path.style.setProperty('--len', Math.ceil(path.getTotalLength())); } catch (e) { /* sin medida */ } });

    var svgEl = box.querySelector('svg');
    var tip = box.querySelector('.tooltip');
    var cross = box.querySelector('.cross');
    var dots = box.querySelector('.dots');
    var hit = box.querySelector('.sc-hit');
    var current = -1;
    var show = function (i) {
      current = Math.max(0, Math.min(n - 1, i));
      var p = points[current];
      var x = xc(current);
      cross.setAttribute('x1', x); cross.setAttribute('x2', x); cross.setAttribute('visibility', 'visible');
      dots.innerHTML = panels.map(function (pn, pi) {
        if (pn.type === 'bars') return '';
        return '<circle class="dot" style="fill:' + (pn.color || 'var(--series-1)') + '" cx="' + x + '" cy="' + scales[pi].y(Number(p[pn.key]) || 0) + '" r="4.5"/>';
      }).join('');
      tip.innerHTML = '<span>' + esc(opts.title ? opts.title(p) : p.label) + '</span>' + panels.map(function (pn) {
        var f = pn.format || String;
        var row = '<div class="tt-row"><span><i class="sw" style="background:' + (pn.color || 'var(--series-1)') + '"></i> ' + esc(pn.label) + '</span><b>' + esc(f(Number(p[pn.key]) || 0)) + '</b></div>';
        if (pn.prevKey) row += '<div class="tt-row"><span>' + esc(pn.prevLabel || 'Periodo anterior') + '</span><b>' + esc(f(Number(p[pn.prevKey]) || 0)) + '</b></div>';
        return row;
      }).join('') + (opts.detail ? '<div class="tt-row"><span>' + esc(opts.detail(p)) + '</span></div>' : '');
      tip.hidden = false;
      var scale = svgEl.getBoundingClientRect().width / width;
      placeTip(tip, box, x * scale);
      tip.style.top = Math.max(0, (m.top - 4) * scale) + 'px';
      if (x * scale > box.clientWidth / 2) tip.style.left = Math.max(0, x * scale - tip.offsetWidth - 14) + 'px';
      else tip.style.left = Math.min(box.clientWidth - tip.offsetWidth, x * scale + 14) + 'px';
    };
    var hide = function () { tip.hidden = true; cross.setAttribute('visibility', 'hidden'); dots.innerHTML = ''; };
    var indexAt = function (clientX) {
      var r = svgEl.getBoundingClientRect();
      var x = (clientX - r.left) * (width / r.width);
      return Math.floor((x - m.left) / slot);
    };
    hit.addEventListener('pointermove', function (e) { show(indexAt(e.clientX)); });
    hit.addEventListener('pointerleave', hide);
    hit.addEventListener('focus', function () { show(current < 0 ? n - 1 : current); });
    hit.addEventListener('blur', hide);
    hit.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { e.preventDefault(); show(current + 1); }
      else if (e.key === 'ArrowLeft') { e.preventDefault(); show(current - 1); }
      else if (e.key === 'Home') { e.preventDefault(); show(0); }
      else if (e.key === 'End') { e.preventDefault(); show(n - 1); }
    });
  }

  function sparkline(values, opts) {
    opts = opts || {};
    var vals = (values || []).map(function (v) { return Number(v) || 0; });
    if (vals.length < 2 || !vals.some(function (v) { return v !== 0; })) return '<svg class="kpi-spark" viewBox="0 0 100 34" preserveAspectRatio="none" aria-hidden="true"><line x1="0" x2="100" y1="30" y2="30" stroke="var(--grid)" stroke-width="1.5"/></svg>';
    var w = 100, h = 34, pad = 3;
    var max = Math.max.apply(null, vals), min = Math.min(0, Math.min.apply(null, vals));
    var span = max - min || 1;
    var x = function (i) { return (i / (vals.length - 1)) * w; };
    var y = function (v) { return pad + (h - pad * 2) * (1 - (v - min) / span); };
    var d = vals.map(function (v, i) { return (i ? 'L' : 'M') + x(i).toFixed(2) + ',' + y(v).toFixed(2); }).join('');
    var color = opts.color ? ' style="stroke:' + opts.color + '"' : '';
    return '<svg class="kpi-spark" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img" aria-label="' + esc(opts.label || 'Tendencia') + '">' +
      '<path class="area" d="' + d + 'L' + w + ',' + h + 'L0,' + h + 'Z"/><path class="line"' + color + ' vector-effect="non-scaling-stroke" d="' + d + '"/></svg>';
  }

  function bars(rows, emptyText, opts) {
    opts = opts || {};
    if (!rows.length) return '<p class="muted">' + esc(emptyText) + '</p>';
    var max = Math.max.apply(null, rows.map(function (r) { return r.value; })) || 1;
    return rows.map(function (r, i) {
      var tag = r.href ? 'a' : 'div';
      return '<' + tag + ' class="bar-row"' + (r.href ? ' href="' + esc(r.href) + '"' : '') + ' title="' + esc(r.label) + '">' +
        '<span class="label">' + (opts.ranked ? '<span class="rank">' + (i + 1) + '</span>' : '') + esc(r.label) + '</span>' +
        '<span class="value">' + r.valueText + (r.extra ? ' <small>· ' + r.extra + '</small>' : '') + '</span>' +
        '<div class="bar-track"><div class="bar-fill" style="width:' + Math.max(1, (r.value / max) * 100) + '%;animation-delay:' + i * 40 + 'ms' + (opts.color ? ';background:' + opts.color : '') + '"></div></div>' +
      '</' + tag + '>';
    }).join('');
  }

  function stack(rows, opts) {
    opts = opts || {};
    var total = rows.reduce(function (s, r) { return s + (Number(r.value) || 0); }, 0);
    if (!total) return '<p class="muted">' + esc(opts.emptyText || 'Sin datos en este periodo.') + '</p>';
    var format = opts.format || String;
    var pct = function (v) { return Math.round((v / total) * 1000) / 10; };
    return '<div class="stack" role="img" aria-label="' + esc(opts.label || 'Distribución') + '">' + rows.filter(function (r) { return r.value > 0; }).map(function (r) {
      return '<span style="flex:' + r.value + ';background:' + r.color + '" title="' + esc(r.label + ': ' + format(r.value) + ' (' + pct(r.value) + '%)') + '"></span>';
    }).join('') + '</div><ul class="stack-legend">' + rows.map(function (r) {
      var inner = '<i class="sw" style="background:' + r.color + '"></i><span>' + esc(r.label) + (r.extra ? ' <small class="muted">' + esc(r.extra) + '</small>' : '') + '</span><b>' + esc(format(r.value)) + '</b><span class="pct">' + pct(r.value) + '%</span>';
      return '<li>' + (r.href ? '<a href="' + esc(r.href) + '" style="display:contents;color:inherit;text-decoration:none">' + inner + '</a>' : inner) + '</li>';
    }).join('') + '</ul>';
  }

  function heatmap(box, grid, opts) {
    opts = opts || {};
    var rows = opts.rows || ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    var full = opts.rowsFull || ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
    var format = opts.format || String;
    var max = 0;
    grid.forEach(function (r) { r.forEach(function (v) { if (v > max) max = v; }); });
    if (!max) { box.innerHTML = '<div class="chart-empty">' + esc(opts.emptyText || 'Sin pedidos en este periodo.') + '</div>'; return; }
    var html = '<div class="heatmap" role="grid" aria-label="' + esc(opts.label || 'Pedidos por día y hora') + '"><span></span>';
    for (var h = 0; h < 24; h++) html += '<span class="hm-hour" aria-hidden="true">' + (h % 3 === 0 ? h : '') + '</span>';
    grid.forEach(function (r, d) {
      html += '<span class="hm-day">' + rows[d] + '</span>';
      r.forEach(function (v, hr) {
        var i = v ? Math.max(.12, Math.sqrt(v / max)) : 0;
        html += '<span class="hm-cell" role="gridcell" tabindex="-1" data-v="' + v + '" style="--i:' + i.toFixed(3) + '" title="' + esc(full[d] + ' ' + hr + ':00–' + hr + ':59 · ' + format(v)) + '" aria-label="' + esc(full[d] + ' de ' + hr + ' a ' + (hr + 1) + ' horas: ' + format(v)) + '"></span>';
      });
    });
    html += '</div><div class="hm-scale"><span>Menos</span><i></i><span>Más (' + esc(format(max)) + ')</span></div>';
    box.innerHTML = html;
  }

  function onResize(render) {
    var timer;
    window.addEventListener('resize', function () { clearTimeout(timer); timer = setTimeout(render, 150); });
  }

  window.SmartCharts = { niceMax: niceMax, columns: columns, combo: combo, sparkline: sparkline, bars: bars, stack: stack, heatmap: heatmap, onResize: onResize };
})();
