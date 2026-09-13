/* Focused lifecycle check: one print-mode click performs one fetch and one Blob download. */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const sourcePath = path.resolve(__dirname, '..', 'assets', 'js', 'frontend.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const listeners = {};
const fetches = [];
const downloads = [];
const revokedUrls = [];
const deferredFetches = [];

function classList() {
  return { add() {}, remove() {}, toggle() {} };
}

const status = { hidden: true, textContent: '', classList: classList() };
const closeButton = { focus() {} };
const modal = {
  hidden: false,
  _didarPreviousFocus: null,
  classList: classList(),
  querySelector(selector) {
    if (selector === '[data-didar-pdf-status]') return status;
    if (selector === '.didar-pdf-modal__close') return closeButton;
    return null;
  },
};
const trigger = {
  getAttribute(name) {
    if (name === 'data-didar-pdf-url') return 'https://example.test/wp-admin/admin-post.php?action=didar_download_pdf&submission_id=25&_wpnonce=test';
    return '';
  },
  focus() {},
};
const documentMock = {
  baseURI: 'https://example.test/request/25',
  body: {
    classList: classList(),
    appendChild(node) { node._appended = true; },
  },
  addEventListener(type, handler) {
    (listeners[type] ||= []).push(handler);
  },
  createElement(tag) {
    assert.strictEqual(tag, 'a', 'Only one temporary download anchor may be created.');
    return {
      click() { downloads.push({ href: this.href, filename: this.download }); },
      remove() { this._removed = true; },
    };
  },
  querySelectorAll() { return []; },
  querySelector() { return null; },
  getElementById() { return modal; },
};
const NativeURL = URL;
function URLMock(url, base) { return new NativeURL(url, base); }
URLMock.createObjectURL = function () { return 'blob:pdf-' + (downloads.length + 1); };
URLMock.revokeObjectURL = function (url) { revokedUrls.push(url); };
const windowMock = {
  URL: URLMock,
  fetch(url, options) {
    fetches.push({ url, options });
    return new Promise((resolve, reject) => deferredFetches.push({ resolve, reject }));
  },
  setTimeout(callback) { callback(); },
};

vm.runInNewContext(source, {
  document: documentMock,
  window: windowMock,
  URL: URLMock,
  Event: function Event() {},
  FormData: function FormData() {},
  URLSearchParams,
  Promise,
  console,
});

assert.strictEqual(listeners.click.length, 1, 'PDF click handling must be delegated once.');
assert.strictEqual(source.includes('window.open('), false, 'PDF download must not open a popup.');
assert.strictEqual(source.includes('window.location'), false, 'PDF download must not navigate the current page.');

function pdfResponse(filename) {
  return {
    ok: true,
    headers: {
      get(name) {
        if (name === 'Content-Type') return 'application/pdf';
        if (name === 'Content-Disposition') return 'attachment; filename="' + filename + '"';
        return null;
      },
    },
    blob() { return Promise.resolve({ type: 'application/pdf' }); },
  };
}

function clickPdfMode(button) {
  modal._didarPreviousFocus = trigger;
  let prevented = 0;
  listeners.click[0]({
    target: button,
    preventDefault() { prevented += 1; },
  });
  assert.strictEqual(prevented, 1, 'The mode click must prevent native/default navigation.');
}

function modeButton(mode) {
  return {
    disabled: false,
    getAttribute(name) { return name === 'data-didar-pdf-mode' ? mode : ''; },
    setAttribute() {},
    removeAttribute() {},
    closest(selector) {
      if (selector === '[data-didar-pdf-mode]') return this;
      if (selector === '[data-didar-pdf-modal]') return modal;
      return null;
    },
  };
}

async function completeNextFetch(filename) {
  deferredFetches.shift().resolve(pdfResponse(filename));
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
}

async function completeNextResponse(response) {
  deferredFetches.shift().resolve(response);
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
}

(async function () {
  const withFiles = modeButton('1');
  clickPdfMode(withFiles);
  clickPdfMode(withFiles);
  assert.strictEqual(fetches.length, 1, 'Rapid double-click must leave only one in-flight fetch.');
  assert.strictEqual(withFiles.disabled, true, 'The clicked button must stay disabled during fetch.');
  assert.strictEqual(new NativeURL(fetches[0].url).searchParams.get('include_files'), '1');
  assert.strictEqual(fetches[0].options.credentials, 'same-origin');
  await completeNextFetch('with-files.pdf');
  assert.strictEqual(downloads.length, 1, 'With-files click must create one browser download.');
  assert.strictEqual(downloads[0].filename, 'with-files.pdf');
  assert.strictEqual(withFiles.disabled, false, 'The button must re-enable after download starts.');

  const withoutFiles = modeButton('0');
  clickPdfMode(withoutFiles);
  assert.strictEqual(fetches.length, 2, 'Without-files click must make exactly one additional fetch.');
  assert.strictEqual(new NativeURL(fetches[1].url).searchParams.get('include_files'), '0');
  await completeNextFetch('without-files.pdf');
  assert.strictEqual(downloads.length, 2, 'Without-files click must create one browser download.');
  assert.strictEqual(downloads[1].filename, 'without-files.pdf');

  const invalidResponse = modeButton('0');
  clickPdfMode(invalidResponse);
  await completeNextResponse({
    ok: true,
    headers: { get() { return 'text/html'; } },
    blob() { throw new Error('must_not_read_html_as_pdf'); },
  });
  assert.strictEqual(downloads.length, 2, 'An HTML response must never be downloaded as a PDF.');
  assert.strictEqual(invalidResponse.disabled, false, 'The button must re-enable after a failed response.');
  assert.strictEqual(status.textContent, 'دریافت فایل PDF ناموفق بود. دوباره تلاش کنید.');

  assert.strictEqual(listeners.click.length, 1, 'Modal reuse must not accumulate click listeners.');
  assert.strictEqual(revokedUrls.length, 2, 'Every temporary Blob URL must be revoked once.');
  console.log(JSON.stringify({
    modes: ['with_files', 'without_files'],
    delegated_click_handlers: listeners.click.length,
    fetches: fetches.length,
    anchor_clicks: downloads.length,
    double_click_fetches: 1,
    invalid_html_downloads: 0,
    page_navigation: 0,
    pass: true,
  }));
})().catch(function (error) {
  console.error(error.stack || error);
  process.exitCode = 1;
});
