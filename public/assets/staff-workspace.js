$(function () {
    $('#password-toggle').on('click', function () {
        const visible = $('#password').attr('type') === 'password';
        $('#password').attr('type', visible ? 'text' : 'password');
        $(this).text(visible ? 'Hide' : 'Show').attr('aria-pressed', String(visible));
    });
    $('#workspace-search').on('submit', function (event) {
        event.preventDefault();
        $('.nav-item[data-view="files"]').trigger('click');
        $('#drive-search').val($(this).find('input').val());
        $('#drive-filter').trigger('submit');
    });
    function updateFileLabel() {
        const file = $('#file')[0]?.files[0];
        $('#selected-file-label').text(file ? 'Selected: '+file.name+' · '+(file.size/1024).toFixed(1)+' KB' : 'No file selected');
    }
    function updateUploadActions() {
        // Metadata and file selection share one scrolling form, so the submit button is
        // always available and required fields are never hidden on a second tab.
    }
    $('#file').on('change',function(){updateFileLabel();const body=$('#upload-dialog').closest('.ui-dialog-content');if(body&&$('#title').value)body.scrollTop=$('#upload-record').offset().top;});
    $('#upload-dialog').on('dialogopen',function(){updateFileLabel();updateUploadActions();});
    $('#upload-dropzone').on('dragover',function(event){event.preventDefault();$(this).addClass('is-dragging');})
        .on('dragleave',function(){$(this).removeClass('is-dragging');})
        .on('drop',function(event){
            event.preventDefault();$(this).removeClass('is-dragging');
            const files=event.originalEvent.dataTransfer.files;
            if(files.length!==1){$('#upload-error').text('Choose one file for this document. Use Folder upload for a batch.').prop('hidden',false);return;}
            $('#file')[0].files=files;$('#file').trigger('change');$('#upload-error').prop('hidden',true);
        });
    function closeNavigation() {
        $('.sidebar').removeClass('open');
        $('#sidebar-dismiss').prop('hidden', true);
        $('#menu-toggle').attr('aria-expanded', 'false');
    }
    $('#sidebar-dismiss').on('click', closeNavigation);
    $(document).on('keydown', function (event) {
        if (event.key === 'Escape' && $('.sidebar').hasClass('open')) {
            closeNavigation();
            $('#menu-toggle').trigger('focus');
        }
    });
    $(window).on('resize', function () { if (window.innerWidth > 900) closeNavigation(); });
});
