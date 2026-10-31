/* global jQuery */
/**
 * DMS Table Sorting, Filtering, and Debounce Module
 */
export function initTables() {
  if (typeof jQuery === 'undefined') return;
  const $ = jQuery;

  let filterTimer;
  const filter = () => {
    const query = $('input[type="search"]').val()?.toLowerCase().trim() || '';
    $('table[data-filter-table] tbody tr:not(.is-empty)').each(function () {
      const row = $(this);
      const text = row.text().toLowerCase();
      row.toggle(text.includes(query));
    });
  };

  const filterSoon = () => {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(filter, 200);
  };

  $('input[type="search"]').on('input', filterSoon);

  function sortDirection(event, element, allHeaders) {
    if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return null;
    if (event.type === 'keydown') event.preventDefault();

    const current = element.getAttribute('aria-sort');
    const next = current === 'ascending' ? 'descending' : 'ascending';
    allHeaders.attr('aria-sort', 'none');
    element.setAttribute('aria-sort', next);
    return next;
  }

  $('table[data-filter-table] th.sort-header').on('click keydown', function (event) {
    const newSort = sortDirection(event, this, $(this).closest('tr').find('th.sort-header'));
    if (!newSort) return;
    const colIndex = parseInt(this.dataset.sortTable, 10);
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
}
