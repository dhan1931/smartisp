/**
 * Gráficos del panel en SVG (una serie y un eje por gráfico). Estilos en admin-page.css.
 *   SmartCharts.columns(box, points, { key, format, axis, label, detail, emptyText })
 *   SmartCharts.bars(rows, emptyText) -> HTML; rows: [{ label, value, valueText, extra, href }]
 */
(function () {
  'use strict';

  var esc = function (v) { return window.SmartAdmin.esc(v); };

  // Tope del eje = 4 pasos "redondos" (1, 2, 2.5 o 5 × potencia de 10), el más chico que cubre el máximo.
  function niceMax(max) {
    if (!(max > 0)) return 4;
    var rawStep = max / 4;
    var pow = Math.pow(10, Math.floor(Math.log10(rawStep)));
    var step = [1, 2, 2.5, 5, 10].find(function (s) { return s * pow >= rawStep; }) * pow;
    return step * 4;
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
      var x = cx - barW / 2, r = Math.min(4, barW / 2, h);
      // Extremo de datos redondeado (4px), base recta sobre la línea de cero.
      var path = h <= 0 ? '' : 'M' + x + ',' + (top + h) + 'V' + (top + r) + 'Q' + x + ',' + top + ' ' + (x + r) + ',' + top +
        'H' + (x + barW - r) + 'Q' + (x + barW) + ',' + top + ' ' + (x + barW) + ',' + (top + r) + 'V' + (top + h) + 'Z';
      svg += '<rect class="hit" x="' + (m.left + slot * i) + '" y="' + m.top + '" width="' + slot + '" height="' + plotH + '" tabindex="0" data-i="' + i + '" aria-label="' + esc(p.title) + ': ' + esc(format(v)) + '"/>';
      svg += '<path class="col" d="' + path + '"/>';
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
      tip.style.left = Math.min(Math.max(0, r.left - b.left + r.width / 2 - tip.offsetWidth / 2), b.width - tip.offsetWidth) + 'px';
      tip.style.top = '0px';
    };
    var hide = function () { tip.hidden = true; };
    box.querySelectorAll('.hit').forEach(function (hit) {
      hit.addEventListener('pointerenter', function () { show(hit); });
      hit.addEventListener('focus', function () { show(hit); });
      hit.addEventListener('pointerleave', hide);
      hit.addEventListener('blur', hide);
    });
  }

  function bars(rows, emptyText) {
    if (!rows.length) return '<p class="muted">' + esc(emptyText) + '</p>';
    var max = Math.max.apply(null, rows.map(function (r) { return r.value; })) || 1;
    return rows.map(function (r) {
      var tag = r.href ? 'a' : 'div';
      return '<' + tag + ' class="bar-row"' + (r.href ? ' href="' + esc(r.href) + '"' : '') + ' title="' + esc(r.label) + '">' +
        '<span class="label">' + esc(r.label) + '</span>' +
        '<span class="value">' + r.valueText + (r.extra ? ' <small>· ' + r.extra + '</small>' : '') + '</span>' +
        '<div class="bar-track"><div class="bar-fill" style="width:' + Math.max(1, (r.value / max) * 100) + '%"></div></div>' +
      '</' + tag + '>';
    }).join('');
  }

  // Redibuja los gráficos de columnas al cambiar el ancho de la ventana.
  function onResize(render) {
    var timer;
    window.addEventListener('resize', function () { clearTimeout(timer); timer = setTimeout(render, 150); });
  }

  window.SmartCharts = { niceMax: niceMax, columns: columns, bars: bars, onResize: onResize };
})();
