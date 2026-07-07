(function () {
    var page = document.querySelector('[data-page]');
    var openButtons = document.querySelectorAll('[data-modal-open]');
    var closeButtons = document.querySelectorAll('[data-modal-close]');

    function closeAllModals() {
        document.querySelectorAll('.modal.open').forEach(function (modal) {
            modal.classList.remove('open');
        });
    }

    openButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var id = button.getAttribute('data-modal-open');
            if (!id) return;
            closeAllModals();
            var target = document.getElementById(id);
            if (target) target.classList.add('open');
        });
    });

    closeButtons.forEach(function (button) {
        button.addEventListener('click', closeAllModals);
    });

    var cpfInput = document.querySelector('input[name="cpf"]');
    if (cpfInput) {
        cpfInput.addEventListener('input', function () {
            var numbers = cpfInput.value.replace(/\D+/g, '').slice(0, 11);
            cpfInput.value = numbers;
        });
    }

    var menuToggle = document.querySelector('[data-menu-toggle]');
    var menu = document.querySelector('[data-menu]');
    var mobileMenuToggle = document.querySelector('[data-mobile-menu-toggle]');
    var mobileMenu = document.querySelector('[data-mobile-menu]');
    var mobileBackdrop = document.querySelector('.mobile-menu-backdrop');
    var mobileMenuClosers = document.querySelectorAll('[data-mobile-menu-close]');
    var pageBody = document.body;

    function setMobileMenuState(isOpen) {
        if (!mobileMenu) return;

        mobileMenu.hidden = !isOpen;
        if (mobileBackdrop) {
            mobileBackdrop.hidden = !isOpen;
        }
        mobileMenu.classList.toggle('open', isOpen);
        pageBody.classList.toggle('mobile-menu-open', isOpen);

        if (mobileMenuToggle) {
            mobileMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
    }

    if (menuToggle && menu && page) {
        menuToggle.addEventListener('click', function () {
            if (window.innerWidth > 1080) {
                page.classList.toggle('menu-collapsed');
            }
        });
    }

    if (mobileMenuToggle && mobileMenu) {
        mobileMenuToggle.addEventListener('click', function () {
            setMobileMenuState(!mobileMenu.classList.contains('open'));
        });

        mobileMenuClosers.forEach(function (node) {
            node.addEventListener('click', function () {
                setMobileMenuState(false);
            });
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 760) {
                setMobileMenuState(false);
            }
        });
    }
    var stakeInput = document.querySelector('[data-stake-input]');
    var totalOddNode = document.querySelector('[data-total-odd]');
    var potentialNode = document.querySelector('[data-potential-return]');
    var totalOdd = (window.betSlip && typeof window.betSlip.totalOdd === 'number') ? window.betSlip.totalOdd : 0;

    function toBRL(value) {
        return 'R$ ' + value.toFixed(2).replace('.', ',');
    }

    function updatePotential() {
        if (!stakeInput || !potentialNode) return;
        var stake = parseFloat(stakeInput.value || '0');
        if (Number.isNaN(stake)) stake = 0;
        potentialNode.textContent = toBRL(stake * totalOdd);
        if (totalOddNode) totalOddNode.textContent = totalOdd.toFixed(2).replace('.', ',');
    }

    document.querySelectorAll('[data-stake-value]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!stakeInput) return;
            stakeInput.value = button.getAttribute('data-stake-value');
            updatePotential();
        });
    });

    if (stakeInput) {
        stakeInput.addEventListener('input', updatePotential);
        updatePotential();
    }
})();


(function () {
    var flash = document.querySelector('.flash');
    if (!flash) return;
    setTimeout(function () {
        flash.style.transition = 'opacity .25s ease';
        flash.style.opacity = '0';
        setTimeout(function () {
            if (flash && flash.parentNode) flash.parentNode.removeChild(flash);
        }, 260);
    }, 3500);
})();


