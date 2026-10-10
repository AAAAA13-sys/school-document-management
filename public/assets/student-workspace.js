$(function () {
    if ($('.app-shell').attr('data-student') !== 'true') return;
    $('#student-upload').on('click', () => $('#upload-open').trigger('click'));
    $('#student-new-folder').on('click', () => $('#drive-new-folder').trigger('click'));
    const search = $('#student-search');
    search.on('submit', function (e) {
        e.preventDefault();
        const value = $(this).find('input').val();
        $('.nav-item[data-view="files"]').trigger('click');
        $('#drive-search').val(value); $('#drive-filter').trigger('submit');
    });
});
