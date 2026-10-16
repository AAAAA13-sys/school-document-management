/* jQuery UI owns dialogs, tabs, autocomplete, accordion and the expiry picker.

   Bootstrap is CSS-only: no competing modal/tooltip/button plugins. */

$(function () {

    'use strict';

    let view = 'overview', page = 1, lastPage = 1, rows = [], selected = null, replacement = null, request = null;

    let uploadKey = crypto.randomUUID();

    let historyCursor = 0, historyCount = 0, historyRequest = null;

    const personal = ['student','teacher','employee'].includes($('.app-shell').data('role'));

    const actorKey = $('.app-shell').data('actor-key');

    const escape = value => $('<span>').text(value == null ? '' : value).html();

    const date = value => value ? new Date(value.replace(' ', 'T') + (value.includes('Z') || value.includes('+') ? '' : 'Z')).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '—';

    const message = xhr => xhr.responseJSON?.message || (xhr.status === 419 ? 'Your session expired. Reload and sign in again.' : 'The operation could not be completed. Please try again.');

    $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'), Accept: 'application/json' } });

    function notice(text, error = false) { $('#notice').text(text).attr('class', 'alert ' + (error ? 'alert-danger' : 'alert-success')).prop('hidden', false); }

    function setView(next) {

        if (!next || (next === 'documents' && $('.app-shell').data('role') !== 'admin')) next = 'files';

        view = next; page = 1;

        $('#workspace-welcome').prop('hidden', next !== 'overview');

        const names = { overview: ['Document overview', "A clear view of your school's documents and review workload."], documents: ['School repository', 'Find the right document, person or business reference.'], integrations: ['School system integrations', 'A shared document service for your four school systems.'], audit: ['Audit trail', 'Trace the activity behind your school records.'] };

        names.shared = ['Shared with me', 'Documents others have shared with you, with the access they granted.'];

        const guides = {overview:'Find your folders and recently updated documents here.',files:'Create folders, upload files, and use each file menu to rename, move, star or share. Expand My files in the sidebar to browse folders.',shared:'Open a document to view its shared version and comments. Commenters can post comments. Editors can also rename and upload a new version.',recent:'Browse your most recently updated documents.',starred:'Use Star in a file menu to keep favorites here.',trash:'Restore files from their menu. Their versions are preserved.',documents:'Search school records by title, person, reference or source.',history:'Inspect source revisions. Business history is read-only.',integrations:'Connect optional source systems through their scoped API contracts.',audit:'Inspect recorded activity within your school and campus.'};

        $('#page-help').text(guides[next] || 'Manage your documents here.');

        names.history = ['Central history', 'Inspect received business records and their preserved revisions.'];

        for (const key of ['files','recent','starred','trash']) names[key] = [{files:'My files',recent:'Recent files',starred:'Starred files',trash:'Trash'}[key], 'Organize your documents while preserving their evidence and history.'];

        names.overview = ['Welcome to your workspace', personal ? 'Everything you need, organized in one place.' : 'Documents, people and history, at a glance.'];

        $('#drive-panel').prop('hidden', !['overview','files','recent','starred','trash','shared'].includes(next));

        $(document).trigger('drive:view', [next]);

        document.title = names[next][0]+' · School Documents';
        $('#page-title').text(names[next][0]); $('#page-description').text(names[next][1]);

        $('#history-panel').prop('hidden', next !== 'history');

        $('#breadcrumb').text(next === 'overview' ? 'Overview' : names[next][0]);

        $('.nav-item').removeClass('active').removeAttr('aria-current').filter('[data-view="' + next + '"]').addClass('active').attr('aria-current','page');

        $('#overview-panel').prop('hidden', true);

        $('#repository-panel').prop('hidden', !['documents'].includes(next));

        $('#integrations-panel').prop('hidden', next !== 'integrations');

        $('#audit-panel').prop('hidden', next !== 'audit');

        $('#show-all').prop('hidden', next !== 'overview');

        $('#list-title').text(next === 'overview' ? 'Recent documents' : 'Document repository');

        $('#source-filter').val(''); $('#search').val(''); $('#notice').prop('hidden', true); $('.sidebar').removeClass('open');$('#sidebar-dismiss').prop('hidden',true);$('#menu-toggle').attr('aria-expanded','false');

        if (next === 'documents') loadDocuments();

        if (next === 'integrations') loadEvents(); if (next === 'audit') loadAudit();

        if (next === 'history') loadHistory(true);

    }

    function loadDocuments() {

        if (request) request.abort();

        $('#documents-body').html('<tr><td colspan="5" class="empty-state">Loading documents…</td></tr>');

        request = $.getJSON('/workspace/documents', { page, per_page: view === 'overview' ? 8 : 20, search: $('#search').val(), source: $('#source-filter').val() })

            .done(result => {

                rows = result.data; lastPage = result.last_page;

                $('#documents-body').html(rows.length ? rows.map(d => '<tr><td><div class="doc-name"><span class="file-icon">' + escape(d.current.filename.split('.').pop().toUpperCase()) + '</span><div><strong>' + escape(d.title) + '</strong><small>' + escape(d.category) + ' · v' + d.current.number + '</small></div></div></td><td><span class="record-owner">' + escape(d.subject) + '</span><span class="record-ref">' + escape(d.reference) + '</span></td><td><span class="source-label">' + escape(d.source) + '</span></td><td class="source-label">' + date(d.updated_at) + '</td><td><button class="btn btn-quiet open-document" data-id="' + escape(d.id) + '">Open</button></td></tr>').join('') : '<tr><td colspan="5" class="empty-state">No documents match these filters.</td></tr>');

                $('#result-count').text(result.total ? 'Showing ' + result.from + '–' + result.to + ' of ' + result.total + ' documents' : '0 documents');

                $('#page-number').text(page); $('#prev-page').prop('disabled', page <= 1); $('#next-page').prop('disabled', page >= lastPage);

                $('#subject').autocomplete('option', 'source', [...new Set(rows.map(d => d.subject))]);

            }).fail(xhr => { if (xhr.statusText !== 'abort') { $('#documents-body').html('<tr><td colspan="5" class="empty-state">Documents are unavailable.</td></tr>'); notice(message(xhr), true); } });

    }

    function loadStats() {

        $.getJSON('/workspace/stats').done(stats => {

            $('#stat-total').text(stats.total); $('#stat-available').text(stats.available); $('#stat-shared').text(stats.shared);


            $('#source-bars').html(stats.sources.map(s => '<div class="source-row"><div><span>' + escape(s.source) + '</span><strong>' + s.count + '</strong></div><div class="bar-track"><div class="bar-fill" style="width:' + (stats.total ? s.count / stats.total * 100 : 0) + '%"></div></div></div>').join(''));

        }).fail(xhr => notice(message(xhr), true));

    }

    $(document).on('drive:refresh',loadStats);
    let documentRequest = null;
    function openDocument(id) {
        if(documentRequest)documentRequest.abort();
        documentRequest = $.getJSON('/workspace/documents/' + id).done(result => {
            selected = result;
            const d = result.document, v = result.versions[0], clean = v.scan === 'Clean';
            const state = clean ? 'Available' : ({Pending:'Pending scan',Failed:'Scan failed',Quarantined:'Quarantined'}[v.scan] || 'Awaiting scan');
            const meta = [['Title',d.title],['Subject',d.subject],['Reference',d.reference],['Source',d.source],['Category',d.category],['Classification',d.classification],['Expires',v.expires_at || 'No expiry'],['Created',date(d.created_at)],['Modified',date(d.updated_at)],['Uploaded by',v.actor],['Version',v.number],['Checksum',v.checksum]];
            const download = x => x.scan === 'Clean' ? '<a class="btn btn-green" href="/workspace/files/'+escape(x.id)+'">Download version '+x.number+'</a>' : '<p class="scan-notice">Security scan: '+escape(x.scan)+'. File access remains blocked.</p>';
            const comments = result.comments?.length ? result.comments.map(c=>'<article class="shared-comment"><strong>'+escape(c.name)+'</strong><p>'+escape(c.body)+'</p></article>').join('') : '<p class="muted-label">No comments yet.</p>';
            const html = '<div class="document-state"><span class="file-state '+(clean?'is-clean':'is-blocked')+'">'+escape(state)+'</span><span>'+escape(d.source)+' · '+escape(d.classification)+'</span></div>'+
                '<div id="detail-tabs"><ul><li><a href="#document-summary">Details</a></li><li><a href="#document-history">Version history</a></li><li><a href="#document-preview">Preview</a></li><li><a href="#document-access">Shares</a></li></ul>'+
                '<div id="document-summary"><dl class="document-metadata">'+meta.map(x=>'<div><dt>'+escape(x[0])+'</dt><dd>'+escape(x[1])+'</dd></div>').join('')+'</dl>'+(v.reason?'<p class="form-instruction">Historical decision note: '+escape(v.reason)+'</p>':'')+'</div>'+
                '<div id="document-history">'+result.versions.map(x=>'<article class="version-card"><header class="version-heading"><strong>Version '+x.number+'</strong><span class="file-state '+(x.scan==='Clean'?'is-clean':'is-blocked')+'">'+escape(x.scan==='Clean'?'Available':x.scan)+'</span></header><p>'+escape(x.filename)+' · '+Math.ceil(x.size/1024)+' KB</p><p>'+escape(x.actor)+' · '+date(x.created_at)+'</p><code>SHA-256: '+escape(x.checksum)+'</code><div class="dialog-actions">'+download(x)+'</div></article>').join('')+'</div>'+
                '<div id="document-preview">'+(clean?'<p class="form-instruction">Preview of version '+v.number+'. Your access and file integrity are checked again when opened.</p><div id="document-preview-content" class="document-preview-content"></div>':'<p class="scan-notice">Preview is unavailable while this version is '+escape(state.toLowerCase())+'. Its metadata and version history are preserved.</p>')+'</div>'+
                '<div id="document-access"><div class="access-heading"><div><h3>People with access</h3><p>Named grants preserve the version shared with each recipient.</p></div>'+(clean?'<button type="button" class="btn btn-green" id="document-share-open">Share version</button>':'')+'</div><div id="document-grant-list" aria-live="polite">Loading access…</div><h3 class="mt-4">Comments</h3>'+comments+'</div></div>'+
                '<div class="dialog-actions document-footer"><button type="button" class="btn btn-quiet" id="document-close">Close</button>'+download(v)+'<button type="button" class="btn btn-quiet" id="replacement-open">Add new version</button></div>';
            $('#detail-content').html(html);
            let previewLoaded = false;
            $('#detail-tabs').tabs({activate: function (event, ui) {
                if (ui.newPanel.attr('id') !== 'document-preview' || previewLoaded || !clean) return;
                previewLoaded = true;
                const url = '/workspace/preview/'+v.id, area = $('#document-preview-content').empty();
                if (v.media_type === 'text/plain') {
                    const text = $('<pre class="history-payload">').text('Loading preview…').appendTo(area);
                    $.ajax({url,dataType:'text'}).done(value=>text.text(value)).fail(xhr=>text.text(message(xhr)));
                } else if (v.media_type.startsWith('image/')) {
                    $('<img class="document-image-preview" alt="Private document preview">').attr('src',url).on('error',()=>area.text('Preview unavailable. Reopen the document or download the clean version.')).appendTo(area);
                } else if (v.media_type === 'application/pdf') {
                    $('<iframe title="Private PDF preview" sandbox>').attr('src',url).appendTo(area);
                    $('<p class="form-instruction">').text('PDF viewing depends on your browser. Use Download if it is unavailable.').appendTo(area);
                } else area.text('A browser preview is not available for this format.');
            }});
            $('#detail-dialog').dialog('option','title',d.title).dialog('option','width',Math.min(1100,window.innerWidth-32)).dialog('open');
            $.getJSON('/workspace/drive/'+d.id+'/shares').done(grants=>{
                if(selected?.document.id!==d.id)return;
                const area=$('#document-grant-list').empty();
                if(!grants.length)area.append($('<p class="access-empty">').text('No named shares yet. Existing workspace role permissions still apply.'));
                grants.forEach(g=>{
                    const expired = new Date(g.expires_at.replace(' ','T')+'Z') <= new Date();
                    const row=$('<article class="access-row">').append($('<div>').append($('<strong>').text(g.email),$('<p>').text((g.permission||'viewer')+' · expires '+date(g.expires_at))));
                    if(g.revoked_at||expired)row.append($('<span class="muted-label">').text(g.revoked_at?'Revoked':'Expired'));
                    else row.append($('<button type="button" class="btn btn-quiet">').text('Revoke').on('click',function(){const b=$(this).prop('disabled',true);$.ajax({url:'/workspace/shares/'+g.id,method:'DELETE'}).done(()=>{row.find('button').remove();row.append($('<span class="muted-label">').text('Revoked'));$(document).trigger('drive:refresh');}).fail(xhr=>{b.prop('disabled',false);notice(message(xhr),true);});}));
                    row.appendTo(area);
                });
            }).fail(xhr=>{if(selected?.document.id===d.id)$('#document-grant-list').text(message(xhr));});
        }).fail(xhr=>{if(xhr.statusText!=='abort')notice(message(xhr),true);});
    }

    $(document).on('click','#document-close',()=>$('#detail-dialog').dialog('close'));
    $(document).on('click','#document-share-open',()=>{if(selected){$('#detail-dialog').dialog('close');$(document).trigger('document:share',[selected.document]);}});
    $('#detail-dialog').on('dialogclose',function(){$(this).find('iframe').attr('src','about:blank');$(this).find('.document-image-preview').removeAttr('src');});

    function openUpload(existing = null) {

        replacement = existing; uploadKey = crypto.randomUUID(); $('#upload-form')[0].reset(); $('#upload-error').prop('hidden', true);

        $('#upload-dialog').dialog('option', 'title', existing ? 'Add document version' : 'Upload document');

        $('#upload-tabs').tabs('option','active',0); $('#upload-form input,#upload-form select').prop('disabled', false);
        if (personal && !existing) $('#reference').val('PERSONAL-'+crypto.randomUUID().slice(0,8));

        if (existing) {

            for (const field of ['title','subject','reference','source','category','classification']) $('#' + field).val(existing[field]).prop('disabled', true);

            $('#upload-tabs').tabs('option','active',1);

        }

        $('#upload-dialog').dialog('open');

    }

    function loadAudit() {

        $.getJSON('/workspace/audit').done(r => $('#audit-body').html(r.items.length ? r.items.map(x => '<tr><td><strong>' + escape(x.action) + '</strong></td><td>' + escape(x.actor) + '</td><td>' + escape(x.detail || '—') + '</td><td>' + date(x.created_at) + '</td></tr>').join('') : '<tr><td colspan="4" class="empty-state">No activity recorded.</td></tr>')).fail(xhr => notice(message(xhr),true));

    }

    function loadEvents() {

        $.getJSON('/workspace/events').done(r => $('#event-list').html(r.items.length ? r.items.map(x => '<div class="event-row"><div><strong>' + escape(x.type) + '</strong><small>' + escape(x.source) + ' · Revision ' + x.revision + '</small></div><div>Sequence ' + x.id + '<small>' + date(x.created_at) + '</small></div></div>').join('') : '<p>No integration events recorded.</p>')).fail(xhr => notice(message(xhr),true));

    }

    function loadHistory(reset = false) {

        if (historyRequest) historyRequest.abort();

        if (reset) { historyCursor = 0; historyCount = 0; $('#history-body').empty(); }

        $('#history-more').prop('disabled', true); $('#history-count').text('Loading history…');

        historyRequest = $.getJSON('/workspace/history', { after: historyCursor, limit: 50, source: $('#history-source').val(), record_id: $('#history-record').val().trim() })

            .done(result => {

                $('#history-body .empty-state').closest('tr').remove();

                result.data.forEach(entry => $('#history-body').append('<tr><td><strong>' + escape(entry.record_id) + '</strong><small class="d-block">' + escape(entry.record_type) + '</small></td><td>' + escape(entry.source) + '</td><td>' + escape(entry.revision) + '</td><td>' + escape(entry.operation === 'delete' ? 'Deletion event' : entry.applied ? 'Snapshot applied' : 'Older revision retained') + '</td><td>' + date(entry.received_at) + '</td><td><button class="btn btn-quiet history-open" data-id="' + escape(entry.id) + '">Inspect</button></td></tr>'));

                historyCount += result.data.length; historyCursor = result.next_cursor;

                if (!historyCount) $('#history-body').html('<tr><td colspan="6" class="empty-state">No received history matches these filters. Connect a source system to start receiving records.</td></tr>');

                $('#history-count').text(historyCount + ' history entries loaded'); $('#history-more').prop('disabled', result.data.length < 50);

            }).fail(xhr => { if (xhr.statusText !== 'abort') { notice(message(xhr), true); $('#history-count').text('History unavailable. Refresh to retry.'); } });

    }

    $('#history-filters').on('submit', event => { event.preventDefault(); loadHistory(true); });

    $('#history-filters').on('reset', () => setTimeout(() => loadHistory(true), 0));

    $('#history-refresh').on('click', () => loadHistory(true)); $('#history-more').on('click', () => loadHistory());

    $(document).on('click', '.history-open', function () {

        $.getJSON('/workspace/history/' + $(this).data('id')).done(entry => {

            const content = $('<div>');

            $('<h2>').text(entry.record_id).appendTo(content);

            $('<p>').text(entry.source + ' · ' + entry.record_type + ' · Event ' + entry.event_id).appendTo(content);

            $('<p>').text('Occurred: ' + entry.occurred_at + ' UTC · Received: ' + entry.received_at + ' UTC').appendTo(content);

            $('<h3>').text('Received revision ' + entry.revision + ' · ' + entry.operation).appendTo(content);

            $('<pre class="history-payload">').text(JSON.stringify(entry.payload, null, 2)).appendTo(content);

            $('<h3>').text('Current revision ' + entry.current_revision + (entry.currently_deleted ? ' · Deleted at source' : '')).appendTo(content);

            $('<pre class="history-payload">').text(JSON.stringify(entry.current_payload, null, 2)).appendTo(content);

            $('<p>').text('History is read-only. Corrections must come from the originating system.').appendTo(content);

            $('#history-detail').empty().append(content); $('#history-dialog').dialog('open');

        }).fail(xhr => notice(message(xhr), true));

    });

    $('#upload-dialog,#detail-dialog,#history-dialog').prop('hidden',false).dialog({ autoOpen:false, modal:true, width:Math.min(680, window.innerWidth - 32), resizable:false, draggable:false });

    $('#upload-tabs').tabs(); $('#expires_at').datepicker({ dateFormat:'yy-mm-dd', changeMonth:true, changeYear:true });

    // Reveal the tab before the browser focuses a required field.
    $('#upload-form input,#upload-form select').on('invalid', function () {
        const panel = $(this).closest('.ui-tabs-panel');
        if (panel.length) $('#upload-tabs').tabs('option', 'active', panel.attr('id') === 'upload-file' ? 1 : 0);
    });

    $('#subject').autocomplete({ source:[], minLength:1 });

    $('.nav-item[data-view]').on('click',function(){setView($(this).data('view'));});

    $('#menu-toggle').on('click',function(){const open=$('.sidebar').toggleClass('open').hasClass('open');$(this).attr('aria-expanded',String(open));$('#sidebar-dismiss').prop('hidden',!open);});

    $('#show-all').on('click',()=>setView('documents'));

    let searchTimer; $('#search').on('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{page=1;loadDocuments();},250);});

    $('#source-filter').on('change',()=>{page=1;loadDocuments();});

    $('#reset-filters').on('click',()=>{$('#search,#source-filter').val('');page=1;loadDocuments();});

    $('#prev-page').on('click',()=>{if(page>1){page--;loadDocuments();}}); $('#next-page').on('click',()=>{if(page<lastPage){page++;loadDocuments();}});

    $(document).on('click','.open-document',function(){openDocument($(this).data('id'));});

    $('#upload-open').on('click',()=>openUpload()); $('#upload-cancel').on('click',()=>$('#upload-dialog').dialog('close'));

    $(document).on('drive:upload', (event, files) => { openUpload(); const transfer = new DataTransfer(); files.forEach(file => transfer.items.add(file)); $('#file')[0].files = transfer.files; $('#file').trigger('change'); $('#upload-tabs').tabs('option','active',1); });

    $(document).on('drive:details', (event, id) => openDocument(id));

    $(document).on('click','#replacement-open',()=>{$('#detail-dialog').dialog('close');openUpload(selected.document);});

    $('#file').on('change', function () { const file=this.files[0]; if(file && !replacement){$('#title').val(file.name);if(personal && !$('#reference').val())$('#reference').val('PERSONAL-'+crypto.randomUUID().slice(0,8));} });

    $('#category').on('change',function(){if($(this).val()==='Payslip'){$('#source').val('Payroll Management');$('#classification').val('Restricted');}});

    $('#upload-form').on('submit',function(e){

        e.preventDefault(); const file=$('#file')[0].files[0];

        if(!file||file.size>10*1024*1024||file.size===0){$('#upload-error').text('Choose a nonempty file up to 10 MB.').prop('hidden',false);$('#upload-tabs').tabs('option','active',1);return;}

        const data=new FormData(this);

        if(replacement){for(const f of ['title','subject','reference','source','category','classification'])data.set(f,replacement[f]);data.set('document_id',replacement.id);data.set('revision',replacement.revision);}

        $('#upload-submit').prop('disabled',true).text('Uploading…');

        $.ajax({url:'/workspace/documents',method:'POST',data,processData:false,contentType:false,headers:{'Idempotency-Key':uploadKey}})

            .done(result=>{$('#upload-dialog').dialog('close');notice(result.scan === 'Clean' ? 'Upload and security scan complete.' : 'Upload saved. File access is blocked until its security scan passes.');if(view==='documents')loadDocuments();loadStats();$(document).trigger('drive:uploaded',[result]);})

            .fail(xhr=>$('#upload-error').text(message(xhr)).prop('hidden',false))

            .always(()=>$('#upload-submit').prop('disabled',false).text('Upload document'));

    });

    $('#upload-form input,#upload-form select').on('change',()=>{uploadKey=crypto.randomUUID();});

    // Reveal an invalid field's tab before the browser attempts to focus it.

    $('#upload-form')[0].addEventListener('invalid',event=>{$('#upload-tabs').tabs('option','active',$(event.target).closest('#upload-file').length?1:0);},true);

    $('#refresh-audit').on('click',loadAudit); $('#refresh-events').on('click',loadEvents);

    $(window).on('resize',()=>$('#upload-dialog,#detail-dialog').dialog('option','position',{my:'center',at:'center',of:window}));

    loadStats(); if ($('.app-shell').data('role') === 'admin') loadDocuments();

});