(function () {
    var adminShell = document.querySelector('[data-admin-shell]');
    if (!adminShell) return;

    var nav = document.querySelector('[data-admin-nav]');
    var links = nav ? nav.querySelectorAll('a[href^="#"]') : [];
    var sections = document.querySelectorAll('[data-admin-section]');
    var menuToggle = document.querySelector('[data-admin-menu-toggle]');
    var currentSectionLabel = document.querySelector('[data-admin-current-section]');

    function titleFor(id) {
        var title = 'Visao geral';

        links.forEach(function (link) {
            var linkId = (link.getAttribute('href') || '').replace('#', '');
            if (linkId === id) {
                title = (link.textContent || '').trim() || title;
            }
        });

        return title;
    }

    function openSection(id) {
        var target = id || 'visao-geral';

        sections.forEach(function (section) {
            section.classList.toggle('active', section.id === target);
        });

        links.forEach(function (link) {
            var linkId = (link.getAttribute('href') || '').replace('#', '');
            link.classList.toggle('active', linkId === target);
        });

        if (currentSectionLabel) {
            currentSectionLabel.textContent = titleFor(target);
        }
    }

    links.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            var id = (link.getAttribute('href') || '').replace('#', '');
            if (!id) return;
            openSection(id);
            if (window.innerWidth <= 980) {
                adminShell.classList.remove('menu-open');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
            window.location.hash = id;
        });
    });

    if (menuToggle) {
        menuToggle.addEventListener('click', function () {
            adminShell.classList.toggle('menu-open');
        });
    }

    var initial = window.location.hash ? window.location.hash.replace('#', '') : 'visao-geral';
    openSection(initial);
})();

(function () {
    var tables = document.querySelectorAll('.admin-content .table-wrap table');
    if (!tables.length) return;

    tables.forEach(function (table) {
        var headers = Array.prototype.map.call(table.querySelectorAll('thead th'), function (header) {
            return (header.textContent || '').replace(/\s+/g, ' ').trim();
        });

        if (!headers.length) return;

        Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (row) {
            Array.prototype.forEach.call(row.children, function (cell, index) {
                if (!(cell instanceof HTMLElement)) return;

                if (cell.hasAttribute('colspan') && Number(cell.getAttribute('colspan')) > 1) {
                    cell.setAttribute('data-label', '');
                    return;
                }

                var label = headers[index] || '';
                cell.setAttribute('data-label', label);
            });
        });
    });
})();

(function () {
    var support = document.querySelector('[data-support-float]');
    var supportClose = document.querySelector('[data-support-close]');
    var pwaBox = document.querySelector('[data-pwa-install]');
    var pwaClose = document.querySelector('[data-pwa-close]');
    var pwaInstallBtn = document.querySelector('[data-pwa-install-btn]');
    var deferredPrompt = null;

    function safeGet(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function safeSet(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (error) {
        }
    }

    function closeSupport(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        if (!support) return;
        support.hidden = true;
        support.style.display = 'none';
        safeSet('support_closed', '1');
    }

    function closePwa(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        if (!pwaBox) return;
        pwaBox.hidden = true;
        pwaBox.style.display = 'none';
        safeSet('pwa_prompt_closed', '1');
    }

    if (support) {
        if (safeGet('support_closed') === '1') {
            support.hidden = true;
            support.style.display = 'none';
        } else {
            support.hidden = false;
            support.style.display = '';
        }

        if (supportClose) {
            supportClose.addEventListener('click', closeSupport);
            supportClose.addEventListener('touchstart', closeSupport, { passive: false });
        }
    }

    function showPwaBox() {
        if (!pwaBox) return;
        if (safeGet('pwa_prompt_closed') === '1') return;

        var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        if (standalone) return;

        pwaBox.hidden = false;
        pwaBox.style.display = '';
    }

    if (pwaClose) {
        pwaClose.addEventListener('click', closePwa);
        pwaClose.addEventListener('touchstart', closePwa, { passive: false });
    }

    if (pwaBox && safeGet('pwa_prompt_closed') === '1') {
        pwaBox.hidden = true;
        pwaBox.style.display = 'none';
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        showPwaBox();
    });

    if (pwaInstallBtn) {
        pwaInstallBtn.addEventListener('click', function () {
            if (!deferredPrompt) {
                closePwa();
                return;
            }

            deferredPrompt.prompt();
            deferredPrompt.userChoice.finally(function () {
                deferredPrompt = null;
                safeSet('pwa_prompt_closed', '1');
                closePwa();
            });
        });
    }

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            var base = (window.APP_BASE_URL || '').replace(/\/+$/, '');
            var swUrl = (base !== '' ? base : '') + '/service-worker.js';
            navigator.serviceWorker.register(swUrl).catch(function () {});
        });
    }
})();




