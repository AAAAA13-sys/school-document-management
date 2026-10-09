$(function () {
    'use strict';
    let batch=null, busy=false;
    const picker=$('#folder-upload-picker')[0], form=$('#upload-form')[0];
    const notice=text=>$('#notice').text(text).attr('class','alert alert-info').prop('hidden',false);
    $('.new-menu-options button').on('click',()=>$('#new-menu').prop('open',false));
    $(document).on('click',e=>{if(!$(e.target).closest('#new-menu').length)$('#new-menu').prop('open',false);});
    $(document).on('keydown',e=>{if(e.key==='Escape')$('#new-menu').prop('open',false);});
    $('#folder-upload-open').on('click',()=>{if(busy)return;if(!('webkitdirectory' in picker)){notice('Folder upload is unavailable in this browser. Use File upload instead.');return;}picker.value='';picker.click();});
    $('#upload-open').on('click',()=>{if(!busy)batch=null;});
    $(picker).on('change',()=>{
        if(busy)return;
        const files=Array.from(picker.files);
        if(!files.length)return;
        if(files.length>50){notice('Choose a folder containing at most 50 files.');return;}
        for(const f of files){const parts=f.webkitRelativePath.split('/');if(parts.length<2||parts.some(p=>!p||p==='.'||p==='..'||p.length>200)||!f.size||f.size>10*1024*1024||!/\.(pdf|png|jpe?g|txt)$/i.test(f.name)){notice('Folder upload accepts nonempty PDF, PNG, JPG or TXT files up to 10 MB each, with folder names up to 200 characters.');return;}}
        const parent=$('#drive-panel').is(':visible')?$('#drive-crumbs .drive-folder').last().data('id')||null:null;
        batch={parent, entries:files.map(file=>({file,path:file.webkitRelativePath.split('/'),key:crypto.randomUUID(),result:null,done:false})), metadata:null, folders:new Map()};
        $(document).trigger('drive:upload',[[files[0]]]);
        $('#title').val(files[0].webkitRelativePath.split('/')[0]);
        $('#upload-dialog').dialog('option','title','Upload folder');
        $('#upload-submit').text('Upload '+files.length+' files');
        notice('Enter the record details once for this folder. Each file keeps its own name.');
    });
    $('#upload-dialog').on('dialogclose',()=>{if(!busy){batch=null;$('#upload-dialog').dialog('option','title','Upload document');}});
    async function destination(job,entry){
        let parent=job.parent;
        for(let i=0;i<entry.path.length-1;i++){
            const key=JSON.stringify(entry.path.slice(0,i+1));
            if(job.folders.has(key)){parent=job.folders.get(key);continue;}
            const existing=await $.getJSON('/workspace/folders');
            let match=existing.find(f=>f.name===entry.path[i]&&f.source===job.metadata.get('source')&&(f.parent_id||null)===parent);
            if(!match)match=await $.post('/workspace/folders',{name:entry.path[i],source:job.metadata.get('source'),parent_id:parent});
            parent=match.id;job.folders.set(key,parent);
        }
        return parent;
    }
    form.addEventListener('submit',async event=>{
        if(!batch)return;
        event.preventDefault();event.stopImmediatePropagation();if(busy)return;
        const job=batch;
        if(!job.metadata){job.metadata=new FormData(form);job.metadata.delete('file');}
        busy=true;$('#upload-error').prop('hidden',true);$('#upload-submit').prop('disabled',true);$('#upload-form input,#upload-form select,#folder-upload-open,#upload-open,#drive-new-folder').prop('disabled',true);
        let failed=false;
        try{
            for(const entry of job.entries){
                if(entry.done)continue;
                $('#upload-submit').text('Uploading '+(job.entries.filter(e=>e.done).length+1)+' / '+job.entries.length);
                const folder=await destination(job,entry);
                if(!entry.result){const data=new FormData();for(const [k,v] of job.metadata)data.set(k,v);data.set('title',entry.file.name);data.set('file',entry.file);entry.result=await $.ajax({url:'/workspace/documents',method:'POST',data,processData:false,contentType:false,headers:{'Idempotency-Key':entry.key}});}
                const detail=await $.getJSON('/workspace/documents/'+entry.result.document_id);
                if(detail.document.folder_id!==folder)await $.post('/workspace/drive/'+entry.result.document_id+'/action',{action:'move',revision:detail.document.revision,folder_id:folder});
                entry.done=true;
            }
            notice(job.entries.length+' files uploaded with their folder structure.');batch=null;$('#upload-dialog').dialog('close');$(document).trigger('drive:refresh');
        }catch(xhr){failed=true;$('#upload-error').text((xhr.responseJSON?.message||'Folder upload stopped.')+' '+job.entries.filter(e=>e.done).length+' of '+job.entries.length+' files completed. Retry to continue; completed files are preserved.').prop('hidden',false);}
        finally{busy=false;$('#upload-submit').prop('disabled',false).text(failed?'Retry remaining files':'Upload document');$('#folder-upload-open,#upload-open,#drive-new-folder').prop('disabled',false);if(!failed){$('#upload-form input,#upload-form select').prop('disabled',false);$('#upload-dialog').dialog('option','title','Upload document');}}
    },true);
});