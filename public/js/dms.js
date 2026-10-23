/* global jQuery, bootstrap */
(function ($) {
  'use strict';
  const fileError = (file) =>
    !file
      ? 'Choose a file.'
      : file.size > 10 * 1024 * 1024
        ? 'Choose a file no larger than 10 MB.'
        : '';
  const strongPassword = (value) =>
    value.length >= 12 &&
    /[a-z]/.test(value) &&
    /[A-Z]/.test(value) &&
    /[0-9]/.test(value) &&
    /[^A-Za-z0-9]/.test(value);
  // Exposed only as pure validators for the dependency-free frontend checks.
  window.DmsValidation = { fileError, strongPassword };
  window.DmsStatusBadge = {
    update: function (el, status) {
      const $el = $(el);
      const s = String(status || '').toLowerCase();
      $el.removeClass('clean pending failed quarantined neutral')
        .addClass(s)
        .text(status)
        .attr('data-status', status);
    },
  };
  window.DmsUploadQueue = async function (items, send, update) {
    async function worker() {
      let item;
      while ((item = items.find((row) => row.status === 'pending'))) {
        item.status = 'uploading';
        update();
        try {
          await send(item);
          item.status = 'saved';
          item.error = '';
        } catch (error) {
          item.status = 'failed';
          item.error = error.message || 'Upload failed.';
        }
        update();
      }
    }
    await Promise.all([worker(), worker()]);
  };
  $(function () {
    const scanUrl = $('[data-scan-status-url]').attr('data-scan-status-url');
    if (
      scanUrl &&
      $('.version-row[data-status="Pending"],.version-row[data-status="Failed"]').length
    ) {
      let polls = 0;
      let hasUnsavedInput = false;
      $('form').on('input change', () => {
        hasUnsavedInput = true;
      });
      const poll = setInterval(() => {
        if (document.hidden) return;
        if (++polls > 120) {
          clearInterval(poll);
          return;
        }
        $.getJSON(scanUrl)
          .done((result) => {
            if (
              result.versions.some(
                (v) =>
                  $('.version-row[data-version-id="' + v.id + '"]').attr('data-status') !==
                  v.scan_status
              )
            ) {
              clearInterval(poll);
              // Preserve drafts and open dialogs when an automatic scan finishes.
              if (hasUnsavedInput || document.querySelector('.modal.show'))
                toast('Scan status updated. Refresh after saving your changes to see the result.');
              else location.reload();
            }
          })
          .fail((xhr) => {
            if ([401, 403, 404, 419].includes(xhr.status)) clearInterval(poll);
          });
      }, 5000);
    }
    let state = {};
    function updateStarAria(starButton, id) {
      if (starButton && id && state[id]) {
        starButton.attr('aria-pressed', String(state[id].starred));
      }
    }

    let toastTimer;
    function toast(message) {
      const box = document.getElementById('feedback-toast');
      if (!box) return;
      clearInterval(toastTimer);
      $(box).find('.toast-body').text(message);
      const countdown = $(box).find('.toast-countdown').progressbar({ max: 4000, value: 4000 });
      const notification = bootstrap.Toast.getOrCreateInstance(box, {
        autohide: false,
        animation: false,
      });
      notification.show();
      const started = performance.now();
      toastTimer = setInterval(() => {
        const remaining = Math.max(0, 4000 - (performance.now() - started));
        countdown.progressbar('value', remaining);
        if (!remaining) {
          clearInterval(toastTimer);
          notification.hide();
        }
      }, 50);
    }
    $('#feedback-toast').on('hidden.bs.toast', () => clearInterval(toastTimer));
    const initialStatus = $('#feedback-toast .toast-body').text().trim();
    if (initialStatus) toast(initialStatus);
    function closeModal(form) {
      const modal = $(form).closest('.modal')[0];
      if (modal) bootstrap.Modal.getInstance(modal)?.hide();
    }
    function menu(open) {
      $('#sidebar').toggleClass('is-open', open);
      $('#sidebar-scrim').prop('hidden', !open);
      $('#menu-toggle').attr({
        'aria-expanded': String(open),
        'aria-label': open ? 'Close navigation' : 'Open navigation',
      });
      if (open) $('#sidebar a').first().trigger('focus');
    }
    $('#menu-toggle').on('click', () => menu(!$('#sidebar').hasClass('is-open')));
    $('#sidebar-scrim').on('click', () => menu(false));
    $(document).on('keydown', (event) => {
      if (event.key === 'Escape' && $('#sidebar').hasClass('is-open')) {
        menu(false);
        $('#menu-toggle').trigger('focus');
      }
    });
    $('#sidebar').on('keydown', (event) => {
      if (event.key !== 'Tab' || !$('#sidebar').hasClass('is-open')) return;
      const controls = $('#sidebar').find('a,button').filter(':visible');
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    // Desktop / Tablet Sidebar Collapse Toggle
    const sidebarToggle = $('#sidebar-toggle');
    const isTabletViewport = () => window.innerWidth >= 768 && window.innerWidth < 1200;
    let sidebarTooltips = [];

    function updateSidebarTooltips(enabled) {
      sidebarTooltips.forEach((t) => {
        try {
          t.dispose();
        } catch (_e) {}
      });
      sidebarTooltips = [];
      if (enabled && typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        $('#sidebar [data-bs-toggle="tooltip"]').each(function () {
          try {
            sidebarTooltips.push(new bootstrap.Tooltip(this, { container: 'body', trigger: 'hover focus' }));
          } catch (_e) {}
        });
      }
    }

    function setSidebarCollapsed(collapsed, userInitiated = false) {
      $('html').toggleClass('sidebar-collapsed', collapsed);
      $('body').toggleClass('sidebar-collapsed', collapsed);
      $('#sidebar').toggleClass('is-collapsed', collapsed);
      const label = collapsed ? 'Expand navigation sidebar' : 'Collapse navigation sidebar';
      const tooltipTitle = collapsed ? 'Expand navigation (Ctrl+B)' : 'Collapse navigation (Ctrl+B)';
      sidebarToggle.attr({
        'aria-expanded': String(!collapsed),
        'aria-label': label,
        'title': tooltipTitle,
      });
      sidebarToggle.find('.sidebar-toggle-icon').text(collapsed ? '»' : '«');

      // Update .brand (PLP Documents icon) behavior: acts as sidebar toggle when collapsed (Gemini-style)
      const brand = $('.brand');
      if (collapsed) {
        brand.attr({
          'title': 'Expand navigation (Ctrl+B)',
          'aria-label': 'Expand navigation sidebar',
          'role': 'button',
        });
      } else {
        brand.attr({
          'title': 'PLP Documents · Document Management',
          'aria-label': 'PLP Documents home',
        }).removeAttr('role');
      }

      if (userInitiated) {
        try {
          localStorage.setItem('dms_sidebar_collapsed', collapsed ? 'true' : 'false');
          document.cookie = 'dms_sidebar_collapsed=' + (collapsed ? 'true' : 'false') + ';path=/;max-age=31536000;SameSite=Lax';
        } catch (_e) {}
      }
      updateSidebarTooltips(collapsed);
    }

    function initSidebarState() {
      try {
        const saved = localStorage.getItem('dms_sidebar_collapsed');
        if (saved !== null) {
          setSidebarCollapsed(saved === 'true');
        } else if (isTabletViewport()) {
          setSidebarCollapsed(true);
        } else {
          setSidebarCollapsed($('html').hasClass('sidebar-collapsed'));
        }
      } catch (_e) {
        if (isTabletViewport()) setSidebarCollapsed(true);
      }
    }

    // PLP Documents icon: when collapsed, clicking it expands the sidebar (like Gemini website)
    $('.brand').on('click', function (event) {
      const isCollapsed = $('html').hasClass('sidebar-collapsed') || $('body').hasClass('sidebar-collapsed');
      if (isCollapsed) {
        event.preventDefault();
        setSidebarCollapsed(false, true);
      }
    });

    sidebarToggle.on('click', () => {
      const isCurrentlyCollapsed = $('html').hasClass('sidebar-collapsed') || $('body').hasClass('sidebar-collapsed');
      setSidebarCollapsed(!isCurrentlyCollapsed, true);
    });

    $(document).on('keydown', (event) => {
      if ((event.ctrlKey || event.metaKey || event.altKey) && (event.key === 'b' || event.key === 'B')) {
        if (!$(event.target).is('input, textarea, select')) {
          event.preventDefault();
          const isCurrentlyCollapsed = $('html').hasClass('sidebar-collapsed') || $('body').hasClass('sidebar-collapsed');
          setSidebarCollapsed(!isCurrentlyCollapsed, true);
        }
      }
    });

    function updateDeviceMode() {
      if (typeof window === 'undefined' || typeof document === 'undefined' || !document.body) return;
      const width = typeof window.innerWidth === 'number' ? window.innerWidth : 1024;
      const isMobile = width < 768;
      if (isMobile) {
        if (typeof document.body.setAttribute === 'function') {
          document.body.setAttribute('data-device', 'mobile');
        }
      } else {
        if (typeof document.body.removeAttribute === 'function') {
          document.body.removeAttribute('data-device');
        }
      }
    }

    $(window).on('resize', () => {
      updateDeviceMode();
      try {
        if (localStorage.getItem('dms_sidebar_collapsed') === null && window.innerWidth >= 768) {
          setSidebarCollapsed(isTabletViewport(), false);
        }
      } catch (_e) {}
    });

    updateDeviceMode();

    initSidebarState();

    $('[data-global-back]').on('click', function (event) {
      try {
        if (
          document.referrer &&
          new URL(document.referrer).origin === location.origin &&
          new URL(document.referrer).pathname !== '/login' &&
          document.referrer !== location.href &&
          history.length > 1
        ) {
          event.preventDefault();
          history.back();
        }
      } catch {
        /* Use the server-rendered parent link. */
      }
    });
    async function moveDocument(row, folderId) {
      if (row.attr('data-moving') === 'true') return false;
      if ((row.attr('data-folder-id') || '') === (folderId || '')) {
        toast('This document is already in that folder.');
        return false;
      }
      row.attr('data-moving', 'true');
      try {
        const result = await $.ajax({
          url: '/documents/' + encodeURIComponent(row.attr('data-document-id')),
          method: 'PATCH',
          headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
          },
          data: {
            title: row.attr('data-title'),
            folder_id: folderId || '',
            revision: row.attr('data-revision'),
          },
        });
        const previousFolder = row.attr('data-folder-id') || '';
        $('.folder-card[data-folder-id]').each(function () {
          const delta =
            this.dataset.folderId === result.folder_id
              ? 1
              : this.dataset.folderId === previousFolder
                ? -1
                : 0;
          if (delta) {
            const total = Math.max(0, Number(this.dataset.documentCount || 0) + delta);
            this.dataset.documentCount = total;
            $(this)
              .find('small')
              .text(total + ' documents');
          }
        });
        row.attr({
          'data-folder-id': result.folder_id || '',
          'data-revision': result.revision,
        });
        const query = new URLSearchParams(location.search);
        if (
          document.body.dataset.section === 'documents' &&
          (query.has('folder') ||
            (!query.has('q') && [null, 'all', 'mine'].includes(query.get('view'))))
        )
          row.remove();
        if (!$('.document-row').length) $('#document-empty').prop('hidden', false);
        const modal = bootstrap.Modal.getInstance(document.getElementById('move-document-modal'));
        modal?.hide();
        toast(result.message);
        return true;
      } catch (xhr) {
        const message = xhr.responseJSON?.errors
          ? Object.values(xhr.responseJSON.errors).flat().join(' ')
          : xhr.status === 409
            ? 'Document changed. Refresh and try again.'
            : xhr.responseJSON?.message || 'Could not move this document.';
        $('#move-document-feedback').text(message);
        toast(message);
        return false;
      } finally {
        row.removeAttr('data-moving');
      }
    }
    $('#move-document-modal').on('show.bs.modal', function (event) {
      const id = $(event.relatedTarget).attr('data-move-document');
      const row = $('.document-row').filter(function () {
        return this.dataset.documentId === id;
      });
      $(this).data('move-row', row);
      $('#move-document-name').text(row.attr('data-title'));
      $('#move-document-feedback').text('');
      $('#move-document-folder option').each(function () {
        this.disabled = !!this.value && this.dataset.source !== row.attr('data-source');
      });
      $('#move-document-folder').val(row.attr('data-folder-id') || '');
    });
    $('#move-document-form').on('submit', async function (event) {
      event.preventDefault();
      const button = $(this).find('[type="submit"]').prop('disabled', true);
      try {
        await moveDocument(
          $('#move-document-modal').data('move-row'),
          $('#move-document-folder').val()
        );
      } finally {
        button.prop('disabled', false);
      }
    });
    // D-11: jQuery UI drag is pointer-only and fights native touch scrolling.
    // On a coarse pointer the move menu is the primary path, so drag is disabled
    // and scroll stays predictable. Desktop behaviour is untouched.
    const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
    $('.document-row[data-can-move="true"]').draggable({
      disabled: coarsePointer,
      handle: '.file-cell',
      cancel: 'button,input,select,textarea,.document-actions',
      distance: 8,
      appendTo: 'body',
      zIndex: 1080,
      revert: 'invalid',
      helper: function () {
        return $('<div class="document-drag-preview">').text($(this).attr('data-title'));
      },
    });
    $('.folder-card[data-folder-id]').droppable({
      tolerance: 'pointer',
      classes: {
        'ui-droppable-hover': 'folder-drop-hover',
        'ui-droppable-active': 'folder-drop-ready',
      },
      accept: function (item) {
        return (
          item.attr('data-can-move') === 'true' && item.attr('data-source') === this.dataset.source
        );
      },
      drop: function (event, ui) {
        moveDocument(ui.draggable, this.dataset.folderId);
      },
    });
    // D-5: Bootstrap does not close a dropdown when another one opens, so two
    // row menus can be open at once. Close any other open menu first.
    $(document).on('show.bs.dropdown', function (event) {
      const opening = event.target;
      $('.dropdown-menu.show')
        .filter((_, menu) => menu !== opening.parentElement.querySelector('.dropdown-menu'))
        .each((__, menu) => {
          const toggle = $(menu).closest('.dropdown').find('[data-bs-toggle="dropdown"]')[0];
          if (toggle && toggle !== opening) bootstrap.Dropdown.getInstance(toggle)?.hide();
        });
    });
    document.querySelectorAll('.document-menu-toggle').forEach(
      (toggle) =>
        new bootstrap.Dropdown(toggle, {
          popperConfig: (config) => ({
            ...config,
            strategy: 'fixed',
            modifiers: [
              ...config.modifiers,
              {
                name: 'preventOverflow',
                options: { boundary: document.body, padding: 12 },
              },
              {
                name: 'flip',
                options: {
                  fallbackPlacements: ['top-end', 'bottom-end'],
                  padding: 12,
                },
              },
            ],
          }),
        })
    );
    document
      .querySelectorAll('[data-bs-toggle="popover"]')
      .forEach((node) => new bootstrap.Popover(node, { trigger: 'focus', container: 'body' }));
    $('.modal').on('shown.bs.modal', function () {
      $(this)
        .find('input:not([type="hidden"]),select,textarea')
        .filter(':visible')
        .first()
        .trigger('focus');
    });
    // D-3: remember what opened the dialog so focus can be handed back on close.
    // Bootstrap moves focus into the modal itself, which strands keyboard users
    // on <body> after Esc or the backdrop.
    $(document).on('show.bs.modal', '.modal', function () {
      this._returnFocus =
        document.activeElement && document.activeElement !== document.body
          ? document.activeElement
          : null;
    });
    $(document).on('hidden.bs.modal', '.modal', function () {
      const target = this._returnFocus;
      if (target && document.body.contains(target)) {
        target.focus();
      }
    });
    $('.datepicker').datepicker({
      dateFormat: 'yy-mm-dd',
      minDate: 1,
      changeMonth: true,
      changeYear: true,
    });
    const recipients = $('#share-recipient option')
      .filter(function () {
        return this.value !== '';
      })
      .map(function () {
        return { label: $(this).text(), value: this.value };
      })
      .get();
    $('#share-search')
      .autocomplete({
        source: recipients,
        appendTo: '#share-modal .modal-body',
        minLength: 0,
        select: function (event, ui) {
          event.preventDefault();
          $(this).val(ui.item.label);
          $('#share-recipient').val(ui.item.value);
        },
        focus: (event) => event.preventDefault(),
      })
      .on('focus', function () {
        $(this).autocomplete('search', this.value);
      });
    function permission() {
      const pinned = $('#share-version').val() !== '';
      const editor = $('#share-permission option[value="Editor"]');
      editor.prop('disabled', pinned);
      if (pinned && $('#share-permission').val() === 'Editor') $('#share-permission').val('Viewer');
    }
    $('#share-version').on('change', permission);
    permission();
    $('[data-account-role]')
      .on('change', function () {
        const source = $(this).closest('form').find('[data-account-source]');
        const isSuperAdmin = this.value === 'super_admin';
        const allowed = isSuperAdmin ? [] : ['EMS', 'PMS', 'ES', 'OAS'];
        source.find('option').each(function () {
          this.disabled = !allowed.includes(this.value);
        });
        source.prop('disabled', isSuperAdmin);
        if (isSuperAdmin) source.val('');
        else if (!allowed.includes(source.val())) source.val(allowed[0]);
      })
      .trigger('change');
    $('[data-password-policy]').on('input', function () {
      this.setCustomValidity(
        this.value && !strongPassword(this.value)
          ? 'Use 12+ characters with upper- and lowercase letters, a number, and a symbol.'
          : ''
      );
    });
    $('.password-toggle').on('click', function () {
      const input = $(this).siblings('input');
      const show = input.attr('type') === 'password';
      input.attr('type', show ? 'text' : 'password');
      $(this)
        .text(show ? 'Hide' : 'Show')
        .attr({
          'aria-label': show ? 'Hide password' : 'Show password',
          'aria-pressed': String(show),
        });
    });
    const documentTabs = $('#document-tabs');
    if (documentTabs.length) {
      documentTabs.tabs({
        activate: (_event, ui) => {
          // Keep the selected section when a completed background scan refreshes the page.
          history.replaceState(null, '', '#' + ui.newPanel.attr('id'));
        },
      });
    }
    $('[data-preview-version]').on('click', function () {
      if (documentTabs.length) documentTabs.tabs('option', 'active', 2);
      $('#detail-download')
        .attr('href', this.dataset.versionDownload)
        .text('Download ' + this.dataset.previewVersion.toLowerCase());
      $('#document-preview-frame').prop('hidden', false);
      $('#preview-unavailable').prop('hidden', true);
      $('#preview-version-label').text(this.dataset.previewVersion);
    });
    $('#comment').on('input', function () {
      $('#comment-count').text(this.value.length.toLocaleString());
    });
    $('#payload-modal').on('show.bs.modal', function (event) {
      const button = $(event.relatedTarget);
      const value = button.attr('data-payload') || '{}';
      let pretty;
      try {
        pretty = JSON.stringify(JSON.parse(value), null, 2);
      } catch {
        pretty = value;
      }
      $('#payload-record').text(
        button.attr('data-record') +
          (button.attr('data-revision') ? ' · Revision ' + button.attr('data-revision') : '')
      );
      $('#payload-content').text(pretty);
    });
    let staffFilter = 'all';
    // Zebra striping for the document list. :nth-of-type cannot be used because
    // it counts rows the filter has hidden, so parity is applied here over the
    // visible rows only. Grid layout keeps its card surfaces and is skipped.
    function restripeDocuments() {
      const list = document.getElementById('document-items');
      if (!list || list.classList.contains('is-grid')) return;
      let stripe = false;
      for (const row of list.querySelectorAll('.document-row')) {
        if (row.hidden) continue;
        row.classList.toggle('is-striped', stripe);
        stripe = !stripe;
      }
    }
    function filter() {
      const q = String(
        $('[data-search-form] input[type="search"]').val() || ''
      ).toLocaleLowerCase();
      const filterClass = $('#document-class').val() || '';
      const filterStatus = $('#document-status').val() || '';
      let visible = 0;
      $('.document-row').each(function () {
        const row = $(this);
        const id = row.attr('data-document-id');
        const changes = state[id] || {};
        const view = new URLSearchParams(location.search).get('view') || 'all';
        const trashed = changes.trashed ?? row.attr('data-trashed') === 'true';
        const starred = changes.starred ?? row.attr('data-starred') === 'true';
        const visibleByState = view === 'trash' ? trashed : !trashed;
        const matches = (row.attr('data-title') + ' ' + row.attr('data-reference'))
          .toLocaleLowerCase()
          .includes(q);
        const rowClass = row.find('.classification').text().trim();
        const rowStatus = row.attr('data-status') || '';
        const matchesClass = !filterClass || rowClass === filterClass;
        const matchesStatus = !filterStatus || rowStatus.toLowerCase() === filterStatus.toLowerCase();
        const show = matches && visibleByState && (view !== 'starred' || starred) && matchesClass && matchesStatus;
        row.prop('hidden', !show);
        if (show) visible++;
      });
      // Re-stripe on the visible sequence. CSS :nth-of-type counts hidden rows
      // too, so filtering to a result set leaves two identical bands adjacent
      // wherever a hidden row sat between two survivors.
      restripeDocuments();
      $('#document-empty').prop('hidden', visible > 0);
      $('[data-filter-table] tbody tr').each(function () {
        const row = $(this);
        const source = $('#repository-source,#audit-source').val() || '';
        const classification = $('#repository-class').val() || '';
        const status = $('#repository-status').val() || '';
        const show =
          row.text().toLocaleLowerCase().includes(q) &&
          (!source || row.attr('data-source') === source) &&
          (!classification || row.attr('data-classification') === classification) &&
          (!status || row.attr('data-doc-status') === status) &&
          (staffFilter === 'all' || row.attr('data-staff-status') === staffFilter);
        row.prop('hidden', !show);
      });
    }

    // D-10: debounce the client-side filter. Filtering every keystroke re-scans
    // every row and re-reads location.search inside the loop, which is visibly
    // slow on long lists. Selects still fire immediately.
    let filterTimer = null;
    const filterSoon = () => {
      clearTimeout(filterTimer);
      filterTimer = setTimeout(filter, 180);
    };
    $('[data-search-form] input[type="search"]').on('input', filterSoon);
    $('#repository-source,#repository-class,#repository-status,#audit-source,#document-class,#document-status').on('change', filter);
    $('[data-staff-filter]').on('click', function () {
      staffFilter = this.dataset.staffFilter;
      $('[data-staff-filter]').removeClass('active').attr('aria-pressed', 'false');
      $(this).addClass('active').attr('aria-pressed', 'true');
      filter();
    });
    $('[data-layout]').on('click', function () {
      const grid = this.dataset.layout === 'grid';
      $('#document-items').toggleClass('is-grid', grid);
      $('[data-layout]').removeClass('selected').attr('aria-pressed', 'false');
      $(this).addClass('selected').attr('aria-pressed', 'true');
      const url = new URL(location.href);
      url.searchParams.set('layout', this.dataset.layout);
      history.replaceState(null, '', url);
    });

    $('.document-column-headings .sort-header').on('click keydown', function (event) {
      if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
      if (event.type === 'keydown') event.preventDefault();
      const column = this.dataset.sort;
      const currentSort = this.getAttribute('aria-sort');
      const newSort = currentSort === 'ascending' ? 'descending' : 'ascending';
      $('.document-column-headings .sort-header').attr('aria-sort', 'none');
      this.setAttribute('aria-sort', newSort);

      const items = $('#document-items');
      const rows = items.children('.document-row').get();
      rows.sort((a, b) => {
        let valA = '', valB = '';
        if (column === 'title') {
          valA = (a.dataset.title || '').toLowerCase();
          valB = (b.dataset.title || '').toLowerCase();
        } else if (column === 'class') {
          valA = $(a).find('.classification').text().trim().toLowerCase();
          valB = $(b).find('.classification').text().trim().toLowerCase();
        } else if (column === 'date') {
          valA = $(a).find('time').attr('datetime') || '';
          valB = $(b).find('time').attr('datetime') || '';
        } else if (column === 'status') {
          valA = (a.dataset.status || '').toLowerCase();
          valB = (b.dataset.status || '').toLowerCase();
        }
        let comp = 0;
        if (column === 'date') {
          comp = new Date(valA).getTime() - new Date(valB).getTime();
        } else {
          comp = valA.localeCompare(valB);
        }
        return newSort === 'ascending' ? comp : -comp;
      });
      $.each(rows, function (_, row) {
        items.append(row);
      });
      restripeDocuments();
    });

    $('table[data-filter-table] th.sort-header').on('click keydown', function (event) {
      if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
      if (event.type === 'keydown') event.preventDefault();
      const colIndex = parseInt(this.dataset.sortTable, 10);
      const currentSort = this.getAttribute('aria-sort');
      const newSort = currentSort === 'ascending' ? 'descending' : 'ascending';
      $(this).closest('tr').find('th.sort-header').attr('aria-sort', 'none');
      this.setAttribute('aria-sort', newSort);

      const table = $(this).closest('table');
      const tbody = table.find('tbody');
      const rows = tbody.find('tr:not(.is-empty)').get();
      rows.sort((a, b) => {
        const valA = $(a).children('td').eq(colIndex).text().trim().toLowerCase();
        const valB = $(b).children('td').eq(colIndex).text().trim().toLowerCase();
        const comp = valA.localeCompare(valB);
        return newSort === 'ascending' ? comp : -comp;
      });
      $.each(rows, function (_, row) {
        tbody.append(row);
      });
    });
    const multiUpload = $('#upload-form').attr('data-multi-upload') === 'true';
    const uploads = [];
    let uploading = false;
    function renderUploads() {
      const list = $('#upload-file-list').empty();
      const labels = {
        pending: 'Queued',
        uploading: 'Uploading…',
        saved: 'Uploaded · scan queued',
        failed: 'Failed',
        invalid: 'Not uploaded',
      };
      uploads.forEach((item) => {
        const row = $('<li>')
          .append(
            $('<span>').text(item.file.name),
            $('<small>')
              .text(item.error || labels[item.status])
              .toggleClass('text-danger', ['failed', 'invalid'].includes(item.status))
          )
          .appendTo(list);
        if (!item.duplicates?.length || item.status === 'saved') return;
        const existing = item.duplicates[0];
        row.append(
          $('<a class="ms-2">')
            .attr('href', '/documents/' + encodeURIComponent(existing.document_id))
            .attr('target', '_blank')
            .attr('rel', 'noopener')
            .text('Open existing')
        );
        $('<button type="button" class="btn btn-link btn-sm p-0 ms-2">Upload anyway</button>')
          .on('click', () => {
            item.confirm = true;
            item.status = 'pending';
            item.error = '';
            renderUploads();
            startUploads();
          })
          .appendTo(row);
      });
      const saved = uploads.filter((item) => item.status === 'saved').length;
      const failed = uploads.filter((item) => ['failed', 'invalid'].includes(item.status)).length;
      $('#upload-summary').text(
        saved +
          ' of ' +
          uploads.length +
          ' uploaded' +
          (failed ? ' · ' + failed + ' failed' : '') +
          (uploading ? ' · Keep this page open.' : '.')
      );
      $('#retry-uploads').prop(
        'hidden',
        uploading || !uploads.some((item) => item.status === 'failed' && !item.duplicates?.length)
      );
    }
    async function startUploads() {
      if (uploading) return;
      uploading = true;
      renderUploads();
      const form = $('#upload-form')[0];
      try {
        await window.DmsUploadQueue(
          uploads,
          async (item) => {
            const data = new FormData(form);
            data.set('file', item.file);
            data.set('title', item.file.name.slice(0, 200));
            data.set('submission_key', item.key);
            data.set('classification', item.classification);
            if (item.confirm) data.set('confirm_duplicate', '1');
            if (item.force_new_version) data.set('force_new_version', 'true');
            try {
              await $.ajax({
                url: form.action,
                method: 'POST',
                data,
                processData: false,
                contentType: false,
                headers: { Accept: 'application/json' },
              });
            } catch (xhr) {
              if (xhr.status === 409) {
                if (xhr.responseJSON?.code === 'duplicate_file') {
                  item.duplicates = xhr.responseJSON.duplicates || [];
                }
                const duplicateData = (xhr.responseJSON?.duplicates && xhr.responseJSON.duplicates[0])
                  || xhr.responseJSON?.existing_document
                  || { title: 'Existing document', owner: 'Document owner' };
                showDuplicateWarningModal(
                  duplicateData,
                  () => {
                    item.force_new_version = true;
                    item.confirm = true;
                    item.status = 'pending';
                    item.error = '';
                    renderUploads();
                    startUploads();
                  }
                );
              }
              throw new Error(
                xhr.responseJSON?.errors
                  ? Object.values(xhr.responseJSON.errors).flat().join(' ')
                  : xhr.responseJSON?.message || 'Upload failed. Retry this file.'
              );
            }
          },
          renderUploads
        );
      } finally {
        uploading = false;
        renderUploads();
      }
    }
    function addFiles(files) {
      if (!files.length) return;
      if (uploads.length + files.length > 50) {
        toast('Choose up to 50 files per upload session.');
        return;
      }
      Array.from(files).forEach((file) => {
        const error = fileError(file);
        uploads.push({
          file,
          key: crypto.randomUUID(),
          classification: $('#upload-class').val(),
          status: error ? 'invalid' : 'pending',
          error,
        });
      });
      renderUploads();
      startUploads();
    }
    $('#retry-uploads').on('click', () => {
      uploads
        .filter((item) => item.status === 'failed' && !item.duplicates?.length)
        .forEach((item) => {
          item.status = 'pending';
          item.error = '';
        });
      startUploads();
    });
    $('#upload-modal')
      .on('hide.bs.modal', (event) => {
        if (multiUpload && uploading) {
          event.preventDefault();
          toast('Uploads are still running. Keep this page open.');
        }
      })
      .on('hidden.bs.modal', () => {
        if (multiUpload && uploads.some((item) => item.status === 'saved')) {
          if (!preview) location.reload();
        }
      });
    function selectedFile() {
      const input = $('#upload-file')[0];
      if (!input) return;
      if (multiUpload) {
        addFiles(input.files);
        input.value = '';
        return;
      }
      const file = input.files[0];
      input.setCustomValidity(fileError(file));
      $('#selected-file').text(
        file ? file.name + ' · ' + (file.size / 1024).toFixed(1) + ' KB' : ''
      );
    }
    $('#upload-file').on('change', selectedFile);
    $('#upload-zone')
      .on('dragover', (event) => {
        event.preventDefault();
        $('#upload-zone').addClass('drag-over');
      })
      .on('dragleave', () => $('#upload-zone').removeClass('drag-over'))
      .on('drop', (event) => {
        event.preventDefault();
        $('#upload-zone').removeClass('drag-over');
        const files = event.originalEvent.dataTransfer.files;
        if (multiUpload) {
          addFiles(files);
          return;
        }
        if (files.length !== 1) {
          toast('Choose one file for this document version.');
          return;
        }
        $('#upload-file')[0].files = files;
        selectedFile();
      });
    $('[data-copy-token]').on('click', async function () {
      const input = $(this).siblings('input')[0];
      try {
        await navigator.clipboard.writeText(input.value);
        toast('Token copied.');
      } catch {
        input.select();
        toast('Select and copy the token.');
      }
    });
    $('#folder-upload-form').on('submit', function (event) {
      event.preventDefault();
      const files = $('#folder-files')[0].files;
      const feedback = $('#folder-upload-feedback');
      if (!files.length || files.length > 50) {
        feedback.text('Choose 1 to 50 files.');
        return;
      }
      const invalid = Array.from(files).map(fileError).find(Boolean);
      if (invalid) {
        feedback.text(invalid);
        return;
      }
      const data = new FormData(this);
      Array.from(files).forEach((file) => data.append('paths[]', file.webkitRelativePath));
      const button = $(this).find('[type="submit"]').prop('disabled', true);
      feedback.text('Uploading folder. Keep this page open…');
      $.ajax({
        url: this.action,
        method: 'POST',
        data,
        processData: false,
        contentType: false,
        headers: { Accept: 'application/json' },
      })
        .done((result) => {
          const errors = result.results.filter((row) => row.status >= 400);
          if (errors.length)
            feedback.text(
              errors.map((row) => row.path + ': ' + row.message).join(' ') +
                ' Retry this form to resume.'
            );
          else location.reload();
        })
        .fail((xhr) =>
          feedback.text(
            xhr.responseJSON?.errors
              ? Object.values(xhr.responseJSON.errors).flat().join(' ')
              : xhr.responseJSON?.message || 'Upload failed. Retry this form to resume.'
          )
        )
        .always(() => button.prop('disabled', false));
    });
    // Duplicate warning modal: intercept 409 Conflict, show title & owner, allow upload as new version or cancel
    function showDuplicateWarningModal(existingDoc, onUploadNewVersion, onCancel) {
      let modalEl = document.getElementById('duplicate-warning-modal');
      if (!modalEl) {
        const modalHtml = `
          <div class="modal fade" id="duplicate-warning-modal" tabindex="-1" aria-labelledby="duplicate-warning-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header">
                  <h2 class="modal-title" id="duplicate-warning-title">Duplicate file detected</h2>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <p class="modal-subtitle">An identical file already exists in a document you can access.</p>
                  <div class="duplicate-info-card p-3 my-3 rounded" style="background: var(--canvas, #f8f9fa); border: 1px solid var(--line, #e2e8f0);">
                    <p class="mb-2"><strong>Document Title:</strong> <span id="duplicate-modal-doc-title">—</span></p>
                    <p class="mb-0"><strong>Document Owner:</strong> <span id="duplicate-modal-doc-owner">—</span></p>
                  </div>
                  <p class="text-muted small mb-0">You can upload this file as a new version of the existing document, or cancel the upload.</p>
                </div>
                <div class="modal-footer">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="duplicate-modal-cancel">Cancel</button>
                  <button type="button" class="btn btn-primary" id="duplicate-modal-upload-new-version">Upload as New Version</button>
                </div>
              </div>
            </div>
          </div>`;
        $('body').append(modalHtml);
        modalEl = document.getElementById('duplicate-warning-modal');
      }

      const title = existingDoc?.title || 'Untitled document';
      const owner = existingDoc?.owner || existingDoc?.owner_name || existingDoc?.owner_email || (existingDoc?.owner_id ? ('User #' + existingDoc.owner_id) : 'Document owner');

      $('#duplicate-modal-doc-title').text(title);
      $('#duplicate-modal-doc-owner').text(owner);

      const bsModal = typeof bootstrap !== 'undefined' && bootstrap.Modal
        ? bootstrap.Modal.getOrCreateInstance(modalEl)
        : null;

      $('#duplicate-modal-upload-new-version').off('click').on('click', function () {
        if (bsModal) bsModal.hide();
        if (typeof onUploadNewVersion === 'function') {
          onUploadNewVersion();
        }
      });

      $('#duplicate-modal-cancel').off('click').on('click', function () {
        if (bsModal) bsModal.hide();
        if (typeof onCancel === 'function') {
          onCancel();
        }
      });

      if (bsModal) {
        bsModal.show();
      }
    }
    window.DmsDuplicateModal = { show: showDuplicateWarningModal };

    function updateFolderShareCheckbox(folderObj) {
      const container = $('#folder-sharing-container, #folder-sharing-checkbox-group, #folder-share-apply-all-group');
      const checkbox = container.find('input[type="checkbox"]');
      if (!folderObj) {
        container.hide();
        checkbox.prop('checked', false).prop('disabled', true);
        return;
      }
      const isRestricted = Boolean(
        folderObj.has_confidential_or_restricted ||
        folderObj.contains_confidential_or_restricted ||
        folderObj.has_confidential ||
        folderObj.has_restricted ||
        ['Confidential', 'Restricted'].includes(folderObj.classification)
      );
      if (isRestricted) {
        container.hide();
        checkbox.prop('disabled', true).prop('checked', false);
      } else {
        container.show();
        checkbox.prop('disabled', false);
      }
    }
    window.DmsFolderSharing = { updateCheckbox: updateFolderShareCheckbox };

    // Duplicate uploads: list only documents this user may already open.
    function showUploadDuplicates(matches) {
      const list = $('#upload-duplicate-list').empty();
      matches.forEach((row) => {
        list.append(
          $('<li>').append(
            $('<a>')
              .attr('href', '/documents/' + encodeURIComponent(row.document_id))
              .attr('target', '_blank')
              .attr('rel', 'noopener')
              .text(row.title),
            $('<small>').text(
              ' · version ' +
                row.version_number +
                (row.can_add_version ? ' · you can add a new version there' : '')
            )
          )
        );
      });
      $('#upload-confirm-duplicate').val('0');
      $('#upload-duplicates').prop('hidden', false);
    }


    $('#upload-duplicate-confirm').on('click', () => {
      $('#upload-confirm-duplicate').val('1');
      $('#upload-form').trigger('submit');
    });
    $('form').on('submit', function (event) {
      if (this.id === 'upload-form' && multiUpload) {
        event.preventDefault();
        return;
      }
      if (event.isDefaultPrevented() || !this.checkValidity()) return;
      if (this.dataset.confirm && !window.confirm(this.dataset.confirm)) {
        event.preventDefault();
        return;
      }
      if (this.dataset.ajaxMove === 'true') {
        event.preventDefault();
        const send = (confirmed) => {
          const data = new FormData(this);
          if (confirmed) data.set('confirm_move', '1');
          $.ajax({
            url: this.action,
            method: 'POST',
            data,
            processData: false,
            contentType: false,
            headers: { Accept: 'application/json' },
          })
            .done((result) => {
              if (result.status === 'pending') location.assign('/approvals');
              else location.reload();
            })
            .fail((xhr) => {
              if (xhr.status === 409 && xhr.responseJSON?.code === 'access_change') {
                if (window.confirm(xhr.responseJSON.message + '\n\nMove anyway?')) send(true);
                return;
              }
              toast(xhr.responseJSON?.message || 'Could not save document details.');
            });
        };
        send(false);
        return;
      }
      if (this.id !== 'upload-form') return;
      event.preventDefault();
      const form = $(this);
      const progress = form.find('.upload-progress');
      progress.prop('hidden', false);
      form.find('button[type="submit"]').prop('disabled', true);
      $.ajax({
        url: this.action,
        method: 'POST',
        data: new FormData(this),
        processData: false,
        contentType: false,
        headers: { Accept: 'application/json' },
        xhr: function () {
          const xhr = $.ajaxSettings.xhr();
          xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
              const percent = Math.round((e.loaded / e.total) * 100);
              progress.find('[role="progressbar"]').attr('aria-valuenow', percent);
              progress.find('.progress-bar').css('width', percent + '%');
              progress
                .find('small')
                .text(
                  percent === 100
                    ? 'Saving and queuing security scan…'
                    : 'Uploading… ' + percent + '%'
                );
            }
          });
          return xhr;
        },
      })
        .done((result) => location.assign('/documents/' + encodeURIComponent(result.document_id)))
        .fail((xhr) => {
          if (xhr.status === 409) {
            progress.prop('hidden', true);
            form.find('button[type="submit"]').prop('disabled', false);

            const duplicateData = (xhr.responseJSON?.duplicates && xhr.responseJSON.duplicates[0])
              || xhr.responseJSON?.existing_document
              || xhr.responseJSON?.document
              || {
                title: xhr.responseJSON?.title || 'Existing document',
                owner: xhr.responseJSON?.owner || xhr.responseJSON?.owner_name || 'Document owner',
                document_id: xhr.responseJSON?.document_id
              };

            showDuplicateWarningModal(
              duplicateData,
              function () {
                // "Upload as New Version" (sends force_new_version=true)
                const formData = new FormData(form[0]);
                formData.set('force_new_version', 'true');
                if (duplicateData.document_id) {
                  formData.set('document_id', duplicateData.document_id);
                }
                progress.prop('hidden', false);
                form.find('button[type="submit"]').prop('disabled', true);

                $.ajax({
                  url: form[0].action,
                  method: 'POST',
                  data: formData,
                  processData: false,
                  contentType: false,
                  headers: { Accept: 'application/json' },
                  xhr: function () {
                    const retryXhr = $.ajaxSettings.xhr();
                    retryXhr.upload.addEventListener('progress', (e) => {
                      if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        progress.find('[role="progressbar"]').attr('aria-valuenow', percent);
                        progress.find('.progress-bar').css('width', percent + '%');
                      }
                    });
                    return retryXhr;
                  },
                })
                  .done((result) => location.assign('/documents/' + encodeURIComponent(result.document_id)))
                  .fail((retryErr) => {
                    const msg = retryErr.responseJSON?.message || 'Upload failed.';
                    progress.find('small').text(msg);
                    form.find('button[type="submit"]').prop('disabled', false);
                  });
              },
              function () {
                progress.prop('hidden', true);
                form.find('button[type="submit"]').prop('disabled', false);
              }
            );

            if (xhr.responseJSON?.code === 'duplicate_file') {
              showUploadDuplicates(xhr.responseJSON.duplicates || []);
            }
            return;
          }
          const errors = xhr.responseJSON?.errors;
          const message = errors
            ? Object.values(errors).flat().join(' ')
            : xhr.responseJSON?.message ||
              'Upload failed. Retry using this form; your submission key is preserved.';
          progress.find('small').text(message);
          form.find('button[type="submit"]').prop('disabled', false);
        });
      return;
    });
    filter();
  });
})(jQuery);
