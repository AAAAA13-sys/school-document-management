$(function () {

    'use strict';

    let sharedTarget = null, sharedUploadKey = crypto.randomUUID();

    let location = 'files', home = false, folder = null, folderSource = null, page = 1, files = [], folders = [], action = null, target = null, pendingFolder = null, request = null;

    const escape = value => $('<span>').text(value == null ? '' : value).html();

    const fail = xhr => $('#notice').text(xhr.responseJSON?.message || 'This action failed. Refresh and try again.').attr('class','alert alert-danger').prop('hidden',false);

    const personal = ['student','teacher','employee'].includes($('.app-shell').data('role'));

    const date = value => new Date(value.replace(' ','T')+'Z').toLocaleDateString();
    function refreshFolderTree() {
        $.getJSON('/workspace/folders').done(all => {
            $('#folders-toggle').prop('hidden', !all.length);
            const container = $('#sidebar-folders').empty(), seen = new Set(), ids = new Set(all.map(f => f.id));
            function append(parent, depth) {
                all.filter(f => parent === null ? !f.parent_id || !ids.has(f.parent_id) : f.parent_id === parent).forEach(f => {
                    if (seen.has(f.id)) return;
                    seen.add(f.id);
                    $('<button type="button" class="sidebar-folder">').attr('data-id', f.id).css('padding-left', 16 + Math.min(depth, 12) * 12).text('▰ '+f.name).appendTo(container);
                    append(f.id, depth + 1);
                });
            }
            append(null, 0);
            if (!all.length) container.prop('hidden', true);
        }).fail(fail);
    }
    $('#folders-toggle').on('click', function () {
        const expanded = $(this).attr('aria-expanded') !== 'true';
        $(this).attr('aria-expanded', expanded).attr('aria-label', expanded ? 'Collapse My files folders' : 'Expand My files folders');
        $('#sidebar-folders').prop('hidden', !expanded);
    });
    $(document).on('click', '.sidebar-folder', function () {
        const id = $(this).attr('data-id');
        $('.nav-item[data-view="files"]').trigger('click');
        folder = id; page = 1; load();
    });
    function loadComments() {
        $.getJSON('/workspace/shared/'+sharedTarget.grant_id+'/comments').done(items => {
            const area = $('#shared-comments').empty();
            if (!items.length) area.text('No comments yet.');
            items.forEach(c => $('<article class="shared-comment">').append($('<strong>').text(c.name), $('<p>').text(c.body), $('<small>').text(date(c.created_at))).appendTo(area));
        }).fail(xhr => $('#shared-error').text(xhr.responseJSON?.message || 'Comments unavailable.'));
    }
    function openShared(d) {
        sharedTarget = d; sharedUploadKey = crypto.randomUUID();
        $('#shared-title').text(d.title); $('#shared-access').text('Shared by '+d.shared_by+' · '+d.permission);
        $('#shared-download').attr('href', '/workspace/shared/'+d.grant_id).prop('hidden', d.current.scan !== 'Clean');
        $('#shared-comment-form').prop('hidden', !['commenter','editor'].includes(d.permission));
        $('#shared-rename-form,#shared-version-form').prop('hidden', d.permission !== 'editor');
        $('#shared-name').val(d.title); $('#shared-comment,#shared-file').val('');$('#shared-error').empty();
        loadComments(); $('#shared-detail-dialog').dialog('open');
    }
    function sharedFailure(xhr) { $('#shared-error').text(xhr.responseJSON?.message || 'Action failed.'); }
    $('#shared-comment-form').on('submit', function (e) {
        e.preventDefault(); const button = $(this).find('button').prop('disabled', true);
        $.post('/workspace/shared/'+sharedTarget.grant_id+'/comments', {body:$('#shared-comment').val()})
            .done(() => {$('#shared-comment').val('');loadComments();}).fail(sharedFailure).always(() => button.prop('disabled',false));
    });
    $('#shared-rename-form').on('submit', function (e) {
        e.preventDefault(); const button = $(this).find('button').prop('disabled',true);
        $.ajax({url:'/workspace/shared/'+sharedTarget.grant_id+'/document', method:'PATCH', data:{title:$('#shared-name').val(),revision:sharedTarget.revision}})
            .done(() => {sharedTarget.revision++; sharedTarget.title=$('#shared-name').val(); $('#shared-title').text(sharedTarget.title);load();$('#shared-error').empty();})
            .fail(sharedFailure).always(() => button.prop('disabled',false));
    });
    $('#shared-file').on('change', () => {sharedUploadKey=crypto.randomUUID();});
    $('#shared-version-form').on('submit', function (e) {
        e.preventDefault(); const button=$(this).find('button').prop('disabled',true), data=new FormData();
        data.set('file',$('#shared-file')[0].files[0]);data.set('revision',sharedTarget.revision);
        $.ajax({url:'/workspace/shared/'+sharedTarget.grant_id+'/versions',method:'POST',data,processData:false,contentType:false,headers:{'Idempotency-Key':sharedUploadKey}})
            .done(() => {sharedTarget.revision++; sharedUploadKey=crypto.randomUUID();$('#shared-file').val('');$('#shared-error').text('Version saved. The original shared version is preserved.');load();})
            .fail(sharedFailure).always(() => button.prop('disabled',false));
    });
    $('#shared-detail-dialog').prop('hidden',false).dialog({autoOpen:false,modal:true,width:Math.min(720,window.innerWidth-32),resizable:false,draggable:false});
    function render() {

        const actions = d => location==='shared' ? '<button class="btn btn-quiet file-details" data-id="'+escape(d.grant_id||d.id)+'">Open shared file</button>' : location==='trash' ? '<button class="btn btn-quiet file-action" data-action="restore" data-id="'+escape(d.id)+'">Restore</button>' : ['star','rename','move','share','trash'].map(a=>'<button class="btn btn-quiet file-action" data-action="'+a+'" data-id="'+escape(d.id)+'">'+({star:d.starred?'Unstar':'Star',rename:'Rename',move:'Move',share:'Share',trash:'Move to Trash'}[a])+'</button>').join('')+(d.current.scan==='Clean'?'<button class="btn btn-quiet file-preview" data-version="'+escape(d.current.id)+'">Preview</button><a class="btn btn-quiet" href="/workspace/files/'+escape(d.current.id)+'">Download</a>':'');

        const folderCards=folders.map(f=>'<article class="drive-card folder-card"><button class="drive-folder drive-name" data-id="'+escape(f.id)+'"><span class="drive-symbol">▰</span><strong>'+escape(f.name)+'</strong></button><span class="drive-owner">'+escape(f.source)+'</span><span class="drive-date">'+date(f.updated_at)+'</span><span class="drive-size">—</span><details class="drive-menu"><summary aria-label="Folder actions for '+escape(f.name)+'">⋮</summary><div><button class="btn btn-quiet folder-rename" data-id="'+escape(f.id)+'">Rename folder</button></div></details></article>').join('');

        const fileCards=files.map(d=>'<article class="drive-card file-card"><button class="drive-name file-details" data-id="'+escape(d.grant_id||d.id)+'"><span class="drive-symbol">▤</span><strong>'+escape(d.title)+'</strong></button><div class="drive-thumbnail" aria-hidden="true"><span>'+escape(d.current.filename.split('.').pop().toUpperCase())+'</span></div><span class="drive-owner">'+escape(location==='shared'?d.shared_by+' · '+d.permission:d.subject)+'</span><span class="drive-date">'+date(d.updated_at)+'</span><span class="drive-size">'+(d.current.size/1024).toFixed(1)+' KB</span><details class="drive-menu"><summary aria-label="File actions for '+escape(d.title)+'">⋮</summary><div>'+actions(d)+'</div></details></article>').join('');

        const headings='<div class="drive-columns"><span>Name</span><span>Record owner / system</span><span>Date modified</span><span>File size</span><span></span></div>';

        $('#drive-items').html(home?'<h2 class="suggestion-title">Your folders</h2><div class="suggested-folders">'+(folderCards||'<p>No folders yet. Create one to organize your records.</p>')+'</div><h2 class="suggestion-title">Recent documents <small>Last updated</small></h2>'+headings+fileCards:headings+folderCards+fileCards);

        if(!files.length&&!folders.length)$('#drive-items').html('<p class="empty-state">No files here.</p>');

    }

    function load() {

        if(request) request.abort();

        $('#drive-items').text('Loading files…');

        request=$.getJSON(location==='shared'?'/workspace/shared-with-me':'/workspace/drive',{location,folder,page,search:$('#drive-search').val(),sort:$('#drive-sort').val()}).done(result=>{

            files=result.files.data; folders=result.folders; $(document).trigger('item:clear');

            folderSource=result.breadcrumbs.at(-1)?.source||null;

            $('#drive-crumbs').html(location==='shared'?'<strong>Shared with me</strong>':'<button class="btn btn-quiet drive-folder" data-id="">My files</button>'+result.breadcrumbs.map(f=>'<span> / </span><button class="btn btn-quiet drive-folder" data-id="'+escape(f.id)+'">'+escape(f.name)+'</button>').join(''));

            render();

            if(!files.length&&!folders.length) $('#drive-items').html('<div class="drive-empty"><span class="empty-file-icon" aria-hidden="true">▤</span><h2>'+({trash:'Trash is empty',starred:'Keep your favorites close',recent:'Your recent documents',home:'Start your document space',files:'Make room for your files',shared:'Shared with you'}[location]||'No matching files')+'</h2><p>'+($('#drive-search').val()?'Try another name or clear your search.':location==='trash'?'Files you move to Trash will appear here.':location==='shared'?'Documents shared with your account will appear here.':location==='starred'?'Star a file from its menu to find it here.':'Upload your first document or create a folder to get organized.')+'</p></div>');

            $('#drive-count').text(result.files.total+' files · '+folders.length+' folders'); $('#drive-prev').prop('disabled',page<=1); $('#drive-next').prop('disabled',page>=result.files.last_page);

            refreshFolderTree();



        }).fail(xhr=>{if(xhr.statusText!=='abort'){ $('#drive-items').text('Files unavailable. Try searching again.'); fail(xhr); }});

    }

    function send(id,kind,extra={}) { const d=files.find(d=>d.id===id); return $.post('/workspace/drive/'+id+'/action',{action:kind,revision:d.revision,...extra}).done(load).fail(fail); }

    function dialog(kind,d=null) {

        action=kind; target=d; $('#drive-action-error').empty(); $('#drive-action-fields').empty();

        $('#drive-dialog').dialog('option','title',{rename:'Rename file',folderRename:'Rename folder',create:'New folder',move:'Move file',share:'Share a version'}[kind]);

        if(['rename','folderRename','create'].includes(kind)) $('<label class="form-label">').text('Name').append($('<input class="form-control" name="name" required maxlength="200">').val(d?.title||d?.name||'')).appendTo('#drive-action-fields');

        if(kind==='create') { const select=$('<select name="source" class="form-select" aria-label="Folder source">'); $('#source option').each(function(){if(!folderSource||$(this).text()===folderSource)select.append($('<option>').text($(this).text()));}); $('#drive-action-fields').append(select); }

        if(kind==='move') $.getJSON('/workspace/folders').done(all=>{ const select=$('<select class="form-select" name="folder_id" aria-label="Destination folder">').append('<option value="">My files</option>'); all.filter(f=>f.source===d.source).forEach(f=>select.append($('<option>').val(f.id).text(f.name+' · '+f.id.slice(0,8)))); $('#drive-action-fields').append(select); }).fail(fail);

        if(kind==='share') {

            $('#drive-action-fields').append('<p>Share with an active account in your school and campus. Choose their access. Links expire and preserve this version.</p><label class="form-label">Recipient email<input type="email" name="email" class="form-control" required></label><label class="form-label">Access<select name="permission" class="form-select"><option value="viewer">Viewer  -  read and download</option><option value="commenter">Commenter  -  also add comments</option><option value="editor">Editor  -  also rename and upload versions</option></select></label><label class="form-label">Expiry date<input type="date" name="expires_at" class="form-control" required></label><div id="share-list"></div>');

            $.getJSON('/workspace/drive/'+d.id+'/shares').done(grants=>grants.forEach(g=>$('#share-list').append($('<p>').text(g.email+' · '+g.expires_at+' UTC ').append(g.revoked_at?'Revoked':$('<button type="button" class="btn btn-quiet">').text('Revoke').on('click',()=>$.ajax({url:'/workspace/shares/'+g.id,method:'DELETE'}).done(()=>dialog('share',d)).fail(fail)))))).fail(fail);

        }

        $('#drive-dialog').dialog('open');

    }

    $(document).on('drive:view',(event,next)=>{if(['overview','files','recent','starred','trash','shared'].includes(next)){home=next==='overview';location=home?'home':next;folder=null;page=1;$('#drive-panel').toggleClass('drive-home',home);$('#drive-items').toggleClass('drive-list',home);$('#drive-layout').text(home?'Grid view':'List view').attr('aria-pressed',home);load();}});

    $('#drive-filter').on('submit',event=>{event.preventDefault();page=1;load();}); $('#drive-sort').on('change',()=>{page=1;load();});

    $('#drive-layout').on('click',function(){const list=$('#drive-items').toggleClass('drive-list').hasClass('drive-list');$(this).text(list?'Grid view':'List view').attr('aria-pressed',list);});

    $('#drive-prev').on('click',()=>{page--;load();}); $('#drive-next').on('click',()=>{page++;load();});

    $(document).on('click','.drive-folder',function(){if($(this).closest('.folder-card').length){inspectFolder($(this).data('id'));return;}const id=$(this).data('id')||null;if(home){$('.nav-item[data-view="files"]').trigger('click');}folder=id;home=false;page=1;location='files';$('#drive-panel').removeClass('drive-home');$('#drive-search').val('');load();});

    $(document).on('dblclick','.file-details',function(){if(location==='shared'){openShared(files.find(f=>f.grant_id===$(this).data('id')));}else if(location!=='trash')$(document).trigger('drive:details',[$(this).data('id')]);});

    $(document).on('click','.folder-rename',function(){dialog('folderRename',folders.find(f=>f.id===$(this).data('id')));});

    $('#drive-new-folder').on('click',()=>{if(!$('#drive-panel').is(':visible')||!['home','files'].includes(location)){$('.nav-item[data-view="files"]').trigger('click');folder=null;folderSource=null;}dialog('create');});

    $('#upload-open').on('click',()=>{if($('#drive-panel').is(':visible')&&folderSource)$('#source').val(folderSource);});

    $(document).on('drive:upload',()=>{if(folderSource)$('#source').val(folderSource);});

    $(document).on('click','.file-action',function(){const kind=$(this).data('action'),d=files.find(d=>d.id===$(this).data('id'));if(['star','trash','restore'].includes(kind))send(d.id,kind);else dialog(kind,d);});

    $('#drive-action-form').on('submit',function(event){event.preventDefault();const p=Object.fromEntries(new FormData(this));let op;

        if(action==='create')op=$.post('/workspace/folders',{name:p.name,source:p.source,parent_id:folder});

        if(action==='folderRename')op=$.ajax({url:'/workspace/folders/'+target.id,method:'PATCH',data:{name:p.name,revision:target.revision}});

        if(action==='rename')op=send(target.id,'rename',{title:p.name});

        if(action==='move')op=send(target.id,'move',{folder_id:p.folder_id});

        if(action==='share')op=$.post('/workspace/drive/'+target.id+'/shares',{email:p.email,permission:p.permission,expires_at:p.expires_at+'T23:59:59Z'});

        $(this).find('button[type!=button]').prop('disabled',true);

        op.done(result=>{if(action==='share') { $('#drive-action-error').removeClass('text-danger').text('Share link: '+window.location.origin+result.url); } else {$('#drive-dialog').dialog('close');load();refreshFolderTree();}}).fail(xhr=>$('#drive-action-error').addClass('text-danger').text(xhr.responseJSON?.message||'Action failed.')).always(()=>$(this).find('button').prop('disabled',false));

    });

    $('#drive-drop').on('dragover',event=>{event.preventDefault();$(event.currentTarget).addClass('dropping');}).on('dragleave',()=>$('#drive-drop').removeClass('dropping')).on('drop',event=>{event.preventDefault();$('#drive-drop').removeClass('dropping');const dropped=Array.from(event.originalEvent.dataTransfer.files);if(dropped.length!==1){fail({responseJSON:{message:'Drop one file at a time so its required metadata can be recorded.'}});return;}pendingFolder=folder;$(document).trigger('drive:upload',[dropped]);});

    $(document).on('drive:uploaded',(event,result)=>{const destination=pendingFolder||folder;if(destination){$.getJSON('/workspace/documents/'+result.document_id).done(r=>$.post('/workspace/drive/'+result.document_id+'/action',{action:'move',revision:r.document.revision,folder_id:destination}).done(load).fail(fail));}else if($('#drive-panel').is(':visible'))load();pendingFolder=null;});

    $(document).on('click','.file-preview',function(){const version=$(this).data('version'),file=files.find(d=>d.current.id===version),url='/workspace/preview/'+version;$('#preview-text,#preview-image,#file-preview').prop('hidden',true);$('#preview-note').text('Loading preview…');$('#preview-dialog').dialog('open');

        if(file.current.media_type==='text/plain')$.ajax({url,dataType:'text'}).done(text=>{$('#preview-text').text(text).prop('hidden',false);$('#preview-note').empty();}).fail(xhr=>$('#preview-note').text(xhr.responseJSON?.message||'Preview unavailable.'));

        else if(file.current.media_type.startsWith('image/'))$('#preview-image').off('load error').on('load',()=>$('#preview-note').empty()).on('error',()=>$('#preview-note').text('Preview unavailable.')).attr('src',url).prop('hidden',false);

        else {$('#file-preview').attr('src',url).prop('hidden',false);$('#preview-note').text('PDF viewing depends on browser support. Use Download if the preview is unavailable.');}

    });

    $('#drive-dialog,#preview-dialog').prop('hidden',false).dialog({autoOpen:false,modal:true,width:Math.min(760,window.innerWidth-32),resizable:false,draggable:false});

    $('#preview-dialog').on('dialogclose',()=>{$('#file-preview').attr('src','about:blank');$('#preview-image').removeAttr('src');$('#preview-text').empty();});


    $(document).on('drive:refresh',()=>{load();refreshFolderTree();});
    function inspectFolder(id) { const d=folders.find(f=>f.id===id);if(d)$(document).trigger('item:select',[{kind:'folder',item:d}]); }
    $(document).on('click','.file-details',function(){const d=files.find(f=>(f.grant_id||f.id)===$(this).data('id'));if(d)$(document).trigger('item:select',[{kind:'file',item:d,shared:location==='shared',trashed:location==='trash'}]);});
    $(document).on('dblclick','.folder-card .drive-folder',function(){const id=$(this).data('id');$('.nav-item[data-view="files"]').trigger('click');folder=id;home=false;location='files';page=1;load();});
    $(document).on('item:action',(event,kind,data)=>{if(kind==='openFolder'){$('.nav-item[data-view="files"]').trigger('click');folder=data.id;page=1;load();}if(kind==='share')dialog('share',data);if(kind==='renameFolder')dialog('folderRename',data);if(kind==='openFile'){if(data.grant_id)openShared(data);else $(document).trigger('drive:details',[data.id]);}});

    $('.nav-item[data-view="overview"]').trigger('click');

});