(function () {
    var builder = document.querySelector('[data-admin-odds-builder]');
    if (!builder) return;

    var rowsWrap = builder.querySelector('[data-odds-rows]');
    var addButton = builder.querySelector('[data-add-odd-row]');
    var presetButtons = builder.querySelectorAll('[data-add-odd-preset]');
    var presetFeedback = builder.querySelector('[data-odds-preset-feedback]');
    var searchInput = builder.querySelector('[data-odds-search]');
    var rowCountNode = builder.querySelector('[data-odds-row-count]');
    var marketCountNode = builder.querySelector('[data-odds-market-count]');
    var presets = {};
    if (!rowsWrap || !addButton) return;

    try {
        presets = JSON.parse(builder.getAttribute('data-odds-presets') || '{}') || {};
    } catch (error) {
        presets = {};
    }

    function createRow(marketName, optionName, oddValue) {
        var row = document.createElement('div');
        row.className = 'admin-odd-row';
        row.setAttribute('data-odd-row', '');
        row.innerHTML = '' +
            '<input type="text" name="extra_market_name[]" placeholder="Mercado (ex: Total de gols)" value="' + (marketName || '') + '">' +
            '<input type="text" name="extra_option_name[]" placeholder="Opcao (ex: Mais de 2.5)" value="' + (optionName || '') + '">' +
            '<input type="number" step="0.01" name="extra_odd_value[]" placeholder="Odd" value="' + (oddValue || '') + '">' +
            '<button type="button" class="btn-dark" data-remove-odd-row>Remover</button>';
        return row;
    }

    function normalizeValue(value) {
        return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function rowKey(marketName, optionName) {
        return normalizeValue(marketName) + '|' + normalizeValue(optionName);
    }

    function existingRowKeys() {
        var keys = {};

        rowsWrap.querySelectorAll('[data-odd-row]').forEach(function (row) {
            var marketInput = row.querySelector('input[name="extra_market_name[]"]');
            var optionInput = row.querySelector('input[name="extra_option_name[]"]');
            var key = rowKey(
                marketInput ? marketInput.value : '',
                optionInput ? optionInput.value : ''
            );

            if (key !== '|') {
                keys[key] = true;
            }
        });

        return keys;
    }

    function setPresetFeedback(message, tone) {
        if (!presetFeedback) return;
        presetFeedback.textContent = message || '';
        presetFeedback.setAttribute('data-tone', tone || '');
    }

    function refreshRowsState() {
        var query = normalizeValue(searchInput ? searchInput.value : '');
        var rowCount = 0;
        var markets = {};

        rowsWrap.querySelectorAll('[data-odd-row]').forEach(function (row) {
            var marketInput = row.querySelector('input[name="extra_market_name[]"]');
            var optionInput = row.querySelector('input[name="extra_option_name[]"]');
            var oddInput = row.querySelector('input[name="extra_odd_value[]"]');
            var marketName = marketInput ? marketInput.value : '';
            var optionName = optionInput ? optionInput.value : '';
            var oddValue = oddInput ? oddInput.value : '';

            if (normalizeValue(marketName) !== '' || normalizeValue(optionName) !== '' || normalizeValue(oddValue) !== '') {
                rowCount++;
            }

            if (normalizeValue(marketName) !== '') {
                markets[normalizeValue(marketName)] = true;
            }

            if (query === '') {
                row.hidden = false;
                return;
            }

            var haystack = normalizeValue(marketName + ' ' + optionName);
            row.hidden = haystack.indexOf(query) === -1;
        });

        if (rowCountNode) {
            rowCountNode.textContent = String(rowCount);
        }

        if (marketCountNode) {
            marketCountNode.textContent = String(Object.keys(markets).length);
        }
    }

    addButton.addEventListener('click', function () {
        setPresetFeedback('', '');
        rowsWrap.appendChild(createRow('', '', ''));
        refreshRowsState();
    });

    presetButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var presetKey = button.getAttribute('data-preset-key') || '';
            var preset = presets[presetKey];

            if (!preset || !Array.isArray(preset.rows) || !preset.rows.length) {
                setPresetFeedback('Nao foi possivel carregar esse pacote de mercados.', 'error');
                return;
            }

            var existingKeys = existingRowKeys();
            var added = 0;
            var skipped = 0;

            preset.rows.forEach(function (item) {
                var marketName = item && item.market_name ? item.market_name : '';
                var optionName = item && item.option_name ? item.option_name : '';
                var oddValue = item && item.odd_value ? item.odd_value : '';
                var key = rowKey(marketName, optionName);

                if (!marketName || !optionName || key === '|') {
                    return;
                }

                if (existingKeys[key]) {
                    skipped++;
                    return;
                }

                existingKeys[key] = true;
                rowsWrap.appendChild(createRow(marketName, optionName, oddValue));
                added++;
            });

            if (added > 0) {
                setPresetFeedback(
                    added + ' mercado(s) adicionado(s) do pacote "' + (preset.label || presetKey) + '".'
                    + (skipped > 0 ? ' ' + skipped + ' ja existiam e foram ignorados.' : ''),
                    'success'
                );
                refreshRowsState();
                return;
            }

            setPresetFeedback('Todos os mercados desse pacote ja estavam na lista.', 'info');
        });
    });

    rowsWrap.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof HTMLElement)) return;
        if (!target.hasAttribute('data-remove-odd-row')) return;

        var row = target.closest('[data-odd-row]');
        if (!row) return;
        row.remove();
        refreshRowsState();
    });

    rowsWrap.addEventListener('input', function () {
        refreshRowsState();
    });

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            refreshRowsState();
        });
    }

    refreshRowsState();
})();

