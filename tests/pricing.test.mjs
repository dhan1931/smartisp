// Precios del panel (assets/js/admin/pricing.js): mismos casos que tests/assert-pricing.php.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const window = {};
vm.runInNewContext(readFileSync(new URL('../assets/js/admin/pricing.js', import.meta.url), 'utf8'), { window, globalThis: window });
const P = window.SmartPricing;

const cases = [[100, 132.25], [10, 13.23], [1, 1.32], [0.99, 1.31], [57.31, 75.79], [1234.56, 1632.71], [0, 0], [-5, 0], ['', 0]];
for (const [cost, expected] of cases) assert.equal(P.applyIncrements(cost), expected, `costo ${cost}`);
assert.equal(P.applyIncrements(100, [15, 15]), 132.25);
assert.notEqual(P.applyIncrements(100), 130, 'no es un 30 % directo');
assert.equal(P.applyIncrements(100, [30]), 130);
assert.equal(Math.round(P.factor() * 10000) / 10000, 1.3225);
assert.equal(P.withIva(115), 132.25);
assert.equal(P.withoutIva(132.25), 115);
console.log(`pricing OK (${cases.length} casos)`);
