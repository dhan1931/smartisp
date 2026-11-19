// Precios del panel (assets/js/admin/pricing.js): mismos casos que tests/assert-pricing.php.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const window = {};
vm.runInNewContext(readFileSync(new URL('../assets/js/admin/pricing.js', import.meta.url), 'utf8'), { window, globalThis: window });
const P = window.SmartPricing;

const cases = [[100, 143.75], [10, 14.38], [1, 1.44], [0.99, 1.42], [57.31, 82.38], [1234.56, 1774.68], [0, 0], [-5, 0], ['', 0]];
for (const [cost, expected] of cases) assert.equal(P.applyIncrements(cost), expected, `costo ${cost}`);
assert.equal(P.applyIncrements(100, [15, 15]), 132.25);
assert.notEqual(P.applyIncrements(100), 140, 'no es un 40 % directo');
assert.equal(P.applyIncrements(100, [30]), 130);
assert.equal(Math.round(P.factor() * 10000) / 10000, 1.4375);
assert.equal(P.withIva(115), 132.25);
assert.equal(P.withoutIva(132.25), 115);
assert.equal(P.guessSupplier(['NW001UBQ79', 'ES215HIK67', '100018908', 'MM111KLX76-B1']), 'Intcomex', 'códigos de Intcomex');
assert.equal(P.guessSupplier(['TPLCAMIP0TAPOC500', 'HPICOMPORD66V1LT#ABM', 'LG0TELUHD55QNED70BSA']), 'Siglo21', 'códigos de Siglo21');
assert.equal(P.guessSupplier(['NW001UBQ79', 'TAPOC500', 'DS-2CD2143G2-IS']), '', 'sin mayoría clara no adivina');
assert.equal(P.guessSupplier([]), '');
console.log(`pricing OK (${cases.length} casos)`);
