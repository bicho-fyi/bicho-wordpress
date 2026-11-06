import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chmodSync, readFileSync, writeFileSync } from 'node:fs';
import puppeteer from 'puppeteer-core';
import axe from 'axe-core';

const variant = process.env.BICHO_WP_VARIANT || 'default';
assert(['default', 'dev', 'compat'].includes(variant));
const compose = ['compose', '-p', 'bicho-wordpress-test', '-f', 'docker-compose.yml', ...(variant === 'default' ? [] : ['-f', `docker-compose.${variant}.yml`]), '-f', 'tests/docker-compose.test.yml'];
const wp = code => execFileSync('docker', [...compose, 'exec', '-T', 'wp-cli', 'wp', '--allow-root', 'eval', code], { encoding: 'utf8' });
const config = (action, ...args) => execFileSync('docker', [...compose, 'exec', '-T', 'wp-cli', 'wp', '--allow-root', 'config', action, ...args], { encoding: 'utf8' });
const origin = `http://localhost:${{ default: 8896, dev: 8897, compat: 8898 }[variant]}`;
const eventsPath = '_temp/wordpress/events.jsonl';
const events = () => readFileSync(eventsPath, 'utf8').trim().split('\n').filter(Boolean).map(line => JSON.parse(line));
const settingsUrl = origin + '/wp-admin/tools.php?page=bicho-error-tracking';
const browser = await puppeteer.launch({ executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
const errors = [];
let overridden = false;
try {
  const cookies = JSON.parse(wp('$u=get_user_by("login","bicho");$expires=time()+600;$token=WP_Session_Tokens::get_instance($u->ID)->create($expires);echo wp_json_encode([["name"=>AUTH_COOKIE,"value"=>wp_generate_auth_cookie($u->ID,$expires,"auth",$token),"url"=>"http://localhost:8896/"],["name"=>LOGGED_IN_COOKIE,"value"=>wp_generate_auth_cookie($u->ID,$expires,"logged_in",$token),"url"=>"http://localhost:8896/"]]);'));
  await browser.defaultBrowserContext().setCookie(...cookies.map(cookie => ({ ...cookie, url: origin + '/' })));
  const page = await browser.newPage();
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(settingsUrl, { waitUntil: 'networkidle0' });
  assert.equal(new URL(page.url()).pathname, '/wp-admin/tools.php');
  assert(await page.$('form[action="options.php"] input[name="_wpnonce"]'));
  assert.equal(await page.$eval('[name="bicho_settings[php_enabled]"]', field => field.checked), false);
  assert.equal(await page.$eval('[name="bicho_settings[js_enabled]"]', field => field.checked), false);
  for (const width of [375, 1440]) {
    await page.setViewport({ width, height: 1000 });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.evaluate(axe.source);
    assert.deepEqual(await page.evaluate(async () => (await axe.run('#wpbody-content .wrap')).violations.filter(v => v.impact === 'serious' || v.impact === 'critical').map(v => ({ id: v.id, nodes: v.nodes.map(n => n.target) }))), []);
  }
  await page.screenshot({ path: '_temp/wordpress/settings.png', fullPage: true });
  await page.evaluate(() => {
    for (const [field, value] of Object.entries({ php_endpoint: 'http://127.0.0.1:8899/v1/logs', php_key: 'wordpress-php-fixture', php_secret: 'wordpress-secret-fixture', js_endpoint: 'http://localhost:8899/v1/logs', js_key: 'wordpress-js-fixture' })) {
      document.querySelector(`[name="bicho_settings[${field}]"]`).value = value;
    }
    document.querySelector('[name="bicho_settings[php_enabled]"]').checked = true;
    document.querySelector('[name="bicho_settings[js_enabled]"]').checked = true;
  });
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#submit')]);
  assert.equal(await page.$eval('[name="bicho_settings[js_enabled]"]', field => field.checked), true);
  assert.equal(await page.$eval('[name="bicho_settings[php_enabled]"]', field => field.checked), true);
  assert(!await page.content().then(html => html.includes('wordpress-secret-fixture')), 'Saved backend secret must not be redisplayed');
  assert.equal(await page.evaluate(async () => {
    const data = new URLSearchParams(new FormData(document.querySelector('form[action="options.php"]')));
    data.set('_wpnonce', 'invalid');
    data.set('bicho_settings[js_key]', 'must-not-save');
    return (await fetch('/wp-admin/options.php', { method: 'POST', body: data })).status;
  }), 403);
  assert.equal(wp('echo get_option("bicho_settings")["js_key"];').trim(), 'wordpress-js-fixture');

  writeFileSync(eventsPath, '');
  chmodSync(eventsPath, 0o666);
  wp('trigger_error("Bicho WordPress integration warning", E_USER_WARNING);');
  assert.equal(events().filter(event => event.key === 'wordpress-php-fixture').length, 1);
  const frontend = await browser.newPage();
  await frontend.goto(origin, { waitUntil: 'networkidle0' });
  assert(await frontend.evaluate(() => typeof window.Bicho === 'object'));
  const html = await frontend.content();
  assert(!html.includes('wordpress-secret-fixture') && !html.includes('wordpress-php-fixture'));
  const delivered = frontend.waitForResponse(response => response.url() === 'http://localhost:8899/v1/logs' && response.request().method() === 'POST');
  await frontend.evaluate(() => setTimeout(() => { throw new Error('Bicho WordPress integration browser error'); }, 0));
  assert.equal((await delivered).status(), 200);
  assert.equal(events().filter(event => event.key === 'wordpress-js-fixture').length, 1);
  assert(events().filter(event => event.key === 'wordpress-js-fixture').every(event => event.secret === ''));

  config('set', 'BICHO_JS_PUBLIC_KEY', 'constant-wordpress-fixture', '--type=constant');
  overridden = true;
  await page.waitForFunction(async url => {
    const html = await (await fetch(url, { cache: 'no-store' })).text();
    return new DOMParser().parseFromString(html, 'text/html').querySelector('[name="bicho_settings[js_key]"]')?.disabled;
  }, { polling: 500, timeout: 10000 }, settingsUrl);
  await page.goto(settingsUrl, { waitUntil: 'networkidle0' });
  assert.equal(await page.$eval('[name="bicho_settings[js_key]"]', field => field.disabled), true);
  assert((await page.$eval('#wpbody-content', el => el.textContent)).includes('BICHO_JS_PUBLIC_KEY'));
  await frontend.reload({ waitUntil: 'networkidle0' });
  assert((await frontend.content()).includes('constant-wordpress-fixture'));
  config('delete', 'BICHO_JS_PUBLIC_KEY');
  overridden = false;
  wp('$s=get_option("bicho_settings");$s["js_enabled"]=false;update_option("bicho_settings",$s,false);');
  await frontend.reload({ waitUntil: 'networkidle0' });
  assert.equal(await frontend.evaluate(() => typeof window.Bicho), 'undefined');
  const before = events().length;
  wp('trigger_error("Bicho PHP remains enabled", E_USER_WARNING);');
  assert.equal(events().length, before + 1);
  wp('$s=get_option("bicho_settings");$s["php_enabled"]=false;update_option("bicho_settings",$s,false);');
  const disabledCount = events().length;
  wp('trigger_error("Bicho disabled warning", E_USER_WARNING);');
  assert.equal(events().length, disabledCount);
  assert.deepEqual(errors, []);
  console.log('PASS: real WordPress Tools settings, nonce form save, separate SDK delivery, no browser secret, constant override, independent toggles, native mobile admin accessibility.');
} finally {
  if (overridden) config('delete', 'BICHO_JS_PUBLIC_KEY');
  wp('delete_option("bicho_settings");');
  await browser.close();
}
