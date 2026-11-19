// Cálculo de precios compartido por el panel y las pruebas. Igual que smartispPriceFromCost (api/lib/shop.php).
// También reconoce de qué proveedor es una lista por el formato de sus códigos.
(function (root) {
  const IVA_RATE = 0.15;
  const DEFAULT_INCREMENTS = [25, 15];
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
  // Intcomex: NW001UBQ79 (sufijo opcional -B1) o 1000 + 5 dígitos. Siglo21: 9 caracteres de marca, tipo y subtipo
  // (TPLCAMIP0) y después el número de parte. Sin una mayoría clara no se adivina.
  const INTCOMEX_SKU = /^([A-Z]{2}\d{3}[A-Z0-9]{3}\d{2}(-[A-Z0-9]{1,3})?|1000\d{5})$/;
  const SIGLO21_SKU = /^[A-Z0-9]{3}[A-Z]{3}[A-Z0-9]{3}.{2,}$/;
  const guessSupplier = skus => {
    const list = (skus || []).map(s => String(s || '').trim().toUpperCase()).filter(Boolean);
    if (!list.length) return '';
    const share = re => list.filter(s => re.test(s)).length / list.length;
    if (share(INTCOMEX_SKU) >= 0.6) return 'Intcomex';
    return share(SIGLO21_SKU) >= 0.8 ? 'Siglo21' : '';
  };
  const api = { IVA_RATE, DEFAULT_INCREMENTS, round2, applyIncrements, factor, withIva, withoutIva, guessSupplier };
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.SmartPricing = api;
})(typeof window !== 'undefined' ? window : globalThis);
