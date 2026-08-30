(function () {
  'use strict';

  var digitMap = { '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9', '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9' };

  function normalizeDigits(value) {
    return value.replace(/[۰-۹٠-٩]/g, function (digit) { return digitMap[digit] || digit; });
  }

  function normalizeDigitsSafe(value) {
    return value.replace(/[\u06f0-\u06f9\u0660-\u0669]/g, function (digit) {
      var code = digit.charCodeAt(0);
      return String(code - (code >= 0x06f0 && code <= 0x06f9 ? 0x06f0 : 0x0660));
    });
  }

  function sanitizeNationalId(value, cursor) {
    value = normalizeDigitsSafe(value);
    var before = value.slice(0, cursor || 0).replace(/[^0-9]/g, '').length;
    return { value: value.replace(/[^0-9]/g, ''), cursor: before };
  }

  function sanitizePassport(value, cursor) {
    var result = '', before = 0, position = 0;
    value = value.toUpperCase();
    for (var i = 0; i < value.length && position < 9; i += 1) {
      var char = value.charAt(i), accepted = false;
      if (position === 0 && /[A-Z]/.test(char)) { result += char; position += 1; accepted = true; }
      else if (position > 0 && /[0-9]/.test(char)) { result += char; position += 1; accepted = true; }
      if (accepted && i < (cursor || 0)) { before += 1; }
    }
    return { value: result, cursor: before };
  }

  function sanitize(input) {
    var cursor = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
    var semantic = input.getAttribute('data-didar-semantic');
    var cleaned = semantic === 'national_id' ? sanitizeNationalId(input.value, cursor) : sanitizePassport(input.value, cursor);
    if (input.value !== cleaned.value) {
      input.value = cleaned.value;
      try { input.setSelectionRange(cleaned.cursor, cleaned.cursor); } catch (ignore) {}
    }
  }

  window.DidarFormInputRules = {
    normalizeDigits: normalizeDigitsSafe,
    sanitizeNationalId: sanitizeNationalId,
    sanitizePassport: sanitizePassport
  };

  document.addEventListener('input', function (event) {
    var input = event.target.closest && event.target.closest('[data-didar-semantic="national_id"],[data-didar-semantic="passport_number"]');
    if (input) { sanitize(input); }
  });
}());
