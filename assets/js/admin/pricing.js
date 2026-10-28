// Cálculo de precios compartido por el panel y las pruebas. Igual que smartispPriceFromCost (api/lib/shop.php).
(function (root) {
  const IVA_RATE = 0.15;
  const DEFAULT_INCREMENTS = [15, 15];
  const round2 = value => Math.round(Number(Number(value).toPrecision(12)) * 100) / 100;
  // Cada incremento se aplica sobre el resultado del anterior y se redondea una sola vez al final.
  const applyIncrements = (base, increments = DEFAULT_INCREMENTS) => {
    const start = Number(base);
    if (!(start > 0)) return 0;
    return round2(increments.reduce((value, pct) => value * (1 + (Number(pct) || 0) / 100), start));
  };
  const factor = (increments = DEFAULT_INCREMENTS) => increments.reduce((value, pct) => value * (1 + (Number(pct) || 0) / 100), 1);
  const withIva = value => applyIncrements(value, [IVA_RATE * 100]);
  const withoutIva = value => round2(Number(value) / (1 + IVA_RATE));
  const api = { IVA_RATE, DEFAULT_INCREMENTS, round2, applyIncrements, factor, withIva, withoutIva };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.SmartPricing = api;
})(typeof window !== 'undefined' ? window : globalThis);
