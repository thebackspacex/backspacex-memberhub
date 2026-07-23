(function () {
  'use strict';

  function normalise(value) {
    return String(value || '').toLocaleLowerCase().trim();
  }

  function prefixMatches(haystack, query) {
    if (!query) return true;
    return normalise(haystack)
      .split(/[^\p{L}\p{N}@.+_-]+/u)
      .filter(Boolean)
      .some(function (token) { return token.indexOf(query) === 0; });
  }

  function enhance(select) {
    if (!select || select.dataset.bsxmhEnhanced === '1') return;
    select.dataset.bsxmhEnhanced = '1';

    var localIndex = (window.BSXMHMemberSearch && Array.isArray(window.BSXMHMemberSearch.members))
      ? window.BSXMHMemberSearch.members : [];
    var metadata = {};
    localIndex.forEach(function (item) { metadata[String(item.id)] = item; });

    var source = Array.prototype.map.call(select.options, function (option, index) {
      var meta = metadata[String(option.value)] || {};
      return {
        value: String(option.value),
        label: option.textContent || option.innerText || '',
        disabled: !!option.disabled,
        placeholder: option.value === '' || index === 0,
        search: normalise((meta.search || '') + ' ' + (meta.label || '') + ' ' + (option.textContent || ''))
      };
    });

    var placeholder = source.find(function (item) { return item.placeholder; }) || {
      value: '', label: 'Choose member', disabled: false, placeholder: true, search: ''
    };
    var allowed = source.filter(function (item) { return !item.placeholder; });

    select.classList.add('bsxmh-member-search-native');
    select.setAttribute('aria-hidden', 'true');
    select.tabIndex = -1;

    var root = document.createElement('div');
    root.className = 'bsxmh-combobox';
    select.parentNode.insertBefore(root, select);
    root.appendChild(select);

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'bsxmh-combobox__trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');

    var triggerText = document.createElement('span');
    triggerText.className = 'bsxmh-combobox__value';
    var arrow = document.createElement('span');
    arrow.className = 'bsxmh-combobox__arrow';
    arrow.setAttribute('aria-hidden', 'true');
    arrow.textContent = '⌄';
    trigger.appendChild(triggerText);
    trigger.appendChild(arrow);

    var panel = document.createElement('div');
    panel.className = 'bsxmh-combobox__panel';
    panel.hidden = true;

    var input = document.createElement('input');
    input.type = 'search';
    input.autocomplete = 'off';
    input.className = 'bsxmh-combobox__search';
    input.placeholder = (window.BSXMHMemberSearch && window.BSXMHMemberSearch.placeholder) || 'Search by name, member ID, email or mobile';
    input.setAttribute('aria-label', input.placeholder);

    var list = document.createElement('div');
    list.className = 'bsxmh-combobox__list';
    list.setAttribute('role', 'listbox');
    list.tabIndex = -1;

    var status = document.createElement('div');
    status.className = 'bsxmh-combobox__status';
    status.setAttribute('role', 'status');

    panel.appendChild(input);
    panel.appendChild(list);
    panel.appendChild(status);
    root.appendChild(trigger);
    root.appendChild(panel);

    var activeIndex = -1;
    var rendered = [];

    function currentLabel() {
      var found = source.find(function (item) { return item.value === String(select.value); });
      return found ? found.label : placeholder.label;
    }

    function updateTrigger() {
      triggerText.textContent = currentLabel();
      root.classList.toggle('has-value', !!select.value);
    }

    function closePanel() {
      panel.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      root.classList.remove('is-open');
      activeIndex = -1;
    }

    function choose(item) {
      select.value = item.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      updateTrigger();
      closePanel();
      trigger.focus();
    }

    function markActive(index) {
      var buttons = list.querySelectorAll('.bsxmh-combobox__option');
      if (!buttons.length) { activeIndex = -1; return; }
      activeIndex = Math.max(0, Math.min(index, buttons.length - 1));
      buttons.forEach(function (button, i) {
        button.classList.toggle('is-active', i === activeIndex);
        button.setAttribute('aria-selected', i === activeIndex ? 'true' : 'false');
      });
      buttons[activeIndex].scrollIntoView({ block: 'nearest' });
    }

    function render(query) {
      var q = normalise(query);
      rendered = allowed.filter(function (item) {
        return prefixMatches(item.search || item.label, q);
      }).sort(function (a, b) {
        var aLabel = normalise(a.label);
        var bLabel = normalise(b.label);
        var aExact = q && aLabel.indexOf(q) === 0 ? 0 : 1;
        var bExact = q && bLabel.indexOf(q) === 0 ? 0 : 1;
        if (aExact !== bExact) return aExact - bExact;
        return aLabel.localeCompare(bLabel, undefined, { numeric: true, sensitivity: 'base' });
      });

      list.innerHTML = '';
      if (!q && !select.required) {
        var emptyButton = document.createElement('button');
        emptyButton.type = 'button';
        emptyButton.className = 'bsxmh-combobox__option is-placeholder';
        emptyButton.setAttribute('role', 'option');
        emptyButton.textContent = placeholder.label;
        emptyButton.addEventListener('click', function () { choose(placeholder); });
        list.appendChild(emptyButton);
      }

      rendered.forEach(function (item) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'bsxmh-combobox__option';
        button.setAttribute('role', 'option');
        button.dataset.value = item.value;
        button.textContent = item.label;
        if (item.disabled) button.disabled = true;
        if (item.value === String(select.value)) button.classList.add('is-selected');
        button.addEventListener('click', function () { choose(item); });
        list.appendChild(button);
      });

      status.textContent = rendered.length === 0
        ? ((window.BSXMHMemberSearch && window.BSXMHMemberSearch.noResults) || 'No matching members found.')
        : '';
      activeIndex = -1;
    }

    function openPanel() {
      panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      root.classList.add('is-open');
      input.value = '';
      render('');
      window.setTimeout(function () { input.focus(); }, 0);
    }

    trigger.addEventListener('click', function () {
      if (panel.hidden) openPanel(); else closePanel();
    });

    input.addEventListener('input', function () {
      render(input.value);
      if (list.querySelectorAll('.bsxmh-combobox__option').length) markActive(0);
    });

    input.addEventListener('keydown', function (event) {
      var count = list.querySelectorAll('.bsxmh-combobox__option').length;
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        markActive(activeIndex + 1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        markActive(activeIndex <= 0 ? count - 1 : activeIndex - 1);
      } else if (event.key === 'Enter' && activeIndex >= 0) {
        event.preventDefault();
        var active = list.querySelectorAll('.bsxmh-combobox__option')[activeIndex];
        if (active) active.click();
      } else if (event.key === 'Escape') {
        event.preventDefault();
        closePanel();
        trigger.focus();
      }
    });

    document.addEventListener('click', function (event) {
      if (!root.contains(event.target)) closePanel();
    });

    select.addEventListener('change', updateTrigger);
    updateTrigger();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('select.bsxmh-member-search').forEach(enhance);
  });
})();
