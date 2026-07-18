window.__AutoLabelScriptVersion = '0.8.0-ui-polish';
console.info('AutoLabel script loaded:', window.__AutoLabelScriptVersion);

document.addEventListener('DOMContentLoaded', () => {
  let activateDashboardTab = () => {};
  const tabsRoot = document.querySelector('[data-autolabel-tabs]');
  if (tabsRoot) {
    const page = document.querySelector('[data-autolabel-default-tab]');
    const tabs = Array.from(tabsRoot.querySelectorAll('[data-autolabel-tab]'));
    const knownTabs = new Set(tabs.map((tab) => tab.dataset.autolabelTab).filter(Boolean));
    const defaultTab = knownTabs.has(page?.dataset.autolabelDefaultTab ?? '')
      ? page.dataset.autolabelDefaultTab
      : (tabs[0]?.dataset.autolabelTab ?? '');

    const normalizeTabId = (hash) => {
      if (!hash) {
        return '';
      }

      const normalizedHash = hash.replace(/^#/, '');
      let raw = normalizedHash;
      try {
        raw = decodeURIComponent(normalizedHash);
      } catch (error) {
        raw = normalizedHash;
      }
      raw = raw.replace(/^autolabel-/, '');
      return knownTabs.has(raw) ? raw : '';
    };

    const activateTab = (tabId, updateHash = false) => {
      const nextTab = knownTabs.has(tabId) ? tabId : defaultTab;
      if (nextTab === '') {
        return;
      }

      for (const tab of tabs) {
        const isActive = tab.dataset.autolabelTab === nextTab;
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        tab.setAttribute('tabindex', isActive ? '0' : '-1');
      }

      for (const panel of document.querySelectorAll('[data-autolabel-panel]')) {
        panel.hidden = panel.dataset.autolabelPanel !== nextTab;
      }

      if (updateHash && window.history?.pushState) {
        window.history.pushState(null, '', `#autolabel-${nextTab}`);
      }
    };
    activateDashboardTab = activateTab;

    const focusTabByOffset = (currentTab, offset) => {
      const currentIndex = tabs.indexOf(currentTab);
      if (currentIndex === -1 || tabs.length === 0) {
        return;
      }

      const nextIndex = (currentIndex + offset + tabs.length) % tabs.length;
      const nextTab = tabs[nextIndex];
      activateTab(nextTab.dataset.autolabelTab ?? '', true);
      nextTab.focus();
    };

    tabsRoot.dataset.autolabelEnhanced = 'true';
    for (const tab of tabs) {
      tab.addEventListener('click', (event) => {
        event.preventDefault();
        activateTab(tab.dataset.autolabelTab ?? '', true);
      });
      tab.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowRight') {
          event.preventDefault();
          focusTabByOffset(tab, 1);
        } else if (event.key === 'ArrowLeft') {
          event.preventDefault();
          focusTabByOffset(tab, -1);
        } else if (event.key === 'Home') {
          event.preventDefault();
          activateTab(tabs[0]?.dataset.autolabelTab ?? '', true);
          tabs[0]?.focus();
        } else if (event.key === 'End') {
          event.preventDefault();
          activateTab(tabs[tabs.length - 1]?.dataset.autolabelTab ?? '', true);
          tabs[tabs.length - 1]?.focus();
        }
      });
    }

    window.addEventListener('hashchange', () => {
      activateTab(normalizeTabId(window.location.hash) || defaultTab);
    });
    activateTab(normalizeTabId(window.location.hash) || defaultTab);
  }

  const findRuleForm = () => document.querySelector('[data-autolabel-rule-form]') || document.querySelector('form[action*="saveRule"]');

  const findRuleNameInput = (form) => {
    if (!form) {
      return null;
    }

    return form.querySelector('[data-autolabel-rule-name]') || form.querySelector('input[name="name"]');
  };

  const findRuleTargetTagsSelect = (form) => {
    if (!form) {
      return null;
    }

    return form.querySelector('[data-autolabel-target-tags]')
      || Array.from(form.querySelectorAll('select')).find((select) => select.name === 'target_tags[]');
  };

  const findRuleSelectedTags = (form) => {
    const container = findRuleTargetTagsSelect(form);
    if (!container) {
      return [];
    }

    if (container instanceof HTMLSelectElement) {
      return Array.from(container.options)
        .filter((option) => option.selected)
        .map((option) => option.value.trim())
        .filter((value) => value !== '');
    }

    return Array.from(container.querySelectorAll('input[name="target_tags[]"]:checked'))
      .map((input) => input.value.trim())
      .filter((value) => value !== '');
  };

  let lastGeneratedRuleName = '';
  let lastSelectedRuleTags = '';

  const syncRuleNameFromSelectedTags = () => {
    const form = findRuleForm();
    const nameInput = findRuleNameInput(form);
    if (!nameInput || !findRuleTargetTagsSelect(form)) {
      return;
    }

    const selectedTags = findRuleSelectedTags(form);
    const selectedKey = selectedTags.join('\n');
    const generatedName = selectedTags.join(', ');
    const currentName = nameInput.value.trim();

    if (currentName !== '' && currentName !== lastGeneratedRuleName) {
      lastSelectedRuleTags = selectedKey;
      return;
    }

    if (selectedKey === lastSelectedRuleTags && currentName === generatedName) {
      return;
    }

    nameInput.value = generatedName;
    lastGeneratedRuleName = generatedName;
    lastSelectedRuleTags = selectedKey;
  };

  const profileForm = document.querySelector('[data-autolabel-profile-form]');
  if (profileForm) {
    const providerSelect = profileForm.querySelector('[data-autolabel-provider]');
    const baseUrlInput = profileForm.querySelector('input[name="base_url"]');
    const profileModeSelect = profileForm.querySelector('[data-autolabel-profile-mode]');
    const llmPanel = profileForm.querySelector('[data-autolabel-profile-panel="llm"]');
    const embeddingPanel = profileForm.querySelector('[data-autolabel-profile-panel="embedding"]');
    const providerDefaults = {
      llm: {
        openai: 'https://api.openai.com/v1/responses',
        anthropic: 'https://api.anthropic.com/v1/messages',
        gemini: 'https://generativelanguage.googleapis.com',
        ollama: 'http://127.0.0.1:11434/api/chat',
      },
      embedding: {
        openai: 'https://api.openai.com/v1/embeddings',
        anthropic: '',
        gemini: 'https://generativelanguage.googleapis.com',
        ollama: 'http://127.0.0.1:11434/api/embed',
      },
    };
    let previousProvider = providerSelect?.value ?? '';
    let previousMode = profileModeSelect?.value === 'embedding' ? 'embedding' : 'llm';

    const applyProfileMode = () => {
      if (!profileModeSelect || !llmPanel || !embeddingPanel) {
        return;
      }

      llmPanel.hidden = profileModeSelect.value !== 'llm';
      embeddingPanel.hidden = profileModeSelect.value !== 'embedding';
    };

    const applyProviderCapabilities = () => {
      if (!providerSelect || !profileModeSelect) {
        return;
      }

      const embeddingOption = Array.from(profileModeSelect.options).find((option) => option.value === 'embedding');
      const isAnthropic = providerSelect.value === 'anthropic';
      if (embeddingOption) {
        embeddingOption.disabled = isAnthropic;
      }
      if (isAnthropic && profileModeSelect.value === 'embedding') {
        profileModeSelect.value = 'llm';
      }
      applyProfileMode();
    };

    const syncBaseUrlForProviderChange = () => {
      if (!providerSelect || !baseUrlInput) {
        return;
      }

      const nextProvider = providerSelect.value;
      const nextMode = profileModeSelect?.value === 'embedding' ? 'embedding' : 'llm';
      if (nextProvider === previousProvider && nextMode === previousMode && baseUrlInput.value.trim() !== '') {
        return;
      }

      const currentValue = baseUrlInput.value.trim();
      const knownDefaults = Object.values(providerDefaults)
        .flatMap((defaults) => Object.values(defaults))
        .filter((value) => value !== '');
      const nextDefault = providerDefaults[nextMode]?.[nextProvider] ?? '';
      const canReplace = currentValue === '' || knownDefaults.includes(currentValue);

      if (nextProvider === 'ollama' && nextDefault !== '') {
        baseUrlInput.value = nextDefault;
      } else if (canReplace && nextDefault !== '') {
        baseUrlInput.value = nextDefault;
      }

      previousProvider = nextProvider;
      previousMode = nextMode;
    };

    const syncProvider = () => {
      if (!providerSelect) {
        return;
      }

      applyProviderCapabilities();
      syncBaseUrlForProviderChange();
    };

    providerSelect?.addEventListener('change', syncProvider);
    profileModeSelect?.addEventListener('change', () => {
      applyProfileMode();
      syncBaseUrlForProviderChange();
    });
    applyProviderCapabilities();
    applyProfileMode();
    if (providerSelect) {
      previousProvider = providerSelect.value;
    }
    previousMode = profileModeSelect?.value === 'embedding' ? 'embedding' : 'llm';
  }

  const ruleForm = findRuleForm();
  if (ruleForm) {
    const profileSelect = ruleForm.querySelector('[data-autolabel-profile-select]');
    const modeSelect = ruleForm.querySelector('[data-autolabel-mode-select]');
    const nameInput = findRuleNameInput(ruleForm);
    const targetTagsSelect = findRuleTargetTagsSelect(ruleForm);
    const llmPanel = ruleForm.querySelector('[data-autolabel-mode-panel="llm"]');
    const embeddingPanel = ruleForm.querySelector('[data-autolabel-mode-panel="embedding"]');
    lastGeneratedRuleName = nameInput?.value.trim() ?? '';

    const syncRuleForm = () => {
      if (!profileSelect || !modeSelect || !llmPanel || !embeddingPanel) {
        return;
      }

      const selectedOption = profileSelect.selectedOptions[0];
      const supportsLlm = selectedOption?.dataset.supportsLlm === '1';
      const supportsEmbedding = selectedOption?.dataset.supportsEmbedding === '1';

      for (const option of modeSelect.options) {
        const mode = option.value;
        const supported = (mode === 'llm' && supportsLlm) || (mode === 'embedding' && supportsEmbedding);
        option.disabled = !supported;
      }

      if (modeSelect.selectedOptions[0]?.disabled) {
        if (supportsLlm) {
          modeSelect.value = 'llm';
        } else if (supportsEmbedding) {
          modeSelect.value = 'embedding';
        }
      }

      llmPanel.hidden = modeSelect.value !== 'llm';
      embeddingPanel.hidden = modeSelect.value !== 'embedding';
    };

    profileSelect?.addEventListener('change', syncRuleForm);
    modeSelect?.addEventListener('change', syncRuleForm);
    ['change', 'input', 'click', 'mouseup', 'keyup'].forEach((eventName) => {
      targetTagsSelect?.addEventListener(eventName, () => window.setTimeout(syncRuleNameFromSelectedTags, 0));
    });
    nameInput?.addEventListener('input', () => {
      if (nameInput.value.trim() === '') {
        lastGeneratedRuleName = '';
        lastSelectedRuleTags = '';
        window.setTimeout(syncRuleNameFromSelectedTags, 0);
      }
    });
    syncRuleForm();
    if (!nameInput?.value.trim()) {
      syncRuleNameFromSelectedTags();
    }
  }

  ['change', 'input', 'click', 'mouseup', 'keyup'].forEach((eventName) => {
    document.addEventListener(eventName, (event) => {
      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      if (target.matches('select[name="target_tags[]"], input[name="target_tags[]"], [data-autolabel-target-tags], input[name="name"], [data-autolabel-rule-name]')) {
        window.setTimeout(syncRuleNameFromSelectedTags, 0);
      }
    });
  });
  for (let index = 1; index <= 10; index++) {
    window.setTimeout(syncRuleNameFromSelectedTags, index * 300);
  }

  const ajaxActionNames = [
    'saveProfile',
    'deleteProfile',
    'toggleProfile',
    'testProfile',
    'saveRule',
    'deleteRule',
    'toggleRule',
    'dryRun',
    'backfill',
    'clearQueue',
    'saveNotifications',
    'testNotifications',
    'clearNotifications',
    'saveDiagnostics',
    'clearDiagnostics',
  ];

  const actionNameFromUrl = (url) => ajaxActionNames.find((actionName) => String(url).includes(actionName)) ?? '';

  const showFeedback = (message, ok = true) => {
    const feedback = document.querySelector('[data-autolabel-feedback]');
    if (!(feedback instanceof HTMLElement) || typeof message !== 'string' || message.trim() === '') {
      return;
    }

    feedback.textContent = message.trim();
    feedback.hidden = false;
    feedback.classList.toggle('autolabel-feedback--error', !ok);
  };

  const parseActionJsonResponse = async (response) => {
    const raw = await response.text();
    let data;
    try {
      data = JSON.parse(raw);
    } catch (error) {
      const snippet = raw.replace(/\s+/g, ' ').trim().slice(0, 180);
      throw new Error(snippet === '' ? 'Request failed.' : snippet);
    }

    if (!response.ok || data.ok === false) {
      throw new Error(String(data.message || data.error || 'Request failed.'));
    }

    return data;
  };

  const fetchAutolabelDocument = async (url) => {
    const response = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'text/html',
      },
    });
    const html = await response.text();
    if (!response.ok) {
      throw new Error(html.replace(/\s+/g, ' ').trim().slice(0, 180) || 'Request failed.');
    }

    return new DOMParser().parseFromString(html, 'text/html');
  };

  const enhanceProfileControls = (scope) => {
    const providerDefaults = {
      llm: {
        openai: 'https://api.openai.com/v1/responses',
        anthropic: 'https://api.anthropic.com/v1/messages',
        gemini: 'https://generativelanguage.googleapis.com',
        ollama: 'http://127.0.0.1:11434/api/chat',
      },
      embedding: {
        openai: 'https://api.openai.com/v1/embeddings',
        anthropic: '',
        gemini: 'https://generativelanguage.googleapis.com',
        ollama: 'http://127.0.0.1:11434/api/embed',
      },
    };

    for (const form of scope.querySelectorAll('[data-autolabel-profile-form]')) {
      if (!(form instanceof HTMLFormElement) || form.dataset.autolabelControlsEnhanced === 'true') {
        continue;
      }

      form.dataset.autolabelControlsEnhanced = 'true';
      const providerSelect = form.querySelector('[data-autolabel-provider]');
      const baseUrlInput = form.querySelector('input[name="base_url"]');
      const profileModeSelect = form.querySelector('[data-autolabel-profile-mode]');
      const llmPanel = form.querySelector('[data-autolabel-profile-panel="llm"]');
      const embeddingPanel = form.querySelector('[data-autolabel-profile-panel="embedding"]');
      let previousProvider = providerSelect?.value ?? '';
      let previousMode = profileModeSelect?.value === 'embedding' ? 'embedding' : 'llm';

      const applyProfileMode = () => {
        if (!profileModeSelect || !llmPanel || !embeddingPanel) {
          return;
        }

        llmPanel.hidden = profileModeSelect.value !== 'llm';
        embeddingPanel.hidden = profileModeSelect.value !== 'embedding';
      };

      const applyProviderCapabilities = () => {
        if (!providerSelect || !profileModeSelect) {
          return;
        }

        const embeddingOption = Array.from(profileModeSelect.options).find((option) => option.value === 'embedding');
        const isAnthropic = providerSelect.value === 'anthropic';
        if (embeddingOption) {
          embeddingOption.disabled = isAnthropic;
        }
        if (isAnthropic && profileModeSelect.value === 'embedding') {
          profileModeSelect.value = 'llm';
        }
        applyProfileMode();
      };

      const syncBaseUrlForProviderChange = () => {
        if (!providerSelect || !(baseUrlInput instanceof HTMLInputElement)) {
          return;
        }

        const nextProvider = providerSelect.value;
        const nextMode = profileModeSelect?.value === 'embedding' ? 'embedding' : 'llm';
        if (nextProvider === previousProvider && nextMode === previousMode && baseUrlInput.value.trim() !== '') {
          return;
        }

        const currentValue = baseUrlInput.value.trim();
        const knownDefaults = Object.values(providerDefaults)
          .flatMap((defaults) => Object.values(defaults))
          .filter((value) => value !== '');
        const nextDefault = providerDefaults[nextMode]?.[nextProvider] ?? '';
        const canReplace = currentValue === '' || knownDefaults.includes(currentValue);

        if (nextProvider === 'ollama' && nextDefault !== '') {
          baseUrlInput.value = nextDefault;
        } else if (canReplace && nextDefault !== '') {
          baseUrlInput.value = nextDefault;
        }

        previousProvider = nextProvider;
        previousMode = nextMode;
      };

      providerSelect?.addEventListener('change', () => {
        applyProviderCapabilities();
        syncBaseUrlForProviderChange();
      });
      profileModeSelect?.addEventListener('change', () => {
        applyProfileMode();
        syncBaseUrlForProviderChange();
      });
      applyProviderCapabilities();
      applyProfileMode();
      previousProvider = providerSelect?.value ?? previousProvider;
      previousMode = profileModeSelect?.value === 'embedding' ? 'embedding' : 'llm';
    }
  };

  const enhanceRuleControls = (scope) => {
    for (const form of scope.querySelectorAll('[data-autolabel-rule-form]')) {
      if (!(form instanceof HTMLFormElement) || form.dataset.autolabelControlsEnhanced === 'true') {
        continue;
      }

      form.dataset.autolabelControlsEnhanced = 'true';
      const profileSelect = form.querySelector('[data-autolabel-profile-select]');
      const modeSelect = form.querySelector('[data-autolabel-mode-select]');
      const nameInput = findRuleNameInput(form);
      const targetTagsSelect = findRuleTargetTagsSelect(form);
      const llmPanel = form.querySelector('[data-autolabel-mode-panel="llm"]');
      const embeddingPanel = form.querySelector('[data-autolabel-mode-panel="embedding"]');
      lastGeneratedRuleName = nameInput?.value.trim() ?? '';

      const syncRuleForm = () => {
        if (!profileSelect || !modeSelect || !llmPanel || !embeddingPanel) {
          return;
        }

        const selectedOption = profileSelect.selectedOptions[0];
        const supportsLlm = selectedOption?.dataset.supportsLlm === '1';
        const supportsEmbedding = selectedOption?.dataset.supportsEmbedding === '1';
        for (const option of modeSelect.options) {
          const mode = option.value;
          option.disabled = !((mode === 'llm' && supportsLlm) || (mode === 'embedding' && supportsEmbedding));
        }
        if (modeSelect.selectedOptions[0]?.disabled) {
          if (supportsLlm) {
            modeSelect.value = 'llm';
          } else if (supportsEmbedding) {
            modeSelect.value = 'embedding';
          }
        }

        llmPanel.hidden = modeSelect.value !== 'llm';
        embeddingPanel.hidden = modeSelect.value !== 'embedding';
      };

      profileSelect?.addEventListener('change', syncRuleForm);
      modeSelect?.addEventListener('change', syncRuleForm);
      form.addEventListener('submit', (event) => {
        if (findRuleSelectedTags(form).length > 0) {
          return;
        }
        event.preventDefault();
        showFeedback(form.dataset.noTagsMessage || 'Please choose at least one target tag.', false);
      });
      nameInput?.addEventListener('input', () => {
        if (nameInput.value.trim() === '') {
          lastGeneratedRuleName = '';
          lastSelectedRuleTags = '';
          window.setTimeout(syncRuleNameFromSelectedTags, 0);
        }
      });
      ['change', 'input', 'click', 'mouseup', 'keyup'].forEach((eventName) => {
        targetTagsSelect?.addEventListener(eventName, () => window.setTimeout(syncRuleNameFromSelectedTags, 0));
      });
      syncRuleForm();
      if (!nameInput?.value.trim()) {
        window.setTimeout(syncRuleNameFromSelectedTags, 0);
      }
    }
  };

  const enhanceNotificationTagControls = (scope) => {
    for (const root of scope.querySelectorAll('[data-autolabel-notification-tags]')) {
      if (!(root instanceof HTMLElement) || root.dataset.autolabelControlsEnhanced === 'true') {
        continue;
      }

      root.dataset.autolabelControlsEnhanced = 'true';
      const modeName = root.dataset.autolabelNotificationModeName || 'notification_tag_mode';
      const tagsName = root.dataset.autolabelNotificationTagsName || 'notification_tags[]';
      const radios = Array.from(root.querySelectorAll('input[type="radio"]')).filter((input) => input.name === modeName);
      const selectedMode = radios.find((input) => input.value === 'selected') || null;
      const checkboxes = Array.from(root.querySelectorAll('input[type="checkbox"]')).filter((input) => input.name === tagsName);
      const list = root.querySelector('[data-autolabel-notification-tag-list]');
      const count = root.querySelector('[data-autolabel-notification-tag-count]');
      const selectAll = root.querySelector('[data-autolabel-notification-tag-select-all]');
      const clear = root.querySelector('[data-autolabel-notification-tag-clear]');

      const updateCount = () => {
        if (!(count instanceof HTMLElement)) {
          return;
        }

        const checked = checkboxes.filter((checkbox) => checkbox.checked).length;
        const template = count.dataset.countTemplate || '%d selected';
        count.textContent = template.replace('%d', String(checked));
      };

      const applyMode = () => {
        const isSelectedMode = selectedMode instanceof HTMLInputElement && selectedMode.checked;
        if (list instanceof HTMLElement) {
          list.hidden = !isSelectedMode;
        }
        for (const checkbox of checkboxes) {
          checkbox.disabled = !isSelectedMode;
        }
        for (const button of [selectAll, clear]) {
          if (button instanceof HTMLButtonElement) {
            button.disabled = !isSelectedMode || checkboxes.length === 0;
          }
        }
        updateCount();
      };

      for (const radio of radios) {
        radio.addEventListener('change', applyMode);
      }
      for (const checkbox of checkboxes) {
        checkbox.addEventListener('change', updateCount);
      }
      if (selectAll instanceof HTMLButtonElement) {
        selectAll.addEventListener('click', () => {
          for (const checkbox of checkboxes) {
            checkbox.checked = true;
          }
          updateCount();
        });
      }
      if (clear instanceof HTMLButtonElement) {
        clear.addEventListener('click', () => {
          for (const checkbox of checkboxes) {
            checkbox.checked = false;
          }
          updateCount();
        });
      }

      applyMode();
    }
  };

  const enhanceReplacedPanel = (panel) => {
    if (!(panel instanceof Element)) {
      return;
    }

    enhanceProfileControls(panel);
    enhanceRuleControls(panel);
    enhanceNotificationTagControls(panel);
  };

  enhanceProfileControls(document);
  enhanceRuleControls(document);
  enhanceNotificationTagControls(document);

  const updateQueueSnapshotValues = (snapshot) => {
    if (!snapshot || typeof snapshot !== 'object') {
      return;
    }

    const pendingEntries = document.querySelector('[data-autolabel-queue-pending-entries]');
    const pendingBackfills = document.querySelector('[data-autolabel-queue-pending-backfills]');
    const pendingBackfillEntries = document.querySelector('[data-autolabel-queue-pending-backfill-entries]');
    const lastRun = document.querySelector('[data-autolabel-queue-last-run]');
    if (pendingEntries) {
      pendingEntries.textContent = String(snapshot.pending_entries ?? '0');
    }
    if (pendingBackfills) {
      pendingBackfills.textContent = String(snapshot.pending_backfills ?? '0');
    }
    if (pendingBackfillEntries) {
      pendingBackfillEntries.textContent = String(snapshot.pending_backfill_entries ?? '0');
    }
    if (lastRun) {
      lastRun.textContent = String(snapshot.last_run?.at ?? '');
    }
  };

  const replacePanelFromDocument = (doc, panelName) => {
    const nextPanel = doc.querySelector(`[data-autolabel-panel="${panelName}"]`);
    const currentPanel = document.querySelector(`[data-autolabel-panel="${panelName}"]`);
    if (!nextPanel || !currentPanel) {
      return;
    }

    const replacement = nextPanel.cloneNode(true);
    currentPanel.replaceWith(replacement);
    enhanceReplacedPanel(replacement);
  };

  const refreshToolsSelects = (doc) => {
    for (const selector of ['#autolabel-tools select[name="rule_id"]', '#autolabel-tools select[name="backfill_rule_id"]']) {
      const currentSelect = document.querySelector(selector);
      const nextSelect = doc.querySelector(selector);
      if (!(currentSelect instanceof HTMLSelectElement) || !(nextSelect instanceof HTMLSelectElement)) {
        continue;
      }

      const previousValue = currentSelect.value;
      currentSelect.innerHTML = nextSelect.innerHTML;
      if (Array.from(currentSelect.options).some((option) => option.value === previousValue)) {
        currentSelect.value = previousValue;
      }
    }
  };

  const refreshFragments = async (url, refreshPanels, data = {}) => {
    const panels = Array.isArray(refreshPanels) ? Array.from(new Set(refreshPanels)) : [];
    if (data.snapshot) {
      updateQueueSnapshotValues(data.snapshot);
    }
    if (panels.length === 0) {
      return;
    }

    const needsDocument = panels.some((panel) => ['profiles', 'rules', 'notifications', 'diagnostics', 'tools_selects'].includes(panel));
    const doc = needsDocument ? await fetchAutolabelDocument(url || window.location.href) : null;
    for (const panel of panels) {
      if (panel === 'tools_queue') {
        if (data.snapshot) {
          updateQueueSnapshotValues(data.snapshot);
        }
        continue;
      }
      if (panel === 'tools_selects') {
        if (doc) {
          refreshToolsSelects(doc);
        }
        continue;
      }
      if (doc) {
        replacePanelFromDocument(doc, panel);
      }
    }
  };

  const replaceHistoryWithTab = (url, tab) => {
    if (!window.history?.replaceState || typeof tab !== 'string' || tab === '') {
      return;
    }

    const baseUrl = String(url || window.location.href).replace(/#.*/, '');
    window.history.replaceState(null, '', `${baseUrl}#autolabel-${tab}`);
  };

  document.addEventListener('submit', async (event) => {
    if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) {
      return;
    }

    const form = event.target;
    const actionName = actionNameFromUrl(form.action);
    if (actionName === '') {
      return;
    }

    event.preventDefault();
    const submitter = event.submitter instanceof HTMLButtonElement ? event.submitter : form.querySelector('button[type="submit"]');
    if (submitter instanceof HTMLButtonElement) {
      submitter.disabled = true;
    }
    form.setAttribute('aria-busy', 'true');

    try {
      const response = await fetch(form.action, {
        method: (form.method || 'POST').toUpperCase(),
        credentials: 'same-origin',
        cache: 'no-store',
        body: new FormData(form),
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
        },
      });
      const data = await parseActionJsonResponse(response);
      showFeedback(data.message, true);
      await refreshFragments(data.refresh_url || window.location.href, data.refresh_panels || [], data);
      replaceHistoryWithTab(data.refresh_url || window.location.href, data.tab || '');
      activateDashboardTab(data.tab || '');
    } catch (error) {
      showFeedback(error instanceof Error ? error.message : 'Request failed.', false);
    } finally {
      form.removeAttribute('aria-busy');
      if (submitter instanceof HTMLButtonElement) {
        submitter.disabled = false;
      }
    }
  });

  document.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }

    const link = target.closest('a[data-autolabel-panel-link]');
    if (!(link instanceof HTMLAnchorElement) || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }

    event.preventDefault();
    const panel = link.dataset.autolabelPanelLink || '';
    try {
      await refreshFragments(link.href, [panel]);
      activateDashboardTab(panel);
      if (window.history?.pushState) {
        window.history.pushState(null, '', link.href);
      }
    } catch (error) {
      showFeedback(error instanceof Error ? error.message : 'Request failed.', false);
    }
  });

  const queueForm = document.querySelector('.autolabel-card form[action*="processQueue"]');
  if (queueForm instanceof HTMLFormElement) {
    const button = queueForm.querySelector('[data-autolabel-queue-button]');
    const status = document.querySelector('[data-autolabel-queue-status]');
    const pendingEntries = document.querySelector('[data-autolabel-queue-pending-entries]');
    const pendingBackfills = document.querySelector('[data-autolabel-queue-pending-backfills]');
    const pendingBackfillEntries = document.querySelector('[data-autolabel-queue-pending-backfill-entries]');
    const lastRun = document.querySelector('[data-autolabel-queue-last-run]');
    const progress = document.querySelector('[data-autolabel-queue-progress]');
    const progressBar = document.querySelector('[data-autolabel-queue-progress-bar]');
    const progressText = document.querySelector('[data-autolabel-queue-progress-text]');
    const startUrlInput = queueForm.querySelector('input[name="queue_manual_start_url"]');
    const statusUrlInput = queueForm.querySelector('input[name="queue_manual_status_url"]');
    const runIdInput = queueForm.querySelector('input[name="queue_manual_run_id"]');
    const runStatusInput = queueForm.querySelector('input[name="queue_manual_status"]');
    const runInitialTotalInput = queueForm.querySelector('input[name="queue_manual_initial_total"]');
    let running = false;
    let initialWorkTotal = 0;
    let currentRunId = '';
    let lastKnownTotal = 0;
    let lastProgressAt = 0;
    let transientFailures = 0;

    const sleep = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

    const setStatus = (text) => {
      if (status) {
        status.textContent = text;
      }
    };

    const queueErrorText = (data) => {
      const fallback = button?.dataset.failedLabel ?? 'Queue processing failed.';
      if (!data || typeof data.error !== 'string' || data.error.trim() === '') {
        return fallback;
      }

      return `${fallback} ${data.error.trim()}`;
    };

    const rawErrorText = (raw) => {
      const fallback = button?.dataset.failedLabel ?? 'Queue processing failed.';
      if (typeof raw !== 'string') {
        return fallback;
      }

      const snippet = raw.replace(/\s+/g, ' ').trim().slice(0, 180);
      return snippet === '' ? fallback : `${fallback} ${snippet}`;
    };

    const parseJsonResponse = async (response) => {
      const raw = await response.text();
      let data;
      try {
        data = JSON.parse(raw);
      } catch (error) {
        console.error('AutoLabel queue response was not valid JSON', raw);
        throw new Error(rawErrorText(raw));
      }

      if (!response.ok) {
        console.error('AutoLabel queue request failed', response.status, data);
        throw new Error(queueErrorText(data));
      }

      if (data.ok === false) {
        throw new Error(queueErrorText(data));
      }

      return data;
    };

    const updateSnapshot = (snapshot) => {
      if (!snapshot || typeof snapshot !== 'object') {
        return;
      }

      if (pendingEntries) {
        pendingEntries.textContent = String(snapshot.pending_entries ?? '0');
      }
      if (pendingBackfills) {
        pendingBackfills.textContent = String(snapshot.pending_backfills ?? '0');
      }
      if (pendingBackfillEntries) {
        pendingBackfillEntries.textContent = String(snapshot.pending_backfill_entries ?? '0');
      }
      if (lastRun) {
        lastRun.textContent = String(snapshot.last_run?.at ?? '');
      }

      updateProgress(snapshot);
    };

    const totalWorkFromSnapshot = (snapshot) => {
      const pendingEntryCount = Number.parseInt(String(snapshot?.pending_entries ?? '0'), 10) || 0;
      const pendingBackfillEntryCount = Number.parseInt(String(snapshot?.pending_backfill_entries ?? '0'), 10) || 0;
      return Math.max(0, pendingEntryCount + pendingBackfillEntryCount);
    };

    const currentSnapshotFromDom = () => ({
      pending_entries: pendingEntries?.textContent ?? '0',
      pending_backfill_entries: pendingBackfillEntries?.textContent ?? '0',
    });

    const setProgressVisibility = (visible) => {
      if (progress instanceof HTMLElement) {
        progress.hidden = !visible;
      }
      if (progressText instanceof HTMLElement) {
        progressText.hidden = !visible;
      }
    };

    const updateProgress = (snapshot) => {
      if (!(progressBar instanceof HTMLElement) || !(progressText instanceof HTMLElement)) {
        return;
      }

      if (!running || initialWorkTotal <= 0) {
        progressBar.style.width = '0%';
        progressText.textContent = '';
        setProgressVisibility(false);
        return;
      }

      const remaining = totalWorkFromSnapshot(snapshot);
      const completed = Math.max(0, initialWorkTotal - Math.min(initialWorkTotal, remaining));
      const ratio = initialWorkTotal <= 0 ? 0 : completed / initialWorkTotal;
      const percent = Math.max(0, Math.min(100, Math.round(ratio * 100)));
      progressBar.style.width = `${percent}%`;
      progressText.textContent = `${percent}% · ${completed} / ${initialWorkTotal}`;
      setProgressVisibility(true);
    };

    const syncProgressSignals = (snapshot) => {
      const total = totalWorkFromSnapshot(snapshot);
      if (lastKnownTotal === 0 || total < lastKnownTotal) {
        lastProgressAt = Date.now();
      }
      lastKnownTotal = total;
    };

    const buildStatusUrl = () => {
      const formAction = typeof queueForm.action === 'string' ? queueForm.action.trim() : '';
      const base = formAction !== ''
        ? `${formAction}${formAction.includes('?') ? '&' : '?'}manual_queue_mode=status`
        : (statusUrlInput instanceof HTMLInputElement ? statusUrlInput.value.trim() : '');
      if (base === '') {
        return '';
      }

      if (currentRunId === '') {
        return base;
      }

      const separator = base.includes('?') ? '&' : '?';
      return `${base}${separator}run_id=${encodeURIComponent(currentRunId)}`;
    };

    const shouldFailAfterTransientErrors = () => transientFailures >= 6 && (Date.now() - lastProgressAt > 30000);

    const syncManualRunInputs = (data) => {
      if (runIdInput instanceof HTMLInputElement && typeof data.run_id === 'string') {
        runIdInput.value = data.run_id;
      }
      if (runStatusInput instanceof HTMLInputElement && typeof data.status === 'string') {
        runStatusInput.value = data.status;
      }
      if (runInitialTotalInput instanceof HTMLInputElement && Number.isFinite(Number(data.initial_total))) {
        runInitialTotalInput.value = String(Number(data.initial_total));
      }
    };

    const resolveStartUrl = () => {
      const formAction = typeof queueForm.action === 'string' ? queueForm.action.trim() : '';
      return formAction !== ''
        ? `${formAction}${formAction.includes('?') ? '&' : '?'}manual_queue_mode=start`
        : (startUrlInput instanceof HTMLInputElement ? startUrlInput.value.trim() : '');
    };

    const startManualRunRequest = async () => {
      const startUrl = resolveStartUrl();
      if (startUrl === '') {
        throw new Error(button?.dataset.failedLabel ?? 'Queue processing failed.');
      }

      const response = await fetch(startUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        body: new FormData(queueForm),
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
        },
      });
      const data = await parseJsonResponse(response);
      syncManualRunInputs(data);
      currentRunId = typeof data.run_id === 'string' ? data.run_id : '';
      updateSnapshot(data.snapshot ?? {});
      if (Number.isFinite(Number(data.initial_total))) {
        initialWorkTotal = Number(data.initial_total);
      } else if (initialWorkTotal <= 0) {
        initialWorkTotal = totalWorkFromSnapshot(data.snapshot ?? currentSnapshotFromDom());
      }
      syncProgressSignals(data.snapshot ?? currentSnapshotFromDom());
      updateProgress(data.snapshot ?? currentSnapshotFromDom());
      return data;
    };

    const pollManualRun = async () => {
      while (true) {
        await sleep(5000);
        const statusUrl = buildStatusUrl();
        if (statusUrl === '') {
          throw new Error(button?.dataset.failedLabel ?? 'Queue processing failed.');
        }

        try {
          const response = await fetch(statusUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'Accept': 'application/json',
            },
          });
          const data = await parseJsonResponse(response);
          transientFailures = 0;
          syncManualRunInputs(data);
          if (typeof data.run_id === 'string' && data.run_id !== '') {
            currentRunId = data.run_id;
          }
          updateSnapshot(data.snapshot ?? {});
          syncProgressSignals(data.snapshot ?? currentSnapshotFromDom());

          if (data.status === 'completed') {
            setStatus(button?.dataset.completedLabel ?? 'Queue completed.');
            return;
          }

          if (data.status === 'error') {
            throw new Error(queueErrorText(data));
          }

          if (data.status === 'running') {
            setStatus(button?.dataset.processingBackgroundLabel ?? button?.dataset.processingLabel ?? 'Processing queue...');
            continue;
          }

          if (totalWorkFromSnapshot(data.snapshot ?? {}) === 0) {
            setStatus(button?.dataset.completedLabel ?? 'Queue completed.');
            return;
          }

          if (data.status === 'idle') {
            setStatus(button?.dataset.processingContinuingLabel ?? button?.dataset.processingBackgroundLabel ?? 'The request ended, but background processing is still continuing...');
            const restarted = await startManualRunRequest();
            if (restarted.status === 'completed') {
              setStatus(button?.dataset.completedLabel ?? 'Queue completed.');
              return;
            }
            if (restarted.status === 'error') {
              throw new Error(queueErrorText(restarted));
            }
            setStatus(button?.dataset.processingBackgroundLabel ?? button?.dataset.processingLabel ?? 'Processing queue...');
            continue;
          }

          setStatus(button?.dataset.stalledLabel ?? 'Queue paused because there was no progress.');
          return;
        } catch (error) {
          transientFailures += 1;
          setStatus(button?.dataset.processingContinuingLabel ?? button?.dataset.processingBackgroundLabel ?? 'The request ended, but background processing is still continuing...');
          if (shouldFailAfterTransientErrors()) {
            throw error instanceof Error ? error : new Error(button?.dataset.failedLabel ?? 'Queue processing failed.');
          }
        }
      }
    };

    const runQueue = async () => {
      if (running || !(button instanceof HTMLButtonElement)) {
        return;
      }

      running = true;
      button.disabled = true;
      currentRunId = runIdInput instanceof HTMLInputElement ? runIdInput.value.trim() : '';
      initialWorkTotal = totalWorkFromSnapshot(currentSnapshotFromDom());
      lastKnownTotal = initialWorkTotal;
      lastProgressAt = Date.now();
      transientFailures = 0;
      updateProgress(currentSnapshotFromDom());
      try {
        setStatus(button.dataset.processingLabel ?? 'Processing queue...');
        const data = await startManualRunRequest();

        if (data.status === 'completed') {
          setStatus(button.dataset.completedLabel ?? 'Queue completed.');
          return;
        }

        if (data.status === 'error') {
          throw new Error(queueErrorText(data));
        }

        setStatus(button.dataset.processingBackgroundLabel ?? button.dataset.processingLabel ?? 'Processing queue...');
        await pollManualRun();
      } catch (error) {
        setStatus(error instanceof Error && error.message ? error.message : (button.dataset.failedLabel ?? 'Queue processing failed.'));
      } finally {
        running = false;
        initialWorkTotal = 0;
        updateProgress(currentSnapshotFromDom());
        button.disabled = false;
      }
    };

    queueForm.addEventListener('submit', (event) => {
      event.preventDefault();
      runQueue();
    });

    const initialRunId = runIdInput instanceof HTMLInputElement ? runIdInput.value.trim() : '';
    const initialRunStatus = runStatusInput instanceof HTMLInputElement ? runStatusInput.value.trim() : '';
    const initialRunTotal = runInitialTotalInput instanceof HTMLInputElement ? Number(runInitialTotalInput.value) : 0;
    if (initialRunId !== '' && initialRunStatus === 'running' && button instanceof HTMLButtonElement) {
      currentRunId = initialRunId;
      initialWorkTotal = Number.isFinite(initialRunTotal) ? initialRunTotal : totalWorkFromSnapshot(currentSnapshotFromDom());
      lastKnownTotal = totalWorkFromSnapshot(currentSnapshotFromDom());
      lastProgressAt = Date.now();
      running = true;
      button.disabled = true;
      updateProgress(currentSnapshotFromDom());
      setStatus(button.dataset.processingBackgroundLabel ?? button.dataset.processingLabel ?? 'Processing queue...');
      pollManualRun()
        .catch((error) => {
          setStatus(error instanceof Error && error.message ? error.message : (button.dataset.failedLabel ?? 'Queue processing failed.'));
        })
        .finally(() => {
          running = false;
          initialWorkTotal = 0;
          updateProgress(currentSnapshotFromDom());
          button.disabled = false;
        });
    }
  }
});

