/* global jQuery, bootstrap */
/**
 * DMS Sidebar Navigation & Device Detection Module
 */
export function initSidebar() {
  if (typeof jQuery === 'undefined') return;
  const $ = jQuery;
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
          sidebarTooltips.push(
            new bootstrap.Tooltip(this, { container: 'body', trigger: 'hover focus' })
          );
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
      title: tooltipTitle,
    });
    sidebarToggle.find('.sidebar-toggle-icon').text(collapsed ? '»' : '«');

    const brand = $('.brand');
    if (collapsed) {
      brand.attr({
        title: 'Expand navigation (Ctrl+B)',
        'aria-label': 'Expand navigation sidebar',
        role: 'button',
      });
    } else {
      brand
        .attr({ title: 'PLP Documents · Document Management', 'aria-label': 'PLP Documents home' })
        .removeAttr('role');
    }

    if (userInitiated) {
      try {
        localStorage.setItem('dms_sidebar_collapsed', collapsed ? 'true' : 'false');
        document.cookie =
          'dms_sidebar_collapsed=' +
          (collapsed ? 'true' : 'false') +
          ';path=/;max-age=31536000;SameSite=Lax';
      } catch (_e) {}
    }
    updateSidebarTooltips(collapsed);
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

  $('.brand').on('click', function (event) {
    const isCollapsed =
      $('html').hasClass('sidebar-collapsed') || $('body').hasClass('sidebar-collapsed');
    if (isCollapsed) {
      event.preventDefault();
      setSidebarCollapsed(false, true);
    }
  });

  sidebarToggle.on('click', () => {
    const isCurrentlyCollapsed =
      $('html').hasClass('sidebar-collapsed') || $('body').hasClass('sidebar-collapsed');
    setSidebarCollapsed(!isCurrentlyCollapsed, true);
  });

  $(document).on('keydown', (event) => {
    if (
      (event.ctrlKey || event.metaKey || event.altKey) &&
      (event.key === 'b' || event.key === 'B')
    ) {
      if (!$(event.target).is('input, textarea, select')) {
        event.preventDefault();
        const isCurrentlyCollapsed =
          $('html').hasClass('sidebar-collapsed') || $('body').hasClass('sidebar-collapsed');
        setSidebarCollapsed(!isCurrentlyCollapsed, true);
      }
    }
  });
}
