'use strict';

/*
 * DOM-lifecycle regression for the Visa template's real components. Run with:
 * node tests/test-didar-visa-history-lifecycle.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const pluginRoot = path.resolve(__dirname, '..');

class ClassList {
  constructor(node) { this.node = node; this.values = new Set(); }
  add(...names) { names.forEach((name) => this.values.add(name)); }
  remove(...names) { names.forEach((name) => this.values.delete(name)); }
  contains(name) { return this.values.has(name); }
  toggle(name, enabled) { if (enabled === undefined ? !this.values.has(name) : enabled) this.values.add(name); else this.values.delete(name); }
  toString() { return Array.from(this.values).join(' '); }
}

function selectorMatches(node, selector) {
  return selector.split(',').some((part) => {
    const query = part.trim();
    if (!query) return false;
    if (query === '#post') return node.id === 'post';
    const tag = (query.match(/^[a-z]+/i) || [''])[0].toUpperCase();
    if (tag && node.tagName !== tag) return false;
    const classes = Array.from(query.matchAll(/\.([\w-]+)/g)).map((match) => match[1]);
    if (classes.some((name) => !node.classList.contains(name))) return false;
    const attributes = Array.from(query.matchAll(/\[([^\]=]+)(?:=["']?([^\]"']*)["']?)?\]/g));
    return attributes.every((match) => {
      const value = node.getAttribute(match[1]);
      return match[2] === undefined ? value !== null : value === match[2];
    });
  });
}

class Element {
  constructor(tagName) {
    this.tagName = tagName.toUpperCase();
    this.nodeType = 1;
    this.attributes = Object.create(null);
    this.children = [];
    this.parentNode = null;
    this.classList = new ClassList(this);
    this.hidden = false;
    this.value = '';
    this.name = '';
    this.type = '';
    this.checked = false;
    this.multiple = false;
    this.disabled = false;
    this.options = this.tagName === 'SELECT' ? [] : undefined;
  }
  get id() { return this.getAttribute('id') || ''; }
  set id(value) { this.setAttribute('id', value); }
  get className() { return this.classList.toString(); }
  set className(value) { this.classList = new ClassList(this); String(value || '').split(/\s+/).filter(Boolean).forEach((name) => this.classList.add(name)); }
  set innerHTML(value) { this.children = []; this._innerHTML = value; }
  get innerHTML() { return this._innerHTML || ''; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
  removeAttribute(name) { delete this.attributes[name]; }
  hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name); }
  appendChild(child) { if (child.parentNode) child.parentNode.removeChild(child); child.parentNode = this; this.children.push(child); return child; }
  insertBefore(child, before) { if (child.parentNode) child.parentNode.removeChild(child); child.parentNode = this; const index = this.children.indexOf(before); this.children.splice(index < 0 ? this.children.length : index, 0, child); return child; }
  removeChild(child) { const index = this.children.indexOf(child); if (index >= 0) this.children.splice(index, 1); child.parentNode = null; return child; }
  contains(node) { return node === this || this.children.some((child) => child.contains(node)); }
  matches(selector) { return selectorMatches(this, selector); }
  closest(selector) { for (let node = this; node; node = node.parentNode) if (node.matches && node.matches(selector)) return node; return null; }
  querySelectorAll(selector) { const found = []; const walk = (node) => node.children.forEach((child) => { if (child.matches(selector)) found.push(child); walk(child); }); walk(this); return found; }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  addEventListener() {}
  removeEventListener() {}
  dispatchEvent() { return true; }
  focus() {}
  select() {}
}

const events = Object.create(null);
const documentRoot = new Element('body');
global.document = {
  body: documentRoot,
  createElement: (tagName) => new Element(tagName),
  getElementById: (id) => documentRoot.querySelector('[id="' + id + '"]'),
  querySelectorAll: (selector) => documentRoot.querySelectorAll(selector),
  querySelector: (selector) => documentRoot.querySelector(selector),
  addEventListener: (name, callback) => { (events[name] ||= []).push(callback); },
  removeEventListener: () => {},
};
global.window = { addEventListener: () => {} };
global.Event = class Event { constructor(type, options) { this.type = type; Object.assign(this, options || {}); } };

function element(tagName, attributes = {}) {
  const node = new Element(tagName);
  Object.entries(attributes).forEach(([name, value]) => node.setAttribute(name, value));
  if (attributes.name) node.name = attributes.name;
  if (attributes.type) node.type = attributes.type;
  if (attributes.value) node.value = attributes.value;
  return node;
}

const form = element('form', { 'data-didar-form': '1' });
documentRoot.appendChild(form);
function parentGroup(name) {
  const field = element('div', { 'data-didar-field': name });
  ['yes', 'no'].forEach((value) => field.appendChild(element('input', { name: 'didar_fields[' + name + ']', type: 'radio', value })));
  form.appendChild(field);
  return field;
}
const rejectionParent = parentGroup('has_rejection');
const schengenParent = parentGroup('has_previous_schengen');
function dependent(name, parentName, kind) {
  const field = element('div', { 'data-didar-field': name, 'data-didar-conditional-on': parentName, 'data-didar-conditional-value': 'yes', 'aria-hidden': 'true' });
  field.className = 'didar-field didar-conditional-hidden';
  field.hidden = true;
  if (kind === 'searchable') {
    field.appendChild(element('label', { for: name }));
    const select = element('select', { id: name, name: 'didar_fields[' + name + '][]', 'data-didar-searchable': '1' });
    select.multiple = true;
    field.appendChild(select);
  } else {
    field.appendChild(element('input', { id: name + '-date', name: 'didar_fields[' + name + ']', type: 'text', 'data-didar-datepicker': 'jalali', 'data-didar-date-target': name + '-canonical' }));
    field.appendChild(element('input', { id: name + '-canonical', name: 'didar_fields[' + name + ']', type: 'hidden' }));
  }
  form.appendChild(field);
  return field;
}
const rejectionCountry = dependent('rejection_embassy', 'has_rejection', 'searchable');
const rejectionDate = dependent('rejection_date', 'has_rejection', 'date');
const schengenCountry = dependent('previous_schengen_country', 'has_previous_schengen', 'searchable');
const schengenDate = dependent('previous_schengen_date', 'has_previous_schengen', 'date');
const estimatedStart = dependent('estimated_travel_date', 'has_previous_schengen', 'date');
const estimatedEnd = dependent('estimated_travel_end_date', 'has_previous_schengen', 'date');
const exitPlace = dependent('schengen_exit_place', 'has_previous_schengen', 'date');

vm.runInThisContext(fs.readFileSync(path.join(pluginRoot, 'assets/js/form-input-rules.js'), 'utf8'));
vm.runInThisContext(fs.readFileSync(path.join(pluginRoot, 'assets/js/jalali-datepicker.js'), 'utf8'));
(events.DOMContentLoaded || []).forEach((callback) => callback());

const allChildren = [rejectionCountry, rejectionDate, schengenCountry, schengenDate, estimatedStart, estimatedEnd, exitPlace];
const hidden = (field) => field.hidden && field.classList.contains('didar-conditional-hidden') && field.getAttribute('aria-hidden') === 'true';
const visible = (field) => !field.hidden && !field.classList.contains('didar-conditional-hidden') && field.getAttribute('aria-hidden') === 'false';
const selectLifecycle = [rejectionCountry, schengenCountry].every((field) => field.querySelector('[data-didar-searchable-select]') && field.contains(field.querySelector('[data-didar-searchable-select]')));
const dateLifecycle = [rejectionDate, schengenDate, estimatedStart, estimatedEnd, exitPlace].every((field) => field.querySelector('.didar-jalali-picker') && field.contains(field.querySelector('.didar-jalali-picker')));
const initial = allChildren.every(hidden);

const changes = events.change || [];
const trigger = (parentField, value) => {
  parentField.querySelectorAll('input').forEach((input) => { input.checked = input.value === value; });
  const control = parentField.querySelector('input[value="' + value + '"]');
  changes.forEach((callback) => callback({ target: control }));
};
trigger(rejectionParent, 'yes');
const rejectionYes = visible(rejectionCountry) && visible(rejectionDate) && [schengenCountry, schengenDate, estimatedStart, estimatedEnd, exitPlace].every(hidden);
trigger(rejectionParent, 'no');
const rejectionNo = hidden(rejectionCountry) && hidden(rejectionDate);
trigger(schengenParent, 'yes');
const schengenYes = [schengenCountry, schengenDate, estimatedStart, estimatedEnd, exitPlace].every(visible);
trigger(schengenParent, 'no');
const schengenNo = [schengenCountry, schengenDate, estimatedStart, estimatedEnd, exitPlace].every(hidden);
const result = { initial, selectLifecycle, dateLifecycle, rejectionYes, rejectionNo, schengenYes, schengenNo };
result.all_pass = Object.values(result).every(Boolean);
console.log(JSON.stringify(result));
process.exitCode = result.all_pass ? 0 : 1;