(function () {
    document.querySelectorAll('[data-market-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var wrapper = button.closest('.match-main-odds');
            if (!wrapper) return;

            var panel = wrapper.querySelector('[data-market-panel]');
            if (!panel) return;

            var isHidden = panel.hasAttribute('hidden');
            if (isHidden) {
                panel.removeAttribute('hidden');
                button.setAttribute('aria-expanded', 'true');
                button.textContent = '-';
            } else {
                panel.setAttribute('hidden', 'hidden');
                button.setAttribute('aria-expanded', 'false');
                button.textContent = '+';
            }
        });
    });
})();

(function () {
    var blocks = Array.prototype.slice.call(document.querySelectorAll('[data-league-block]'));
    if (!blocks.length) return;

    var collapseAllButton = document.querySelector('[data-league-collapse-all]');
    var expandAllButton = document.querySelector('[data-league-expand-all]');
    var storageKey = 'home_league_collapsed_v1';
    var collapsedMap = {};

    function safeRead() {
        try {
            var raw = window.localStorage.getItem(storageKey);
            var parsed = raw ? JSON.parse(raw) : {};
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function safeWrite() {
        try {
            window.localStorage.setItem(storageKey, JSON.stringify(collapsedMap));
        } catch (error) {
        }
    }

    function blockKey(block) {
        return block.getAttribute('data-league-key') || '';
    }

    function setCollapsed(block, collapsed, options) {
        var opts = options || {};
        var key = blockKey(block);
        var toggle = block.querySelector('[data-league-toggle]');
        var panel = block.querySelector('[data-league-panel]');
        if (!toggle || !panel || !key) return;

        block.classList.toggle('is-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        panel.hidden = collapsed;

        if (opts.persist === false) return;

        if (collapsed) {
            collapsedMap[key] = 1;
        } else {
            delete collapsedMap[key];
        }

        safeWrite();
    }

    function restoreStoredState() {
        blocks.forEach(function (block) {
            setCollapsed(block, !!collapsedMap[blockKey(block)], { persist: false });
        });
    }

    function setAll(collapsed) {
        blocks.forEach(function (block) {
            setCollapsed(block, collapsed);
        });
    }

    function expandVisibleForSearch() {
        blocks.forEach(function (block) {
            if (block.hidden) return;
            setCollapsed(block, false, { persist: false });
        });
    }

    function expandByHash(hash) {
        if (!hash || hash.charAt(0) !== '#') return;

        var target = document.querySelector(hash);
        if (!target || !target.matches('[data-league-block]')) return;

        setCollapsed(target, false);
    }

    collapsedMap = safeRead();
    restoreStoredState();
    expandByHash(window.location.hash || '');

    blocks.forEach(function (block) {
        var toggle = block.querySelector('[data-league-toggle]');
        if (!toggle) return;

        toggle.addEventListener('click', function () {
            var isCollapsed = block.classList.contains('is-collapsed');
            setCollapsed(block, !isCollapsed);
        });
    });

    if (collapseAllButton) {
        collapseAllButton.addEventListener('click', function () {
            setAll(true);
        });
    }

    if (expandAllButton) {
        expandAllButton.addEventListener('click', function () {
            setAll(false);
        });
    }

    window.addEventListener('hashchange', function () {
        expandByHash(window.location.hash || '');
    });

    window.homeLeagueCollapse = {
        expandVisibleForSearch: expandVisibleForSearch,
        restoreStoredState: restoreStoredState,
        expandByHash: expandByHash,
    };
})();

(function () {
    var searchInput = document.querySelector('[data-game-search]');
    if (!searchInput) return;

    var gameRows = Array.prototype.slice.call(document.querySelectorAll('[data-game-row]'));
    var leagueBlocks = Array.prototype.slice.call(document.querySelectorAll('[data-league-block]'));
    var emptySearch = document.querySelector('[data-search-empty]');
    var submitTimer = null;
    var initialTerm = searchInput.value || '';

    function normalize(text) {
        return (text || '')
            .toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
    }

    function filterGames() {
        var term = normalize(searchInput.value);
        var visibleGames = 0;

        gameRows.forEach(function (row) {
            var text = normalize(row.getAttribute('data-search-text'));
            var show = term === '' || text.indexOf(term) !== -1;
            row.hidden = !show;
            if (show) {
                visibleGames += 1;
            }
        });

        leagueBlocks.forEach(function (block) {
            var hasVisibleGame = Array.prototype.some.call(block.querySelectorAll('[data-game-row]'), function (row) {
                return !row.hidden;
            });
            block.hidden = !hasVisibleGame;
        });

        if (window.homeLeagueCollapse) {
            if (term !== '') {
                window.homeLeagueCollapse.expandVisibleForSearch();
            } else {
                window.homeLeagueCollapse.restoreStoredState();
            }
        }

        if (emptySearch) {
            emptySearch.hidden = visibleGames !== 0 || term === '';
        }

        return visibleGames;
    }

    function submitSearch() {
        var baseUrl = searchInput.getAttribute('data-search-url') || '/';
        var category = searchInput.getAttribute('data-search-category') || '';
        var league = searchInput.getAttribute('data-search-league') || '';
        var value = searchInput.value.trim();
        var params = new URLSearchParams();

        if (category !== '') {
            params.set('cat', category);
        }

        if (league !== '' && league !== '0') {
            params.set('league', league);
        }

        if (value !== '') {
            params.set('q', value);
        }

        window.location.href = params.toString() ? (baseUrl + '?' + params.toString()) : baseUrl;
    }

    searchInput.addEventListener('input', function () {
        var visibleGames = filterGames();
        var term = searchInput.value.trim();

        if (submitTimer) {
            window.clearTimeout(submitTimer);
        }

        if (term === '') {
            return;
        }

        if (visibleGames === 0 || normalize(term) !== normalize(initialTerm)) {
            submitTimer = window.setTimeout(submitSearch, 450);
        }
    });

    searchInput.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        if (submitTimer) {
            window.clearTimeout(submitTimer);
        }
        submitSearch();
    });

    filterGames();
})();
(function () {
    if (window.innerWidth > 760) return;
    if (window.location.hash !== '#betslip-panel') return;

    var panel = document.getElementById('betslip-panel');
    if (!panel) return;

    window.setTimeout(function () {
        panel.scrollIntoView({ behavior: 'auto', block: 'start' });
    }, 60);
})();


