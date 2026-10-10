$(function () {
    'use strict';
    let selection=null, request=null, generation=0;
    const escape=value=>$('<span>').text(value??'').html();
    const date=value=>value?new Date(value.replace(' ','T')+(value.includes('Z')?'':'Z')).toLocaleString():'—';
    function show(open){$('#item-details').prop('hidden',!open);$('#main').toggleClass('details-open',open);$('#details-toggle').attr('aria-expanded',open).attr('aria-label',open?'Hide item details':'Show item details');}
    function clear(){selection=null;generation++;$('#selection-toolbar').prop('hidden',true);if(request)request.abort();$('.drive-card').removeClass('selected');$('#inspector-title').text('Details');$('#inspector-details').html('<div class="inspector-empty"><span aria-hidden="true">▤</span><p>Select an item to see its details</p></div>');$('#inspector-activity').text('Select an item to see its activity.');}
    function activity(items){$('#inspector-activity').html(items.length?items.map(a=>'<article class="inspector-event"><strong>'+escape(a.action)+'</strong><p>'+escape(a.actor)+'</p><small>'+escape(date(a.created_at))+'</small></article>').join(''):'<p>No recorded activity.</p>');}
    function render(data){
        const d=selection.item, folder=selection.kind==='folder';
        const entries=[['Type',folder?'Folder':d.current.filename.split('.').pop().toUpperCase()+' file'],['Created',date(d.created_at)],['Modified',date(d.updated_at)]];
        if(!folder)entries.push(['Size',(d.current.size/1024).toFixed(1)+' KB'],['Version',d.current.number],['Category',d.category]);
        if(selection.shared)entries.push(['Shared by',d.shared_by],['Your access',d.permission]);
        let html='<div class="inspector-symbol" aria-hidden="true">'+(folder?'▰':'▤')+'</div><dl class="inspector-metadata">'+entries.map(x=>'<div><dt>'+escape(x[0])+'</dt><dd>'+escape(x[1])+'</dd></div>').join('')+'</dl>';
        if(folder){html+='<h3>Folder access</h3><p>Access follows your workspace role and ownership. Folder sharing is not enabled.</p><button class="btn btn-green inspector-action" data-action="openFolder">Open folder</button> <button class="btn btn-quiet inspector-action" data-action="renameFolder">Rename</button>';}
        else if(selection.trashed){html+='<p>This file is in Trash. Restore it from the file menu to use it.</p>';}
        else {html+='<h3>Who has access</h3>';
            if(selection.shared)html+='<p>Your permission: '+escape(d.permission)+'.</p>';
            else html+='<small>Named shares you created</small>'+(data.grants?.length?data.grants.map(g=>'<p>'+escape(g.email)+'<br><small>'+escape(g.permission)+' · expires '+escape(date(g.expires_at))+'</small></p>').join(''):'<p>No active named shares. Workspace role permissions still apply.</p>')+'<button class="btn btn-quiet inspector-action" data-action="share">Manage access</button>';
            html+='<button class="btn btn-green inspector-action mt-3" data-action="openFile">'+(selection.shared?'Open shared document':'Open document & versions')+'</button>';
            if(!selection.shared)html+='<a class="btn btn-quiet mt-3" href="/workspace/files/'+escape(d.current.id)+'">Download</a>';
        }
        $('#inspector-details').html(html);activity(data.activity||[]);
    }
    $(document).on('item:select',(event,item)=>{
        clear();selection=item;show(true);$('#selection-toolbar').prop('hidden',false);$('#selection-label').text('1 selected');const actions=item.kind==='folder'?[['openFolder','Open'],['renameFolder','Rename']]:item.trashed?[]:[['openFile','Open'],...(!item.shared?[['share','Share']]:[])];$('#selection-actions').html(actions.map(a=>'<button class="btn btn-quiet inspector-action" data-action="'+a[0]+'">'+a[1]+'</button>').join(''));$('#inspector-title').text(item.item.name||item.item.title);$('#inspector-tabs').tabs('option','active',0);
        $('.drive-name').filter(function(){return $(this).data('id')===(item.item.grant_id||item.item.id);}).closest('.drive-card').addClass('selected');
        const current=++generation;
        if(item.shared){render({activity:[{action:'Shared version uploaded',actor:item.item.current.actor,created_at:item.item.current.created_at}]});return;}
        if(item.trashed){render({activity:[]});return;}
        $('#inspector-details').text('Loading details…');
        request=$.getJSON(item.kind==='folder'?'/workspace/folders/'+item.item.id:'/workspace/documents/'+item.item.id).done(data=>{
            if(current!==generation)return;
            if(item.kind==='folder'){render(data);return;}
            render(data);
            $.getJSON('/workspace/drive/'+item.item.id+'/shares').done(grants=>{if(current===generation)render({...data,grants:grants.filter(g=>!g.revoked_at&&new Date(g.expires_at.replace(' ','T')+'Z')>new Date())});});
        }).fail(xhr=>{if(current===generation&&xhr.statusText!=='abort')$('#inspector-details').text(xhr.responseJSON?.message||'Details unavailable.');});
    });
    $(document).on('item:clear',clear);
    $(document).on('click','.inspector-action',function(){if(selection)$(document).trigger('item:action',[$(this).data('action'),selection.item]);});
    $('#details-toggle').on('click',()=>show($('#item-details').prop('hidden')));
    $('#selection-clear').on('click',clear);
    $('#details-close').on('click',()=>show(false));
    $('#inspector-tabs').tabs();
});