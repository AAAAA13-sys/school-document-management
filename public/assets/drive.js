$(function () {

    'use strict';

    let sharedTarget = null, sharedUploadKey = crypto.randomUUID();

    let location = 'files', home = false, folder = null, folderSource = null, page = 1, files = [], folders = [], action = null, target = null, pendingFolder = null, request = null, crumbs = [];
    let colorTarget = null, colorChoice = '', createColor = '', shareTarget = null;

    const escape = value => $('<span>').text(value == null ? '' : value).html();

    const fail = xhr => $('#notice').text(xhr.responseJSON?.message || 'This action failed. Refresh and try again.').attr('class','alert alert-danger').prop('hidden',false);

    const inform = text => $('#notice').text(text).attr('class','alert alert-success').prop('hidden',false);

    const palette = () => $('#drive-panel').data('folder-colors') || {};

    const colorLabel = color => color ? (palette()[color] || 'Default') : 'Default';

    const tone = color => color ? ' data-folder-color="' + escape(color) + '"' : '';

    const folderShareDialogWidth = () => 520;

    function renderSwatches(container, current, onPick) {
        const group = container.addClass('folder-swatches').empty();
        const pick = (color, button) => { group.find('.folder-swatch').attr('aria-pressed','false'); $(button).attr('aria-pressed','true'); onPick(color); };
        $('<button type="button" class="folder-swatch folder-swatch-default">').attr('aria-label','Default folder color').attr('aria-pressed', current ? 'false' : 'true').appendTo(group).on('click',function(){pick('',this);});
        Object.entries(palette()).forEach(([token,label]) => {
            $('<button type="button" class="folder-swatch">').attr('data-folder-color',token).attr('title',label).attr('aria-label',label+' folder color').attr('aria-pressed', token===current ? 'true' : 'false').appendTo(group).on('click',function(){pick(token,this);});
        });

        return group;
    }

    function applyFolderColor(target, color) {
        return $.ajax({url:'/workspace/folders/'+target.id, method:'PATCH', data:{color: color||'', revision: target.revision}})
            .done(result => {
                if (Object.prototype.hasOwnProperty.call(result, 'color')) target.color = result.color;
                if (result.revision) target.revision = result.revision;
                $('#folder-color-dialog').dialog('close');
                inform('Folder color saved.');
                load();
            })
            .fail(xhr => { if ($('#folder-color-dialog').dialog('isOpen')) { $('#folder-color-error').text(xhr.responseJSON?.message || 'Color could not be saved.'); load(); } else fail(xhr); });
    }

    function openColorDialog(f) {
        if (!f) return;
        colorTarget = f; colorChoice = f.color || '';
        $('#folder-color-target').text('Color for "' + f.name + '"');
        $('#folder-color-error').empty();
        renderSwatches($('#folder-color-swatches'), colorChoice, color => colorChoice = color);
        $('#folder-color-dialog').dialog('open');
    }

    function shareRow(person, level, extra, removable) {
        const initials = escape(person.name || person.email).trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();
        return '<div class="share-person">'+
            '<span class="share-avatar" aria-hidden="true">'+escape(initials)+'</span>'+
            '<span class="share-person-text"><strong>'+escape(person.name || 'Unknown')+'</strong><small>'+escape(person.email)+'</small><small>'+extra+'</small></span>'+
            '<span class="share-level">'+escape(level)+'</span>'+
            (removable ? '<button type="button" class="btn btn-quiet share-remove" data-id="'+escape(person.id)+'" aria-label="Remove access for '+escape(person.email)+'">Remove</button>' : '')+
        '</div>';
    }

    function renderShareSheet(data) {
        const list = $('#folder-share-list').empty();
        if (data.owner) list.append(shareRow(data.owner, 'Owner', '', false));
        (data.shares || []).forEach(g => {
            const expired = g.revoked_at || new Date(String(g.expires_at).replace(' ', 'T') + 'Z') <= new Date();
            list.append(shareRow({id: g.id, name: g.name, email: g.email}, ({viewer:'Viewer', commenter:'Commenter', editor:'Editor'}[g.permission] || 'Viewer') + (expired ? ' · Expired' : ''), 'Expires ' + g.expires_at + ' UTC', true));
        });
        if (!data.owner && !(data.shares || []).length) list.append($('<p class="share-hint">').text('No one else has access yet.'));
    }

    function openShareDialog(f) {
        if (!f) return;
        shareTarget = f;
        $('#folder-share-intro').text('Share "' + f.name + '" with people in your school and campus.');
        $('#folder-share-email').val('');
        $('#folder-share-error').empty();
        $('#folder-share-status').empty();
        $('#folder-share-dialog').dialog('option', 'title', 'Share "' + f.name + '"');
        $('#folder-share-dialog').dialog('open');
        $.getJSON('/workspace/folders/' + f.id + '/shares').done(renderShareSheet).fail(xhr => $('#folder-share-error').text(xhr.responseJSON?.message || 'Sharing is unavailable.'));
    }

    $('#folder-share-form').on('submit', function (event) {
        event.preventDefault();
        if (!shareTarget) return;
        const expires = new Date();
        expires.setDate(expires.getDate() + 7);
        $('#folder-share-add').prop('disabled', true);
        $.post('/workspace/folders/' + shareTarget.id + '/shares', {
            email: $('#folder-share-email').val(),
            permission: $('#folder-share-permission').val(),
            expires_at: expires.toISOString().slice(0, 10) + 'T23:59:59Z',
        }).done(result => {
            renderShareSheet(result);
            $('#folder-share-email').val('');
            $('#folder-share-status').text('Access granted. The recipient must sign in to see it.');
        }).fail(xhr => $('#folder-share-error').text(xhr.responseJSON?.message || 'Access could not be granted.')).always(() => $('#folder-share-add').prop('disabled', false));
    });

    $(document).on('click', '.share-remove', function () {
        const row = $(this).closest('.share-person');
        $('#folder-share-status').text('Removing access…');
        $.ajax({url: '/workspace/folder-shares/' + $(this).data('id'), method: 'DELETE'}).done(() => {
            row.remove();
            $('#folder-share-status').text('Access removed.');
        }).fail(xhr => $('#folder-share-error').text(xhr.responseJSON?.message || 'Access could not be removed.'));
    });

    $(document).on('click', '.folder-share-open', function () { openShareDialog(folders.find(f => f.id === $(this).data('id'))); });

    $('#folder-share-link').on('click', function () {
        if (!shareTarget) return;
        const link = window.location.origin + '/?folder=' + shareTarget.id;
        const done = () => $('#folder-share-status').text('Link copied. Only people with access can open it.');
        if (navigator.clipboard?.writeText) navigator.clipboard.writeText(link).then(done).catch(() => $('#folder-share-status').text(link));
        else $('#folder-share-status').text(link);
    });

    function openFolder(id) {
        $('.nav-item[data-view="files"]').trigger('click');
        folder = id || null; home = false; page = 1; location = 'files';
        $('#drive-panel').removeClass('drive-home'); $('#drive-search').val('');
        load();
    }

    const personal = ['student','teacher','employee'].includes($('.app-shell').data('role'));

    const date = value => new Date(value.replace(' ','T')+'Z').toLocaleDateString();
    function refreshFolderTree() {
        $.getJSON('/workspace/folders').done(all => {
            $('#folders-toggle').prop('hidden', !all.length);
            const container = $('#sidebar-folders').empty(), ids = new Set(all.map(f => f.id));
            function append(parent, depth, target = container, source = null, visited = new Set()) {
                all.filter(f => (!source || f.source === source) && (parent === null ? !f.parent_id || !ids.has(f.parent_id) : f.parent_id === parent)).forEach(f => {
                    if (visited.has(f.id)) return;
                    visited.add(f.id);
                    $('<button type="button" class="sidebar-folder">').attr('data-id', f.id).css('padding-left', 16 + Math.min(depth, 12) * 12).append($('<span class="folder-dot"'+tone(f.color)+' aria-hidden="true">'), document.createTextNode('▰ '+f.name)).appendTo(target);
                    append(f.id, depth + 1, target, source, visited);
                });
            }
            append(null, 0);
            $('.source-folder-list').each(function(){
                const group=$(this).empty(), source=$(this).attr('data-source');
                append(null,0,group,source);
                if(!group.children().length)group.append($('<span class="source-empty">').text('No folders yet'));
            });
            container.prop('hidden', !all.length || $('#folders-toggle').attr('aria-expanded') !== 'true');
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
        openFolder(id);
    });
    function loadComments() {
        if (!sharedTarget) return;
        $.getJSON(sharedTarget.share_url + '/comments').done(items => {
            const area = $('#shared-comments').empty();
            if (!items.length) area.text('No comments yet.');
            items.forEach(c => $('<article class="shared-comment">').append($('<strong>').text(c.name), $('<p>').text(c.body), $('<small>').text(date(c.created_at))).appendTo(area));
        }).fail(xhr => $('#shared-error').text(xhr.responseJSON?.message || 'Comments unavailable.'));
    }
    function openShared(d) {
        sharedTarget = d; sharedUploadKey = crypto.randomUUID();
        if (!d.share_url) d.share_url = '/workspace/shared/' + d.grant_id;
        $('#shared-title').text(d.title); $('#shared-access').text('Shared by ' + d.shared_by + ' · ' + ({viewer:'Viewer',commenter:'Commenter',editor:'Editor'}[d.permission] || d.permission));
        $('#shared-download').attr('href', d.shared && d.current ? '/workspace/files/' + d.current.id : '/workspace/shared/' + d.grant_id).prop('hidden', d.current.scan !== 'Clean');
        $('#shared-comment-form').prop('hidden', !['commenter','editor'].includes(d.permission));
        $('#shared-rename-form,#shared-version-form').prop('hidden', d.permission !== 'editor');
        $('#shared-name').val(d.title); $('#shared-comment,#shared-file').val('');$('#shared-error').empty();
        loadComments(); $('#shared-detail-dialog').dialog('open');
    }
    function sharedFailure(xhr) { $('#shared-error').text(xhr.responseJSON?.message || 'Action failed.'); }
    $('#shared-comment-form').on('submit', function (e) {
        e.preventDefault();
    if (!sharedTarget) return;
    const button = $(this).find('button').prop('disabled', true);
        $.post(sharedTarget.share_url + '/comments', {body:$('#shared-comment').val()})
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

        const transfer = d => d.current.scan === 'Clean' ? '<button class="btn btn-quiet file-preview" data-version="'+escape(d.current.id)+'">Preview</button><a class="btn btn-quiet" href="/workspace/files/'+escape(d.current.id)+'">Download</a>' : '<p class="scan-notice">Security scan: '+escape(d.current.scan)+'</p>';

        const actions = d => d.shared ? '<button class="btn btn-quiet file-details" data-id="'+escape(d.id)+'">Open shared file</button>'+transfer(d) : location==='shared' ? '<button class="btn btn-quiet file-details" data-id="'+escape(d.grant_id||d.id)+'">Open shared file</button>' : location==='trash' ? '<button class="btn btn-quiet file-action" data-action="restore" data-id="'+escape(d.id)+'">Restore</button>' : ['star','rename','move',...(d.current.scan === 'Clean' ? ['share'] : []),'trash'].map(a=>'<button class="btn btn-quiet file-action" data-action="'+a+'" data-id="'+escape(d.id)+'">'+({star:d.starred?'Unstar':'Star',rename:'Rename',move:'Move',share:'Share',trash:'Move to Trash'}[a])+'</button>').join('')+transfer(d);

        const folderMenu = f => '<div><button type="button" class="btn btn-quiet folder-open" data-id="'+escape(f.id)+'">Open folder</button><button type="button" class="btn btn-quiet folder-details" data-id="'+escape(f.id)+'">Folder details</button>'+(f.shared ? '' : '<button type="button" class="btn btn-quiet folder-add" data-id="'+escape(f.id)+'" data-source="'+escape(f.source)+'">Add file to this folder</button><button type="button" class="btn btn-quiet folder-rename" data-id="'+escape(f.id)+'">Rename folder</button><button type="button" class="btn btn-quiet folder-color" data-id="'+escape(f.id)+'">Folder color</button><button type="button" class="btn btn-quiet folder-share-open" data-id="'+escape(f.id)+'">Share</button>')+'</div>';

        const folderCards=folders.map(f=>'<article class="drive-card folder-card'+(f.shared?' folder-shared':'')+'" data-id="'+escape(f.id)+'"><button type="button" class="drive-folder drive-name" data-id="'+escape(f.id)+'" aria-label="Select folder '+escape(f.name)+'"><span class="folder-chip"'+tone(f.color)+' aria-hidden="true">▰</span><strong>'+escape(f.name)+'</strong></button>'+(f.shared?'<span class="shared-badge">Shared · '+escape({viewer:'Viewer',commenter:'Commenter',editor:'Editor'}[f.permission]||'Viewer')+'</span>':'')+'<span class="drive-owner">'+escape(f.source)+'</span><span class="drive-date">'+date(f.updated_at)+'</span><span class="drive-size">—</span><details class="drive-menu"><summary aria-label="Folder actions for '+escape(f.name)+'">⋮</summary>'+folderMenu(f)+'</details></article>').join('');

        const fileCards=files.map(d=>{
            const clean=d.current.scan==='Clean', extension=d.current.filename.split('.').pop().toUpperCase();
            const state=clean?'Available':({Pending:'Pending scan',Failed:'Scan failed',Quarantined:'Quarantined'}[d.current.scan]||'Awaiting scan');
            return '<article class="drive-card file-card"><button type="button" class="drive-name file-details" data-id="'+escape(d.grant_id||d.id)+'"><span class="file-extension">'+escape(extension)+'</span><strong>'+escape(d.title)+'</strong></button>'+
                '<div class="drive-thumbnail" aria-label="Record summary"><strong>'+escape(d.category)+'</strong><p>'+escape(d.reference)+'</p><p>Version '+d.current.number+'</p><p>'+escape(d.classification)+'</p></div>'+
                '<span class="file-state '+(clean?'is-clean':'is-blocked')+'">'+escape(state)+'</span>'+
                '<span class="drive-owner">'+escape(location==='shared'?d.shared_by+' · '+d.permission:d.source)+'</span><span class="drive-date">'+date(d.updated_at)+'</span><span class="drive-size">'+(d.current.size/1024).toFixed(1)+' KB</span>'+
                '<details class="drive-menu"><summary aria-label="File actions for '+escape(d.title)+'">⋮</summary><div>'+actions(d)+'</div></details></article>';
        }).join('');

        const headings='<div class="drive-columns"><span>Name</span><span>Source / shared by</span><span>Availability</span><span>Date modified</span><span>File size</span><span></span></div>';

        if (home) {
            const cards = $(fileCards).toArray();
            const take = list => list.map(n => n.outerHTML).join('');
            const starred = cards.filter((n, i) => files[i].starred);
            $('#drive-items').addClass('drive-home-items').html(
                '<h2 class="suggestion-title">Suggested documents</h2><div class="home-file-grid">'+(take(cards.slice(0,3))||'<p class="home-empty">Upload a document to get started.</p>')+'</div>'+
                '<h2 class="suggestion-title">Recent</h2><div class="home-file-list drive-list">'+headings+take(cards.slice(0,5))+'</div>'+
                '<h2 class="suggestion-title">Starred</h2><div class="home-file-list drive-list">'+(starred.length?headings+take(starred.slice(0,3)):'<p class="home-empty">Star a file to keep it close.</p>')+'</div>'+
                '<h2 class="suggestion-title">Folders</h2><div class="suggested-folders">'+(folderCards||'<p class="home-empty">No folders yet.</p>')+'</div>'+
                '<h2 class="suggestion-title">Files</h2><div class="home-file-list drive-list">'+headings+fileCards+'</div>');
        } else $('#drive-items').removeClass('drive-home-items').html(headings+folderCards+fileCards);

        if(!files.length&&!folders.length)$('#drive-items').html('<p class="empty-state">No files here.</p>');

    }

    function load() {

        if(request) request.abort();

        $(document).trigger('item:clear');
        $('#drive-items').text('Loading files…');

        request=$.getJSON(location==='shared'?'/workspace/shared-with-me':'/workspace/drive',{location,folder,page,search:$('#drive-search').val(),sort:$('#drive-sort').val()}).done(result=>{

            files=result.files.data; folders=result.folders;

            crumbs=result.breadcrumbs||[]; folderSource=crumbs.at(-1)?.source||null;

            syncFolderLink();


            $('#drive-crumbs').html(location==='shared'?'<strong>Shared with me</strong>':'<button class="btn btn-quiet drive-folder" data-id="">My files</button>'+crumbs.map(f=>'<span> / </span><button class="btn btn-quiet drive-folder" data-id="'+escape(f.id)+'"><span class="folder-dot"'+tone(f.color)+' aria-hidden="true"></span>'+escape(f.name)+'</button>').join(''));

            renderBack();

            render();

            if(!files.length&&!folders.length) $('#drive-items').html('<div class="drive-empty"><span class="empty-file-icon" aria-hidden="true">▤</span><h2>'+({trash:'Trash is empty',starred:'Keep your favorites close',recent:'Your recent documents',home:'Start your document space',files:'Make room for your files',shared:'Shared with you'}[location]||'No matching files')+'</h2><p>'+($('#drive-search').val()?'Try another name or clear your search.':location==='trash'?'Files you move to Trash will appear here.':location==='shared'?'Documents shared with your account will appear here.':location==='starred'?'Star a file from its menu to find it here.':'Upload your first document or create a folder to get organized.')+'</p></div>');

            $('#drive-count').text(result.files.total+' files · '+folders.length+' folders'); $('#drive-prev').prop('disabled',page<=1); $('#drive-next').prop('disabled',page>=result.files.last_page);

            refreshFolderTree();



        }).fail(xhr=>{if(xhr.statusText!=='abort'){ $('#drive-items').text('Files unavailable. Try searching again.'); fail(xhr); }});

    }

    function renderBack() {
        const inside = location==='files' && !!folder;
        const parent = inside && crumbs.length>1 ? crumbs[crumbs.length-2] : null;
        $('#drive-back').prop('hidden',!inside)
            .attr('aria-label', parent ? 'Back to '+parent.name : 'Back to My files');
    }

    function send(id,kind,extra={}) {
        const d=files.find(x=>x.id===id);
        // A stale list used to throw here, which aborted the submit handler after the Save
        // button was disabled and left the dialog permanently wedged with no message.
        if(!d) return $.Deferred().reject({responseJSON:{message:'This item is no longer in the current view. Refresh and try again.'}}).promise();
        return $.post('/workspace/drive/'+id+'/action',{action:kind,revision:d.revision,...extra}).done(load).fail(fail);
    }

    function dialog(kind,d=null) {

        action=kind; target=d; $('#drive-action-error').empty(); $('#drive-action-fields').empty();

        $('#drive-dialog').dialog('option','title',{rename:'Rename file',folderRename:'Rename folder',create:'New folder',move:'Move file',share:'Share a version'}[kind]);

        if(['rename','folderRename','create'].includes(kind)) $('<label class="form-label">').text('Name').append($('<input class="form-control" name="name" required maxlength="200">').val(d?.title||d?.name||'')).appendTo('#drive-action-fields');

        if(kind==='create') { const select=$('<select name="source" class="form-select" aria-label="Folder source">'); $('#source option').each(function(){if(!folderSource||$(this).text()===folderSource)select.append($('<option>').text($(this).text()));}); $('#drive-action-fields').append(select); const picker=$('<div>').attr('role','group').attr('aria-label','Folder color'); $('#drive-action-fields').append('<label class="form-label">Folder color</label>',picker); renderSwatches(picker, createColor, color => createColor = color); }

        if(kind==='move') $.getJSON('/workspace/folders').done(all=>{ const select=$('<select class="form-select" name="folder_id" aria-label="Destination folder">').append('<option value="">My files</option>'); all.filter(f=>f.source===d.source).forEach(f=>select.append($('<option>').val(f.id).text(f.name+' · '+f.id.slice(0,8)))); $('#drive-action-fields').append(select); }).fail(fail);

        if(kind==='share') {

            $('#drive-action-fields').append('<p>Share with an active account in your school and campus. Choose their access. Links expire and preserve this version.</p><label class="form-label">Recipient email<input type="email" name="email" class="form-control" required></label><label class="form-label">Access<select name="permission" class="form-select"><option value="viewer">Viewer  -  read and download</option><option value="commenter">Commenter  -  also add comments</option><option value="editor">Editor  -  also rename and upload versions</option></select></label><label class="form-label">Expiry date<input type="date" name="expires_at" class="form-control" required></label><div id="share-list"></div>');

            $.getJSON('/workspace/drive/'+d.id+'/shares').done(grants=>grants.forEach(g=>$('#share-list').append($('<p>').text(g.email+' · '+g.expires_at+' UTC ').append(g.revoked_at?'Revoked':$('<button type="button" class="btn btn-quiet">').text('Revoke').on('click',()=>$.ajax({url:'/workspace/shares/'+g.id,method:'DELETE'}).done(()=>dialog('share',d)).fail(fail)))))).fail(fail);

        }

        $('#drive-dialog').dialog('open');

    }

    $(document).on('drive:view',(event,next)=>{if(['overview','files','recent','starred','trash','shared'].includes(next)){home=next==='overview';location=home?'home':next;folder=null;page=1;$('#drive-panel').toggleClass('drive-home',home);$('#drive-items').removeClass('drive-list');$('#drive-layout').text('List view').attr('aria-pressed',false);load();}});

    $('#drive-filter').on('submit',event=>{event.preventDefault();page=1;load();}); $('#drive-sort').on('change',()=>{page=1;load();});

    $('#drive-layout').on('click',function(){const list=$('#drive-items').toggleClass('drive-list').hasClass('drive-list');$(this).text(list?'Grid view':'List view').attr('aria-pressed',list);});

    $('#drive-prev').on('click',()=>{page--;load();}); $('#drive-next').on('click',()=>{page++;load();});

    $('#drive-back').on('click',function(){
        openFolder(folder && crumbs.length>1 ? crumbs[crumbs.length-2].id : null);
    });

    $(document).on('click','.drive-folder',function(){if($(this).closest('.folder-card').length){inspectFolder($(this).data('id'));return;}const id=$(this).data('id')||null;if(home){$('.nav-item[data-view="files"]').trigger('click');}openFolder(id);});

    $(document).on('dblclick','.file-details',function(){if(location==='shared'){openShared(files.find(f=>f.grant_id===$(this).data('id')));}else if(location!=='trash')$(document).trigger('drive:details',[$(this).data('id')]);});

    // Double-click anywhere on a folder card opens it. Single click still opens details,
    // and the per-card menu keeps a keyboard/pointer path that double-click cannot replace.
    $(document).on('dblclick','.folder-card',function(event){
        if($(event.target).closest('.drive-menu,.folder-rename,.folder-open').length)return;
        openFolder($(this).attr('data-id'));
    });

    $(document).on('click','.folder-open',function(){openFolder($(this).data('id'));});

    // "Add file to this folder" opens the upload dialog already aimed at that folder.
    $(document).on('click','.folder-add',function(){
        const id = $(this).data('id'), source = $(this).data('source');
        $(this).closest('details').removeAttr('open');
        $('#upload-open').trigger('click');
        if ($('#source option[value="'+source+'"]').length) $('#source').val(source);
        fillDestination(id);
    });

    $(document).on('click','.folder-details',function(){inspectFolder($(this).data('id'));});
    $(document).on('click','.folder-color',function(){openColorDialog(folders.find(f=>f.id===$(this).data('id')));});

    $(document).on('click','.folder-rename',function(){dialog('folderRename',folders.find(f=>f.id===$(this).data('id')));});

    $('#drive-new-folder').on('click',()=>{if(!$('#drive-panel').is(':visible')||!['home','files'].includes(location)){$('.nav-item[data-view="files"]').trigger('click');folder=null;folderSource=null;}dialog('create');});

    $('#upload-open').on('click',()=>{if($('#drive-panel').is(':visible')&&folderSource)$('#source').val(folderSource);});

    function fillDestination(preferred) {
        const sel = $('#upload-folder'), note = $('#upload-destination-note');
        if (!sel.length) return;
        const source = $('#source').val();
        sel.empty().append($('<option>').val('').text('My files — no folder'));
        $.getJSON('/workspace/folders').done(all => {
            all.filter(f => f.source === source).forEach(f => sel.append($('<option>').val(f.id).text(f.name)));
            const want = preferred || folder;
            sel.val(want && all.some(f => f.id === want) ? want : '');
            note.text(sel.val() ? 'This document will be saved into the selected folder.' : 'This document will be saved at the top of My files.');
        }).fail(fail);
    }

    $('#upload-dialog').on('dialogopen', () => fillDestination());
    $('#source').on('change', () => { if ($('#upload-dialog').is(':visible')) fillDestination(); });

    $(document).on('drive:upload',()=>{if(folderSource)$('#source').val(folderSource);});

    $(document).on('click','.file-action',function(){const kind=$(this).data('action'),d=files.find(d=>d.id===$(this).data('id'));if(!d){fail({responseJSON:{message:'This item is no longer in the current view. Refresh and try again.'}});return;}if(['star','trash','restore'].includes(kind))send(d.id,kind);else dialog(kind,d);});

    $('#drive-action-form').on('submit',function(event){event.preventDefault();const p=Object.fromEntries(new FormData(this));let op;

        if(action==='create')op=$.post('/workspace/folders',{name:p.name,source:p.source,parent_id:folder,color:createColor});

        if(action==='folderRename')op=$.ajax({url:'/workspace/folders/'+target.id,method:'PATCH',data:{name:p.name,revision:target.revision}});

        if(action==='rename')op=send(target.id,'rename',{title:p.name});

        if(action==='move')op=send(target.id,'move',{folder_id:p.folder_id});

        if(action==='share')op=$.post('/workspace/drive/'+target.id+'/shares',{email:p.email,permission:p.permission,expires_at:p.expires_at+'T23:59:59Z'});

        $(this).find('button[type!=button]').prop('disabled',true);

        op.done(result=>{if(action==='share') { $('#drive-action-error').removeClass('text-danger').text('Share link: '+window.location.origin+result.url); } else { if(action==='move'){ const name=p.folder_id?$('#drive-action-fields select[name=folder_id] option:selected').text().split(' · ')[0]:'My files'; $('#notice').text('Moved to '+name+'.').attr('class','alert alert-success').prop('hidden',false); } $('#drive-dialog').dialog('close');load();refreshFolderTree();}}).fail(xhr=>$('#drive-action-error').addClass('text-danger').text(xhr.responseJSON?.message||'Action failed.')).always(()=>$(this).find('button').prop('disabled',false));

    });

    $('#drive-drop').on('dragover',event=>{event.preventDefault();$(event.currentTarget).addClass('dropping');}).on('dragleave',()=>$('#drive-drop').removeClass('dropping')).on('drop',event=>{event.preventDefault();$('#drive-drop').removeClass('dropping');const dropped=Array.from(event.originalEvent.dataTransfer.files);if(dropped.length!==1){fail({responseJSON:{message:'Drop one file at a time so its required metadata can be recorded.'}});return;}pendingFolder=folder;$(document).trigger('drive:upload',[dropped]);});

    $(document).on('drive:uploaded',(event,result,isVersion)=>{
        if(isVersion)return;                       // a new version keeps its document's folder
        const destination=$('#upload-folder').val()||pendingFolder||folder;
        if(destination){$.getJSON('/workspace/documents/'+result.document_id).done(r=>$.post('/workspace/drive/'+result.document_id+'/action',{action:'move',revision:r.document.revision,folder_id:destination}).done(()=>{if(folder)load();}).fail(fail));}
        else if($('#drive-panel').is(':visible'))load();
        pendingFolder=null;
    });

    $(document).on('click','.file-preview',function(){const version=$(this).data('version'),file=files.find(d=>d.current.id===version),url='/workspace/preview/'+version;$('#preview-text,#preview-image,#file-preview').prop('hidden',true);$('#preview-note').text('Loading preview…');$('#preview-dialog').dialog('open');

        if(file.current.media_type==='text/plain')$.ajax({url,dataType:'text'}).done(text=>{$('#preview-text').text(text).prop('hidden',false);$('#preview-note').empty();}).fail(xhr=>$('#preview-note').text(xhr.responseJSON?.message||'Preview unavailable.'));

        else if(file.current.media_type.startsWith('image/'))$('#preview-image').off('load error').on('load',()=>$('#preview-note').empty()).on('error',()=>$('#preview-note').text('Preview unavailable.')).attr('src',url).prop('hidden',false);

        else {$('#file-preview').attr('src',url).prop('hidden',false);$('#preview-note').text('PDF viewing depends on browser support. Use Download if the preview is unavailable.');}

    });

    $(document).on('document:share',(event,document)=>dialog('share',document));

    $('#drive-dialog,#preview-dialog,#folder-color-dialog,#folder-share-dialog').prop('hidden',false).dialog({autoOpen:false,modal:true,width:Math.min(760,window.innerWidth-32),resizable:false,draggable:false});

    $('#folder-color-dialog,#folder-share-dialog').dialog('option','width',Math.min(folderShareDialogWidth(),window.innerWidth-32));

    $(window).on('resize',()=>$('#folder-color-dialog,#folder-share-dialog').dialog('option','width',Math.min(folderShareDialogWidth(),window.innerWidth-32)));

    $('#folder-color-cancel').on('click',()=>$('#folder-color-dialog').dialog('close'));

    $('#folder-color-save').on('click',()=>{if(colorTarget)applyFolderColor(colorTarget,colorChoice);});

    $('#preview-dialog').on('dialogclose',()=>{$('#file-preview').attr('src','about:blank');$('#preview-image').removeAttr('src');$('#preview-text').empty();});


    $(document).on('drive:refresh',()=>{load();refreshFolderTree();});
    function inspectFolder(id) { const d=folders.find(f=>f.id===id);if(d)$(document).trigger('item:select',[{kind:'folder',item:d}]); }
    $(document).on('click','.file-details',function(){const d=files.find(f=>(f.grant_id||f.id)===$(this).data('id'));if(d)$(document).trigger('item:select',[{kind:'file',item:d,shared:location==='shared'||!!d.shared,trashed:location==='trash'}]);});
    $(document).on('item:action',(event,kind,data)=>{if(kind==='openFolder')openFolder(data.id);if(kind==='share')dialog('share',data);if(kind==='renameFolder')dialog('folderRename',data);if(kind==='folderColor')openColorDialog(data);if(kind==='folderShare')openShareDialog(data);if(kind==='openFile'){if(data.grant_id)openShared(data);else $(document).trigger('drive:details',[data.id]);}});

    function syncFolderLink() {
        const url = new URL(window.location.href);
        if (location === 'files' && folder) url.searchParams.set('folder', folder);
        else url.searchParams.delete('folder');
        window.history.replaceState({}, '', url.pathname + url.search);
    }

    $('.nav-item[data-view="overview"]').trigger('click');

    const linkedFolder = new URLSearchParams(window.location.search).get('folder');
    if (linkedFolder) openFolder(linkedFolder);

});
