'use strict';

/* DOM-lifecycle regression for Consultation preferred_time. Run with:
 * node tests/test-didar-consultation-time-picker.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const pluginRoot = path.resolve(__dirname, '..');

class ClassList {
  constructor() { this.values = new Set(); }
  add(...values) { values.forEach((value) => this.values.add(value)); }
  remove(...values) { values.forEach((value) => this.values.delete(value)); }
  contains(value) { return this.values.has(value); }
  toggle(value, force) { if (force === undefined ? !this.values.has(value) : force) this.add(value); else this.remove(value); }
  toString() { return Array.from(this.values).join(' '); }
}

function matches(node, selector) {
  return selector.split(',').some((part) => {
    const query = part.trim();
    if (!query) return false;
    if (query === '#post') return node.id === 'post';
    const tag = (query.match(/^[a-z]+/i) || [''])[0].toUpperCase();
    if (tag && node.tagName !== tag) return false;
    if (Array.from(query.matchAll(/\.([\w-]+)/g)).some((name) => !node.classList.contains(name[1]))) return false;
    return Array.from(query.matchAll(/\[([^\]=]+)(?:=["']?([^\]"']*)["']?)?\]/g)).every((attribute) => {
      const value = node.getAttribute(attribute[1]);
      return attribute[2] === undefined ? value !== null : value === attribute[2];
    });
  });
}

const documentEvents = Object.create(null);
class Element {
  constructor(tag) { this.tagName = tag.toUpperCase(); this.nodeType = 1; this.attributes = Object.create(null); this.children = []; this.parentNode = null; this.classList = new ClassList(); this.listeners = Object.create(null); this.hidden = false; this.value = ''; this.type = ''; this.disabled = false; }
  get id() { return this.getAttribute('id') || ''; }
  set id(value) { this.setAttribute('id', value); }
  get className() { return this.classList.toString(); }
  set className(value) { this.classList = new ClassList(); String(value || '').split(/\s+/).filter(Boolean).forEach((item) => this.classList.add(item)); }
  set textContent(value) { this.children = []; this._text = String(value); }
  get textContent() { return this._text || ''; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
  removeAttribute(name) { delete this.attributes[name]; }
  matches(selector) { return matches(this, selector); }
  closest(selector) { for (let node = this; node; node = node.parentNode) if (node.matches && node.matches(selector)) return node; return null; }
  appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
  contains(node) { return node === this || this.children.some((child) => child.contains(node)); }
  querySelectorAll(selector) { const result = []; const visit = (node) => node.children.forEach((child) => { if (child.matches(selector)) result.push(child); visit(child); }); visit(this); return result; }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
  dispatchEvent(event) { event.target ||= this; event.currentTarget = this; (this.listeners[event.type] || []).forEach((callback) => callback(event)); if (event.bubbles && !event.cancelBubble) { if (this.parentNode) this.parentNode.dispatchEvent(event); else (documentEvents[event.type] || []).forEach((callback) => callback(event)); } return !event.defaultPrevented; }
  focus() { this.focused = true; }
}
class DomEvent {
  constructor(type, options = {}) { this.type = type; Object.assign(this, options); this.defaultPrevented = false; this.cancelBubble = false; }
  preventDefault() { this.defaultPrevented = true; }
  stopPropagation() { this.cancelBubble = true; }
}
const root = new Element('body');
global.document = { body: root, createElement: (tag) => new Element(tag), querySelectorAll: (selector) => root.querySelectorAll(selector), querySelector: (selector) => root.querySelector(selector), getElementById: (id) => root.querySelector('[id="' + id + '"]'), addEventListener: (name, callback) => { (documentEvents[name] ||= []).push(callback); } };
global.window = { addEventListener: () => {} };
global.Event = DomEvent;
function node(tag, attributes = {}) { const item = new Element(tag); Object.entries(attributes).forEach(([name, value]) => item.setAttribute(name, value)); item.value = attributes.value || ''; item.type = attributes.type || ''; return item; }
function click(item) { item.dispatchEvent(new DomEvent('click', { bubbles: true })); }

const form = node('form', { 'data-didar-form': '1' });
const picker = node('div', { 'data-didar-time-picker': '', 'data-didar-time-step': '60', 'data-didar-time-placeholder': 'انتخاب ساعت' });
const canonical = node('input', { type: 'hidden', value: '18:45', 'data-didar-time-canonical': '1' });
const trigger = node('button', { type: 'button', 'data-didar-time-trigger': '', 'aria-expanded': 'false' });
const display = node('span', { 'data-didar-time-display': '' });
const popover = node('div', { 'data-didar-time-popover': '' }); popover.hidden = true;
const title = node('strong', { 'data-didar-time-title': '' });
const options = node('div', { 'data-didar-time-options': '' });
popover.appendChild(title); popover.appendChild(options); trigger.appendChild(display); picker.appendChild(canonical); picker.appendChild(trigger); picker.appendChild(popover); form.appendChild(picker); root.appendChild(form);

vm.runInThisContext(fs.readFileSync(path.join(pluginRoot, 'assets/js/form-input-rules.js'), 'utf8'));
(documentEvents.DOMContentLoaded || []).forEach((callback) => callback(new DomEvent('DOMContentLoaded')));
window.DidarFormInputRules.enhanceFormControls(document);

const initial = picker.getAttribute('data-didar-time-picker-initialized') === '1' && popover.hidden && trigger.getAttribute('aria-expanded') === 'false' && !picker.classList.contains('is-open') && display.textContent === '۱۸:۴۵';
const triggerListeners = (trigger.listeners.click || []).length === 1;
click(trigger);
const opens = !popover.hidden && picker.classList.contains('is-open') && trigger.getAttribute('aria-expanded') === 'true' && form.classList.contains('didar-time-picker-open');
const hours = options.querySelectorAll('[data-didar-time-hour]');
const hourOrdering = hours.slice(0, 4).map((hour) => hour.getAttribute('data-didar-time-hour')).join(',') === '00,01,02,03';
const singleDigitLabels = hours.slice(0, 4).map((hour) => hour.textContent).join(',') === '۰,۱,۲,۳';
const hour = options.querySelector('[data-didar-time-hour="14"]');
click(hour);
const minute = options.querySelector('[data-didar-time-minute="30"]');
click(minute);
const selectionValue = canonical.value === '14:30';
const selectionDisplay = display.textContent === '۱۴:۳۰';
const selectionClosed = popover.hidden && !picker.classList.contains('is-open') && trigger.getAttribute('aria-expanded') === 'false' && !form.classList.contains('didar-time-picker-open');
const selection = selectionValue && selectionDisplay && selectionClosed;
click(trigger);
root.dispatchEvent(new DomEvent('click', { bubbles: true }));
const outsideClose = popover.hidden && !picker.classList.contains('is-open');
click(trigger);
picker.dispatchEvent(new DomEvent('keydown', { key: 'Escape', bubbles: true }));
const escapeClose = popover.hidden && trigger.getAttribute('aria-expanded') === 'false';
const css = fs.readFileSync(path.join(pluginRoot, 'assets/css/frontend.css'), 'utf8');
const colors = /didar-app \.didar-time-picker__trigger \{[^}]*background: #fff/.test(css) && /didar-time-picker__trigger:hover,[\s\S]*?background: var\(--didar-accent\)/.test(css) && /didar-time-picker\.is-open \.didar-time-picker__trigger/.test(css) && /didar-time-picker__option:hover,[\s\S]*?background: var\(--didar-accent\)/.test(css) && /didar-time-picker__option:focus-visible \{ background: var\(--didar-accent\)/.test(css) && /didar-time-picker__option\.is-selected \{ background: var\(--didar-accent\)/.test(css) && !/didar-time-picker__trigger \{[^}]*background: #fff[^}]*transition:[^}]*background-color/.test(css) && /didar-form\.didar-time-picker-open \{ overflow: visible; \}/.test(css);
const gridDirection = /didar-time-picker__options \{ direction: ltr;/.test(css);
const result = { initial, triggerListeners, opens, hourOrdering, singleDigitLabels, selection, selectionValue, selectionDisplay, selectionClosed, outsideClose, escapeClose, colors, gridDirection };
result.all_pass = Object.values(result).every(Boolean);
console.log(JSON.stringify(result));
process.exitCode = result.all_pass ? 0 : 1;
