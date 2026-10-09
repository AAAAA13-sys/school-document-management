$(function () {
    let position = 0, locked = false;
    function sync() {
        const open = $('.ui-dialog-content').toArray().some(element => $(element).dialog('isOpen'));
        if (open && !locked) {
            position = window.scrollY;
            document.body.style.top = -position + 'px';
            document.body.classList.add('dialog-scroll-locked');
            locked = true;
        } else if (!open && locked) {
            document.body.classList.remove('dialog-scroll-locked');
            document.body.style.top = '';
            window.scrollTo(0, position);
            locked = false;
        }
    }
    $(document).on('dialogopen dialogclose', '.ui-dialog-content', sync);
});
