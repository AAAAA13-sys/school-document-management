<?php

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

// Synthetic view fixtures only. This file never reads or writes an application database.
return static function (string $source, string $section, ?string $documentId = null): array {
    $request = request();
    $page = max(1, (int) $request->query('page', 1));
    $paginate = static function (Collection $items, int $perPage = 12) use ($request, $page): LengthAwarePaginator {
        return new LengthAwarePaginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page, ['path'=>$request->url(), 'query'=>$request->query()]);
    };
    $catalog = [
        'EMS'=>[['Personnel records','Appointment letters','Faculty records','Office files'],['Employee information form','Appointment letter','Faculty service record','Signed appointment letter','Superseded office memo']],
        'PMS'=>[['Payroll registers','Salary adjustments','Tax documents','Office files'],['October payroll register','Salary adjustment memo','Tax withholding summary','Payroll supporting sheet','Superseded payroll memo']],
        'ES'=>[['Enrollment forms','Class lists','Student documents','Office files'],['Enrollment master list','Registration form','Class schedule record','Enrollment checklist','Superseded class list']],
        'OAS'=>[['Application files','Admission records','Submitted documents','Office files'],['Admission application form','Applicant document checklist','Admission record summary','Applicant supporting file','Superseded admission memo']],
    ];
    $allDocuments=collect();$allVersions=collect();$allFolders=collect();
    foreach ($catalog as $code=>$values) {
        $index = array_search($code,array_keys($catalog),true)+1;
        foreach ($values[0] as $j=>$name) $allFolders->push((object)['id'=>sprintf('%08d-1111-4000-8000-%012d',$index,$j+1),'parent_id'=>null,'owner_id'=>1,'source'=>$code,'name'=>$name,'revision'=>1,'document_count'=>[32,18,46,24][$j],'school_id'=>'PLP','campus'=>'Main']);
        foreach ($values[1] as $j=>$title) {
            $id=sprintf('%08d-0000-4000-8000-%012d',$index,$j+1);
            $allDocuments->push((object)['id'=>$id,'owner_id'=>1,'source'=>$code,'title'=>$title,'classification'=>$code==='PMS'?'Restricted':'Confidential','folder_id'=>$j<3?sprintf('%08d-1111-4000-8000-%012d',$index,1):null,'revision'=>3,'subject'=>'School office record','reference'=>$code.'-2026-'.str_pad((string)($j+1),3,'0',STR_PAD_LEFT),'category'=>'Office records','trashed_at'=>$j===4?'2026-10-15 09:00:00':null,'created_at'=>'2026-10-02 08:30:00','updated_at'=>'2026-10-'.(16-min($j,2)).' 09:40:00','school_id'=>'PLP','campus'=>'Main']);
            $allVersions->push((object)['id'=>sprintf('%08d-2222-4000-8000-%012d',$index,$j+1),'document_id'=>$id,'number'=>[3,1,2,1,2][$j],'filename'=>str_replace(' ','-',strtolower($title)).'.pdf','size'=>[245760,190464,1258291,102400,51200][$j],'mime'=>'application/pdf','scan_status'=>$j===3?($code==='OAS'?'Quarantined':($code==='PMS'?'Failed':'Pending')):'Clean','checksum'=>hash('sha256','synthetic-'.$id),'created_at'=>'2026-10-16 09:40:00']);
        }
    }
    $allFolders->push((object)['id'=>'00000001-1111-4000-8000-000000000005','parent_id'=>'00000001-1111-4000-8000-000000000001','owner_id'=>1,'source'=>'EMS','name'=>'2026 appointments','revision'=>1,'document_count'=>18,'school_id'=>'PLP','campus'=>'Main']);
    $doc=$documentId ? $allDocuments->firstWhere('id',$documentId) : null;
    if($documentId && !$doc) abort(404);
    if($doc) $source=$doc->source;
    $admin=$request->is('admin') || $request->is('admin/*');
    if($admin) $source='ALL';
    $viewer=new class(['name'=>$admin?'Administrator':'Maria Cruz','role'=>$admin?'super_admin':'admin','source'=>$admin?null:$source,'school_id'=>'PLP','campus'=>'Main','active'=>true]) extends User { public function sources(): array { return $this->isSuperAdmin() ? \App\Enums\Source::values() : [$this->source]; } };$viewer->id=1;
    $folders=$admin?$allFolders->values():$allFolders->where('source',$source)->values();
    $folder=$request->filled('folder')?$folders->firstWhere('id',$request->query('folder')):null;
    if($request->filled('folder')&&!$folder)abort(404);
    $parentFolder=$folder&&$folder->parent_id?$folders->firstWhere('id',$folder->parent_id):null;
    $view=in_array($request->query('view'),['mine','shared','starred','trash'],true)?$request->query('view'):'all';
    $documents=$allDocuments->when(!$admin,fn($q)=>$q->where('source',$source))->values();
    if(!$admin) {
        $documents=$documents->filter(fn($d)=>$view==='trash'?(bool)$d->trashed_at:!$d->trashed_at);
        if($section==='overview')$documents=$documents->take(3);
        if($folder)$documents=$documents->where('folder_id',$folder->id);
        if($view==='shared')$documents=$documents->take(2);
    }
    $versions=$allVersions->keyBy('document_id');
    $starred=$allDocuments->filter(fn($d)=>str_ends_with($d->id,'000000000001'))->pluck('id')->all();
    if($view==='starred')$documents=$documents->whereIn('id',$starred);
    $history=collect();
    for($i=0;$i<4;$i++)$history->push((object)['id'=>$i+1,'record_type'=>$source==='EMS'?'Employee':'Source record','record_id'=>'EMP-00'.($i===2?1:$i+1),'event_id'=>strtolower($source).'-event-0'.(321-$i),'revision'=>[8,4,6,3][$i],'operation'=>$i===3?'delete':'upsert','applied'=>$i!==2,'deleted'=>$i===3,'payload'=>json_encode(['record_id'=>'EMP-00'.($i===2?1:$i+1),'department'=>'Faculty','status'=>'active']),'created_at'=>'2026-10-16 10:'.(42-$i*5).':00','updated_at'=>'2026-10-16 10:'.(42-$i*5).':00']);
    $accounts=collect([(object)['id'=>1,'name'=>'Administrator','email'=>'admin@example.test','role'=>'super_admin','source'=>'EMS','active'=>true],(object)['id'=>2,'name'=>'Maria Cruz','email'=>'maria.cruz@example.test','role'=>'admin','source'=>'EMS','active'=>true],(object)['id'=>3,'name'=>'Paolo Reyes','email'=>'paolo.reyes@example.test','role'=>'admin','source'=>'PMS','active'=>true],(object)['id'=>4,'name'=>'Ana Santos','email'=>'ana.santos@example.test','role'=>'admin','source'=>'ES','active'=>true],(object)['id'=>5,'name'=>'Luis Garcia','email'=>'luis.garcia@example.test','role'=>'staff','source'=>'EMS','active'=>false]]);
    $connectors=collect();foreach(array_keys($catalog) as $i=>$code)$connectors->push((object)['id'=>$i+1,'source'=>$code,'name'=>$code.' · Main campus','active'=>true,'last_seen_at'=>'2026-10-16 10:'.(42-$i*4).':00','created_at'=>'2026-10-01 09:00:00']);
    $audits=collect();foreach(['document.uploaded','document.version_stored','share.granted','history.received','account.updated'] as $i=>$action)$audits->push((object)['id'=>$i+1,'action'=>$action,'source'=>['EMS','PMS','EMS','ES','—'][$i],'actor_id'=>$i===3?null:2,'actor_name'=>['Maria Cruz','Payroll office','Administrator','ES connector','Administrator'][$i],'connector_id'=>$i===3?3:null,'document_id'=>$i<3?$allDocuments[0]->id:null,'details'=>json_encode(['action'=>$action,'scope'=>'PLP / Main']),'created_at'=>'2026-10-16 10:'.(42-$i*5).':00']);
    $scans=$allVersions->where('scan_status','!=','Clean')->map(function($v)use($allDocuments){$d=$allDocuments->firstWhere('id',$v->document_id);return (object)(get_object_vars($v)+['title'=>$d->title,'source'=>$d->source]);})->values();
    $subsystemStatuses=collect([
        (object)['code'=>'OAS','name'=>'Online Admission','department'=>'Admissions Office','status'=>'Online','latency'=>24,'endpoint'=>'/api/v1/applicant-packets','total'=>1028,'sync_mode'=>'Realtime Push','queue'=>12,'active'=>true],
        (object)['code'=>'ES','name'=>'Enrollment System','department'=>'Office of the Registrar','status'=>'Online','latency'=>18,'endpoint'=>'/api/v1/documents','total'=>1604,'sync_mode'=>'Event Stream','queue'=>8,'active'=>true],
        (object)['code'=>'EMS','name'=>'Cashier & Staff','department'=>'Cashier / Accounts','status'=>'Online','latency'=>32,'endpoint'=>'/api/v1/history','total'=>1248,'sync_mode'=>'Batch CAS','queue'=>5,'active'=>true],
        (object)['code'=>'PMS','name'=>'Payroll Management','department'=>'Accounting & Payroll','status'=>'Online','latency'=>15,'endpoint'=>'/api/v1/documents','total'=>982,'sync_mode'=>'Encrypted Outbox','queue'=>3,'active'=>true],
        (object)['code'=>'DMS','name'=>'Document Storage','department'=>'Central Repository','status'=>'Online','latency'=>4,'endpoint'=>'/api/v1/documents','total'=>4862,'sync_mode'=>'Private Store','queue'=>7,'active'=>true],
    ]);
    $applicantQueue=collect([
        (object)['applicant_id'=>'OAS-2026-0042','name'=>'Elena Santos','program'=>'BS Computer Science','files_count'=>3,'documents_summary'=>'PSA Birth Certificate, Form 137, Exam Ticket','verified'=>true,'admitted'=>true,'released'=>true,'updated_at'=>'2026-10-25 14:30:00'],
        (object)['applicant_id'=>'OAS-2026-0043','name'=>'Marco Bautista','program'=>'BS Information Technology','files_count'=>3,'documents_summary'=>'PSA Birth Certificate, Form 138, Parent Affidavit','verified'=>false,'admitted'=>false,'released'=>false,'updated_at'=>'2026-10-25 15:15:00'],
        (object)['applicant_id'=>'OAS-2026-0044','name'=>'Chloe Mendoza','program'=>'BS Nursing','files_count'=>4,'documents_summary'=>'PSA Birth Certificate, Form 137, Good Moral','verified'=>true,'admitted'=>true,'released'=>true,'updated_at'=>'2026-10-25 16:00:00'],
        (object)['applicant_id'=>'OAS-2026-0045','name'=>'Rico De Guzman','program'=>'BS Accountancy','files_count'=>2,'documents_summary'=>'Form 138, Exam Ticket','verified'=>false,'admitted'=>false,'released'=>false,'updated_at'=>'2026-10-25 16:45:00'],
    ]);
    $departmentTransactions=collect([
        (object)['id'=>9041,'source'=>'EMS','department'=>'Cashier','record_type'=>'payment_receipt','record_id'=>'CR-2026-9041','description'=>'Tuition Deposit & Registration Receipt (₱14,500.00)','operation'=>'upsert','revision'=>1,'applied'=>true,'status'=>'Clean','created_at'=>'2026-10-25 16:40:00'],
        (object)['id'=>9040,'source'=>'PMS','department'=>'Payroll','record_type'=>'payslip_batch','record_id'=>'PAY-2026-10-B','description'=>'October 2026 Faculty Payroll Summary Register','operation'=>'upsert','revision'=>2,'applied'=>true,'status'=>'Clean','created_at'=>'2026-10-25 16:20:00'],
        (object)['id'=>9039,'source'=>'OAS','department'=>'Admission','record_type'=>'applicant_packet','record_id'=>'PKT-2026-0118','description'=>'Verified Admitted Applicant Packet Batch Release','operation'=>'release','revision'=>1,'applied'=>true,'status'=>'Clean','created_at'=>'2026-10-25 15:55:00'],
        (object)['id'=>9038,'source'=>'ES','department'=>'Enrollment','record_type'=>'class_roster','record_id'=>'ENR-2026-0338','description'=>'Term 1 Official Enrollee Master List Certification','operation'=>'upsert','revision'=>3,'applied'=>true,'status'=>'Clean','created_at'=>'2026-10-25 15:10:00'],
        (object)['id'=>9037,'source'=>'EMS','department'=>'Cashier','record_type'=>'clearance_slip','record_id'=>'CLR-2026-4412','description'=>'Student Financial Clearance Endorsement Slip','operation'=>'upsert','revision'=>1,'applied'=>true,'status'=>'Clean','created_at'=>'2026-10-25 14:35:00'],
    ]);
    $ingestionStream=collect([
        (object)['id'=>'00000004-0000-4000-8000-000000000001','source'=>'OAS','department'=>'Admission','title'=>'Admission application form','filename'=>'admission-application-form.pdf','classification'=>'Confidential','scan_status'=>'Clean','version_number'=>1,'size'=>245760,'checksum'=>'a591a6d40bf420404a011733cfb7b190d62c65bf0bcda32b57b277d9ad9f146e','updated_at'=>'2026-10-25 16:42:00'],
        (object)['id'=>'00000002-0000-4000-8000-000000000001','source'=>'PMS','department'=>'Payroll','title'=>'October payroll register','filename'=>'october-payroll-register.pdf','classification'=>'Restricted','scan_status'=>'Clean','version_number'=>3,'size'=>190464,'checksum'=>'b729f8a31e12487290118274dfc8c190d62c65bf0bcda32b57b277d9ad9f112a','updated_at'=>'2026-10-25 16:20:00'],
        (object)['id'=>'00000001-0000-4000-8000-000000000002','source'=>'EMS','department'=>'Cashier','title'=>'Official Cashier Deposit Receipt #9041','filename'=>'cashier-receipt-9041.pdf','classification'=>'Confidential','scan_status'=>'Clean','version_number'=>1,'size'=>1258291,'checksum'=>'c418e2f51a44569018273645dfb9c190d62c65bf0bcda32b57b277d9ad9f984c','updated_at'=>'2026-10-25 15:50:00'],
        (object)['id'=>'00000003-0000-4000-8000-000000000001','source'=>'ES','department'=>'Enrollment','title'=>'Enrollment master list','filename'=>'enrollment-master-list.pdf','classification'=>'Confidential','scan_status'=>'Clean','version_number'=>2,'size'=>512000,'checksum'=>'d928c1b42e77651029384756dfa8c190d62c65bf0bcda32b57b277d9ad9f456e','updated_at'=>'2026-10-25 15:15:00'],
        (object)['id'=>'00000001-0000-4000-8000-000000000003','source'=>'EMS','department'=>'Faculty','title'=>'Faculty service record','filename'=>'faculty-service-record.pdf','classification'=>'Internal','scan_status'=>'Clean','version_number'=>1,'size'=>102400,'checksum'=>'e119b3c45f88762039485767dfc7c190d62c65bf0bcda32b57b277d9ad9f789d','updated_at'=>'2026-10-25 14:40:00'],
    ]);
    $data=['workspaceLabels'=>\App\Enums\Source::labels(),'viewer'=>$viewer,'source'=>$source,'section'=>$section,'root'=>$admin?'/admin':'/workspaces/'.strtolower($source),'frontendPreview'=>true,'view'=>$view,'documents'=>$paginate($documents),'versions'=>$versions,'folders'=>$folders,'folder'=>$folder,'parentFolder'=>$parentFolder,'starred'=>$starred,'history'=>$paginate($history),'accounts'=>$accounts,'connectors'=>$connectors,'audits'=>$paginate($audits),'scans'=>$paginate($scans),'subsystemStatuses'=>$subsystemStatuses,'applicantQueue'=>$applicantQueue,'departmentTransactions'=>$departmentTransactions,'ingestionStream'=>$ingestionStream,'workspaceCounts'=>['EMS'=>1248,'PMS'=>982,'ES'=>1604,'OAS'=>1028],'stats'=>$admin?['documents'=>4862,'accounts'=>148,'connectors'=>4,'blocked'=>7,'folders'=>5,'shared'=>24]:['documents'=>1248,'shared'=>86,'history'=>3412,'blocked'=>4]];
    $data+=['canManageFolder'=>false,'folderShares'=>collect(),'folderRecipients'=>collect()];
    if($doc){
        $latest=$allVersions->firstWhere('document_id',$doc->id);$detailVersions=collect();
        for($v=3;$v>=1;$v--){$copy=clone $latest;$copy->id=substr($latest->id,0,-1).$v;$copy->number=$v;$copy->size=[1=>233472,2=>237568,3=>245760][$v];$copy->created_at=[1=>'2026-10-02 08:30:00',2=>'2026-10-10 14:15:00',3=>'2026-10-16 09:40:00'][$v];$detailVersions->push($copy);}
        $data+=['doc'=>$doc,'owner'=>true,'editor'=>true,'commenter'=>true,'sharer'=>true,'comments'=>collect([(object)['name'=>'Maria Cruz','body'=>'Updated with the latest signed information form. Please use version 3 for your records.','created_at'=>'2026-10-16 10:05:00']]),'shares'=>collect([(object)['id'=>1,'name'=>'Registrar office','permission'=>'Viewer','version_id'=>null,'revoked_at'=>null],(object)['id'=>2,'name'=>'Payroll officer','permission'=>'Commenter','version_id'=>null,'revoked_at'=>null]]),'recipients'=>$accounts->where('id','!=',1)->where('active',true),'activity'=>$audits->take(3)];
        $data['versions']=$detailVersions;
    }
    return $data;
};
