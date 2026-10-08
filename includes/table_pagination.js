(function () {
    'use strict';

    const PAGE_SIZE = 15;
    const registry = new WeakMap();

    function shouldSkip(table) {
        if (!table || table.dataset.noPagination === '1' || table.dataset.serverPaginated === '1') return true;
        if (!table.tBodies || !table.tBodies.length) return true;
        if (table.closest('.print-frame, .cal, .calendar, [data-no-pagination]')) return true;
        if (table.classList.contains('no-pagination')) return true;

        // Do not double-paginate a table that already has server-side controls.
        const wrapper = table.closest('.table-responsive, .table-wrapper, .table-wrap');
        if (wrapper && wrapper.nextElementSibling && wrapper.nextElementSibling.classList.contains('pagination')) return true;
        if (table.parentElement && table.parentElement.nextElementSibling && table.parentElement.nextElementSibling.classList.contains('pagination')) return true;
        return false;
    }

    function rowsFor(table) {
        const tbody = table.tBodies[0];
        if (!tbody) return [];
        return Array.from(tbody.rows).filter(row => {
            if (row.classList.contains('copies-row')) return false;
            if (row.classList.contains('empty') || row.classList.contains('no-data') || row.classList.contains('empty-message')) return false;
            if (row.dataset.paginationSpacer === '1' || row.dataset.paginationIgnore === '1') return false;

            // Empty-state tables commonly use one TD spanning the whole header.
            // That cell is a message, not a record, and should not count as page data.
            const cells = row.cells ? Array.from(row.cells) : [];
            if (cells.length === 1 && cells[0].hasAttribute('colspan')) return false;
            return true;
        });
    }

    function getPagerHost(table) {
        return table.closest('.table-responsive, .table-wrapper, .table-wrap') || table.parentElement || table;
    }

    function ensurePager(table) {
        const current = registry.get(table);
        if (current && current.pager && document.contains(current.pager)) return current.pager;

        const pager = document.createElement('div');
        pager.className = 'table-pagination';
        pager.setAttribute('aria-label', 'Table pagination');
        getPagerHost(table).insertAdjacentElement('afterend', pager);
        registry.set(table, { page: 1, pager });
        return pager;
    }

    function visibleRows(rows) {
        return rows.filter(row => row.dataset.filterHidden !== '1');
    }

    function setRowVisibility(row, hidden) {
        row.dataset.paginationHidden = hidden ? '1' : '0';
        const filterHidden = row.dataset.filterHidden === '1';
        row.style.display = hidden || filterHidden ? 'none' : '';
    }

    function pageButtons(totalPages, currentPage) {
        const pages = [];
        const add = page => { if (page >= 1 && page <= totalPages && !pages.includes(page)) pages.push(page); };
        add(1); add(2); add(3);
        if (currentPage > 3) add(currentPage);
        if (currentPage === totalPages && totalPages > 3) add(totalPages);
        return pages.sort((a, b) => a - b);
    }

    function renderPager(table, page, totalRows, totalPages) {
        const state = registry.get(table);
        const pager = state && state.pager;
        if (!pager) return;

        pager.innerHTML = '';
        if (totalRows === 0) {
            pager.style.display = 'none';
            return;
        }

        pager.style.display = 'flex';

        const from = totalRows ? ((page - 1) * PAGE_SIZE) + 1 : 0;
        const to = Math.min(page * PAGE_SIZE, totalRows);
        const summary = document.createElement('span');
        summary.className = 'table-pagination-summary';
        summary.textContent = 'Showing ' + from + '–' + to + ' of ' + totalRows;
        pager.appendChild(summary);

        const controls = document.createElement('div');
        controls.className = 'table-pagination-controls';
        pager.appendChild(controls);

        function addButton(label, target, disabled, active, ariaLabel) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'table-pagination-button' + (active ? ' active' : '');
            button.textContent = label;
            button.disabled = !!disabled;
            button.setAttribute('aria-label', ariaLabel || ('Page ' + label));
            button.title = ariaLabel || ('Page ' + label);
            if (!disabled) {
                button.addEventListener('click', function () {
                    const nextState = registry.get(table);
                    if (!nextState) return;
                    nextState.page = target;
                    apply(table);
                    table.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                });
            }
            controls.appendChild(button);
        }

        // Consistent order used across Student and Teacher tables: « | ‹ | 1 | 2 | 3 | › | »
        addButton('«', 1, page <= 1, false, 'First page');
        addButton('‹', Math.max(1, page - 1), page <= 1, false, 'Previous page');

        const numbers = pageButtons(totalPages, page);
        numbers.forEach((number) => {
            addButton(String(number), number, false, number === page);
        });

        addButton('›', Math.min(totalPages, page + 1), page >= totalPages, false, 'Next page');
        addButton('»', totalPages, page >= totalPages, false, 'Last page');
    }

    function apply(table, resetPage) {
        if (shouldSkip(table)) return;
        const state = registry.get(table) || { page: 1, pager: null };
        if (!state.pager) state.pager = ensurePager(table);
        if (resetPage) state.page = 1;
        registry.set(table, state);

        const rows = rowsFor(table);
        const eligible = visibleRows(rows);
        const totalPages = Math.max(1, Math.ceil(eligible.length / PAGE_SIZE));
        state.page = Math.min(Math.max(1, Number(state.page) || 1), totalPages);

        const start = (state.page - 1) * PAGE_SIZE;
        const end = start + PAGE_SIZE;
        const visibleSet = new Set(eligible.slice(start, end));
        rows.forEach(row => setRowVisibility(row, !visibleSet.has(row)));
        renderPager(table, state.page, eligible.length, totalPages);
    }

    function initTable(table) {
        if (shouldSkip(table)) return;
        if (!registry.has(table)) {
            const pager = ensurePager(table);
            registry.set(table, { page: 1, pager });
        }
        apply(table);
    }

    function initAll(root) {
        const scope = root && root.querySelectorAll ? root : document;
        if (scope.matches && scope.matches('table')) initTable(scope);
        scope.querySelectorAll('table').forEach(initTable);
    }

    window.TablePagination = {
        PAGE_SIZE,
        refreshTable: apply,
        resetTable: function (table) { apply(table, true); },
        refreshAll: function () { initAll(document); }
    };

    document.addEventListener('DOMContentLoaded', function () {
        initAll(document);

        const observer = new MutationObserver(function (mutations) {
            const refreshTables = new Set();
            mutations.forEach(mutation => {
                mutation.addedNodes.forEach(node => {
                    if (node.nodeType !== 1) return;
                    if (node.matches('table')) initTable(node);
                    node.querySelectorAll && node.querySelectorAll('table').forEach(initTable);
                    if (node.matches('tr')) {
                        const table = node.closest('table');
                        if (table) refreshTables.add(table);
                    }
                });
            });
            refreshTables.forEach(table => apply(table));
        });
        observer.observe(document.body, { childList: true, subtree: true });
    });
})();
