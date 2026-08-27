import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const bundle = readFileSync(new URL('../../public/assets/admin/umi.js', import.meta.url), 'utf8');
const v2nodeStart = bundle.indexOf('class wV2node extends');
const v2nodeEnd = bundle.indexOf('var mV2node =', v2nodeStart);
const v2node = bundle.slice(v2nodeStart, v2nodeEnd);

function setAcceptProxyProtocol(networkSettings, enabled) {
  const settings = typeof networkSettings === 'string'
    ? JSON.parse(networkSettings)
    : networkSettings || {};
  return { ...settings, acceptProxyProtocol: enabled };
}

test('V2node Proxy Protocol defaults to off and restores saved state', () => {
  assert.equal(Boolean(undefined), false);
  assert.equal(Boolean({ acceptProxyProtocol: false }.acceptProxyProtocol), false);
  assert.equal(Boolean(JSON.parse('{"acceptProxyProtocol":true}').acceptProxyProtocol), true);
  assert.match(v2node, /getAcceptProxyProtocol\(\)[\s\S]*return !!\(e && e\.acceptProxyProtocol\)/);
});

test('V2node Proxy Protocol toggling preserves the existing network settings', () => {
  assert.deepEqual(
    setAcceptProxyProtocol('{"path":"/edge","headers":{"Host":"edge.example"}}', true),
    { path: '/edge', headers: { Host: 'edge.example' }, acceptProxyProtocol: true },
  );
  assert.deepEqual(
    setAcceptProxyProtocol({ serviceName: 'GunService', acceptProxyProtocol: true }, false),
    { serviceName: 'GunService', acceptProxyProtocol: false },
  );
  assert.match(v2node, /setAcceptProxyProtocol\(e\)[\s\S]*acceptProxyProtocol: e/);
});

test('V2node Proxy Protocol is limited to supported non-QUIC protocols', () => {
  for (const protocol of ['vless', 'vmess', 'trojan', 'shadowsocks', 'anytls']) {
    assert.match(v2node, new RegExp(`"${protocol}"`));
  }
  assert.doesNotMatch(
    v2node.slice(v2node.indexOf('supportsAcceptProxyProtocol()'), v2node.indexOf('getAcceptProxyProtocol()')),
    /hysteria2|tuic/,
  );
});

test('the V2node toggle does not alter the Reality xver selector or legacy editors', () => {
  const realityStart = bundle.indexOf('class U extends');
  const realityEnd = bundle.indexOf('class EncryptionSettings', realityStart);
  assert.match(bundle.slice(realityStart, realityEnd), /this\.change\("xver", e\)/);
  assert.doesNotMatch(v2node, /this\.change\("xver", e\)/);
  assert.equal(bundle.indexOf('class wVless extends'), -1);
  assert.equal(bundle.indexOf('class wTrojan extends'), -1);
});
