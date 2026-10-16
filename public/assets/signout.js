$(function () {
    const dialog = $('#signout-dialog');
    if (!dialog.length) return;
    let trigger = null;
    dialog.prop('hidden', false).dialog({ autoOpen: false, modal: true, width: Math.min(440, window.innerWidth - 32), resizable: false, draggable: false });
    $(document).on('click', '.signout-trigger', function (event) {
        event.preventDefault();
        trigger = this;
        dialog.dialog('open');
    });
    $('#signout-cancel').on('click', () => dialog.dialog('close'));
    dialog.on('dialogclose', () => {
        if (trigger) {
            trigger.focus();
            trigger = null;
        }
    });
    $(window).on('resize', () => dialog.dialog('option', 'position', { my: 'center', at: 'center', of: window }));
});