(function () {
  var lastGeneratedName = '';
  var lastSelectedTags = '';

  function findRuleForm() {
    return document.querySelector('[data-autolabel-rule-form]') || document.querySelector('form[action*="saveRule"]');
  }

  function findNameInput(form) {
    if (!form) {
      return null;
    }
    return form.querySelector('[data-autolabel-rule-name]') || form.querySelector('input[name="name"]');
  }

  function findTargetTagsSelect(form) {
    var selects;
    var index;
    if (!form) {
      return null;
    }
    if (form.querySelector('[data-autolabel-target-tags]')) {
      return form.querySelector('[data-autolabel-target-tags]');
    }
    selects = form.querySelectorAll('select');
    for (index = 0; index < selects.length; index += 1) {
      if (selects[index].name === 'target_tags[]') {
        return selects[index];
      }
    }
    return null;
  }

  function selectedTagNames(select) {
    var tags = [];
    var index;
    var boxes;
    if (!select) {
      return tags;
    }
    if (select.options) {
      for (index = 0; index < select.options.length; index += 1) {
        if (select.options[index].selected && select.options[index].value.trim() !== '') {
          tags.push(select.options[index].value.trim());
        }
      }
      return tags;
    }
    boxes = select.querySelectorAll('input[name="target_tags[]"]:checked');
    for (index = 0; index < boxes.length; index += 1) {
      if (boxes[index].value.trim() !== '') {
        tags.push(boxes[index].value.trim());
      }
    }
    return tags;
  }

  function syncRuleName() {
    var form = findRuleForm();
    var nameInput = findNameInput(form);
    var targetTagsSelect = findTargetTagsSelect(form);
    var tags = selectedTagNames(targetTagsSelect);
    var selectedKey = tags.join('\n');
    var generatedName = tags.join(', ');
    var currentName;

    if (!nameInput || !targetTagsSelect) {
      return;
    }

    currentName = nameInput.value.trim();
    if (currentName !== '' && currentName !== lastGeneratedName) {
      lastSelectedTags = selectedKey;
      return;
    }

    if (currentName === generatedName && selectedKey === lastSelectedTags) {
      return;
    }

    nameInput.value = generatedName;
    lastGeneratedName = generatedName;
    lastSelectedTags = selectedKey;
  }

  function handleEvent(event) {
    var target = event.target;
    if (!target || !target.matches) {
      return;
    }
    if (target.matches('select[name="target_tags[]"], input[name="target_tags[]"], [data-autolabel-target-tags], input[name="name"], [data-autolabel-rule-name]')) {
      window.setTimeout(syncRuleName, 0);
    }
  }

  ['change', 'input', 'click', 'mouseup', 'keyup'].forEach(function (eventName) {
    document.addEventListener(eventName, handleEvent, true);
  });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncRuleName);
  } else {
    syncRuleName();
  }
  for (var index = 1; index <= 20; index += 1) {
    window.setTimeout(syncRuleName, index * 250);
  }
}());
