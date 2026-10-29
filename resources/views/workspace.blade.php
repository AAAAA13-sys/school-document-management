@extends ('layout')
@php $viewer=$viewer??auth()->user(); @endphp
@section ('title',(($workspaceLabels ?? App\Services\WorkspaceRegistry::labels($viewer))[$source] ?? $source).' · '.ucfirst($section))
@section ('content')
    @php
    $root='/workspaces/'.strtolower($source);
    $readOnly=false;
    $canWrite=$viewer->can('documents.write');
    $canManageFolders=$viewer->can('folders.manage');
    $view=$view??'all';
    $titles=['all'=>'Documents','mine'=>'My documents','shared'=>'Shared with me','starred'=>'Starred documents','trash'=>'Trash'];
    $title=match($section){'overview'=>'Your workspace, organized.','history'=>'Received history','snapshots'=>'Current records',default=>$titles[$view]};
    $subtitle=match($section){'overview'=>'Keep every document connected, protected, and easy to find.','history'=>'Every source event is preserved, including events that arrive out of order.','snapshots'=>'The latest received state of each source record.',default=>'Your files, organized and securely stored.'};
@endphp
    <div class="page-heading">
        <div>
            <span class="eyebrow">{{ $source }}{{ $section==='overview'?' WORKSPACE':' / '.strtoupper($title) }}</span>
            <h1>{{ $title }}</h1>
            <p>{{ $subtitle }}</p>
        </div>
        @if ($canWrite && in_array($section,['overview','documents']))
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#upload-modal">Upload document</button>
        @endif
    </div>
    @if ($readOnly)
        <div class="alert alert-light border">
            <strong>Super admin · Read-only oversight</strong>
            <p>Inspect this repository, document versions and received history. Repository changes remain with its staff.</p>
        </div>
    @endif
    @if ($section==='overview')
        @include ('partials.stats')
    @endif
    {{-- Library views live in the sidebar under MY LIBRARY, so they are not repeated here.
         Sidebar "Documents" is the all-views landing, which covers the missing "All documents" tab. --}}
    @if (in_array($section,['overview','documents']))
        @if ($view==='shared')
            <div class="info-strip">
                <x-figma-icon name="link-notice" :size="24" />
                <div>
                    <strong>Access follows the owner’s grant</strong>
                    <p>Viewers can download. Commenters can also comment. Editors can rename and upload new versions.</p>
                </div>
            </div>
        @elseif ($view==='trash')
            <div class="info-strip">
                <x-figma-icon name="link-notice" :size="24" />
                <div>
                    <strong>Your history is preserved</strong>
                    <p>Trashing blocks downloads and editing. All earlier versions remain safely stored.</p>
                </div>
            </div>
        @endif
        <form class="document-toolbar" method="get" data-search-form>
            <div class="search-field">
                <x-figma-icon name="search" /><label class="visually-hidden" for="document-search">Search documents or reference</label
                ><input
                    type="search"
                    id="document-search"
                    name="q"
                    value="{{ request('q') }}"
                    maxlength="200"
                    placeholder="Search documents or reference…"
                    autocomplete="off" />
                @if ($canManageFolders)
                    <button
                        type="button"
                        class="icon-button mobile-folder-button"
                        data-bs-toggle="modal"
                        data-bs-target="#folder-modal"
                        aria-label="New folder">
                        <x-figma-icon name="more" />
                    </button>
                @endif
            </div>
            <label class="visually-hidden" for="document-class">Classification</label
            ><select id="document-class" name="classification" class="form-select filter-select">
                <option value="">All classifications</option>
                <option>Confidential</option>
                <option>Restricted</option>
                <option>Internal</option></select
            ><label class="visually-hidden" for="document-status">Scan status</label
            ><select id="document-status" name="status" class="form-select filter-select">
                <option value="">All scan statuses</option>
                <option value="Clean">Clean</option>
                <option value="Pending">Pending</option>
                <option value="Failed">Failed</option>
                <option value="Quarantined">Quarantined</option>
            </select>
            <input type="hidden" name="view" value="{{ $view }}" />
            @if ($folder)
                <input type="hidden" name="folder" value="{{ $folder->id }}" />
            @endif
            <div class="toolbar-actions">
                @if ($canManageFolders)
                    <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#folder-upload-modal">Upload folder</button>
                @endif
                @if ($canManageFolders)
                    <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#folder-modal">New folder</button>
                @endif
                <div class="view-toggle" role="group" aria-label="Document layout">
                    <button
                        type="button"
                        data-layout="list"
                        class="{{ request('layout','list')==='list'?'selected':'' }}"
                        aria-label="List view"
                        aria-pressed="{{ request('layout','list')==='list'?'true':'false' }}">
                        <x-figma-icon name="list" /></button
                    ><button
                        type="button"
                        data-layout="grid"
                        class="{{ request('layout')==='grid'?'selected':'' }}"
                        aria-label="Grid view"
                        aria-pressed="{{ request('layout')==='grid'?'true':'false' }}">
                        <x-figma-icon name="grid" />
                    </button>
                </div>
            </div>
        </form>
        @if ($folder)
            <div class="folder-breadcrumb">
                <a href="{{ $root }}/documents">My drive</a><span>/</span>
                @if ($folder->parent_id)
                    <a href="{{ $root }}/documents?folder={{ $folder->parent_id }}">Parent folder</a
                    ><span>/</span>
                @endif
                <strong>{{ $folder->name }}</strong>
                @if ($canManageFolder)
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="modal" data-bs-target="#folder-edit-modal">
                        Edit folder
                    </button>

                @endif
            </div>
        @endif
        @unless (in_array($view,['shared','starred','trash']))
            <div class="folder-grid">
                @if ($folder)
                    @php
                        // Every open folder offers a way out. A nested folder goes to its
                        // parent; a top-level folder goes to the drive root, so no folder
                        // view is ever a dead end.
                        $upHref=$parentFolder?$root.'/documents?folder='.$parentFolder->id:$root.'/documents';
                        $upName=$parentFolder?$parentFolder->name:'My drive';
                        $upSubtitle=$parentFolder?'Parent folder':'Drive root';
                    @endphp
                    <a
                        class="folder-card folder-parent"
                        href="{{ $upHref }}"
                        data-folder-id="{{ $parentFolder?->id }}"
                        data-folder-name="{{ $upName }}"
                        data-document-count="{{ $parentFolder->document_count??0 }}"
                        data-source="{{ $parentFolder?->source ?? $source }}"
                        aria-label="Go up to {{ $upName }}"
                        ><span class="folder-parent-icon" aria-hidden="true"
                            ><x-figma-icon
                                name="folder-card"
                                :size="28" /><svg class="folder-parent-arrow" viewBox="0 0 24 24" width="18" height="18" fill="none"><path d="M12 19V5M12 5l-6 6M12 5l6 6" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" /></svg></span
                        ><span><strong>Up to {{ $upName }}</strong><small>{{ $upSubtitle }}</small></span></a
                    >
                @endif
                @foreach ($folders->where('parent_id',$folder?->id) as $f)
                    <a
                        class="folder-card"
                        data-folder-id="{{ $f->id }}"
                        data-folder-name="{{ $f->name }}"
                        data-document-count="{{ $f->document_count??0 }}"
                        data-source="{{ $f->source }}"
                        href="{{ $root }}/documents?folder={{ $f->id }}"
                        ><x-figma-icon name="folder-card" :size="28" /><span
                            ><strong>{{ $f->name }}</strong><small>{{ $f->document_count??0 }} documents</small></span
                        ></a
                    >
                @endforeach
            </div>
        @endunless
        @include ('partials.documents')
    @else
        <div class="info-strip">
            <x-figma-icon name="link-notice" :size="24" />
            <div>
                <strong>{{ $section==='history'?'Received history is evidence':'Your ERP remains the system of record' }}</strong>
                <p>{{ $section==='history'?'Applied events update the current record. Older revisions remain stored without changing the latest snapshot.':'These snapshots are read-only. Make business-record changes in the originating ERP.' }}</p>
            </div>
        </div>
        <form method="get" class="document-toolbar" data-search-form>
            <div class="search-field">
                <x-figma-icon name="search" /><label class="visually-hidden" for="record-search">Search record ID</label
                ><input type="search" id="record-search" name="q" value="{{ request('q') }}" placeholder="Search by record ID or event ID…" maxlength="200" />
            </div>
        </form>
        <section class="panel">
            <div class="panel-header">
                <h2>{{ $section==='history'?'Received events':'Latest source snapshots' }}</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-zebra records-table" data-filter-table>
                    <thead>
                        <tr>
                            <th scope="col">Record</th>
                            @if ($section==='history')
                                <th scope="col">Event ID</th>
                            @endif
                            <th scope="col">Revision</th>
                            <th scope="col">{{ $section==='history'?'Operation':'State' }}</th>
                            <th scope="col">{{ $section==='history'?'Result':'Last received' }}</th>
                            <th scope="col">Evidence</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($history as $entry)
                            <tr>
                                <td><strong>{{ $entry->record_id }}</strong><small>{{ $entry->record_type }}</small></td>
                                @if ($section==='history')
                                    <td>{{ $entry->event_id }}</td>
                                @endif
                                <td>r{{ $entry->revision }}</td>
                                <td>{{ $section==='history'?ucfirst($entry->operation):($entry->deleted?'Tombstone':'Current') }}</td>
                                <td>
                                    {{ $section==='history'?($entry->applied?'Applied':'Stored only'):Illuminate\Support\Carbon::parse($entry->updated_at)->format('M d, Y · H:i') }}
                                </td>
                                <td>
                                    <button
                                        type="button"
                                        class="text-button"
                                        data-payload="{{ $entry->payload }}"
                                        data-record="{{ $entry->record_id }}"
                                        data-revision="{{ $entry->revision }}"
                                        data-bs-toggle="modal"
                                        data-bs-target="#payload-modal">
                                        View payload
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr class="is-empty">
                                <td colspan="6">
                                    <div class="empty-state">
                                        <h3>No records received yet</h3>
                                        <p>Your source system’s events will appear here once connected.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($history->hasPages())
                <div class="panel-pagination">{{ $history->links('pagination::bootstrap-5') }}</div>
            @endif
        </section>
        @if ($history->isNotEmpty())
            <section class="panel evidence-panel">
                <h2>Received versus current state</h2>
                <div class="evidence-grid">
                    <div>
                        <span class="eyebrow">RECEIVED EVIDENCE</span>
                        <p>Every accepted event retains its original payload and revision.</p>
                    </div>
                    <div>
                        <span class="eyebrow">CURRENT SNAPSHOT</span>
                        <p>Only a newer revision changes the current record. Earlier history stays intact.</p>
                    </div>
                </div>
            </section>
        @endif
    @endif
@endsection
@push ('dialogs')
    @if ($canWrite && in_array($section,['overview','documents']))
        @include ('partials.upload')
        @if ($canManageFolders)
            @include ('partials.folder-upload')
            <div class="modal fade" id="folder-modal" tabindex="-1" aria-labelledby="folder-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form action="/folders" method="post">
                            @csrf
                            <div class="modal-header">
                                <h2 class="modal-title" id="folder-title">New folder</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p class="modal-subtitle">Organize documents inside your workspace.</p>
                                <input type="hidden" name="source" value="{{ $source }}" /><label for="folder-name" class="form-label">Folder name</label
                                ><input
                                    id="folder-name"
                                    class="form-control mb-4"
                                    name="name"
                                    maxlength="150"
                                    required
                                    placeholder="e.g. 2026 appointments" /><label class="form-label" for="folder-parent">Parent folder</label
                                ><select id="folder-parent" name="parent_id" class="form-select">
                                    <option value="">My drive (root)</option>
                                    @foreach ($folders as $f)
                                        <option value="{{ $f->id }}" @selected ($folder?->id===$f->id)>{{ $f->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button
                                ><button type="submit" class="btn btn-primary">Create folder</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            @if ($folder)
                <div class="modal fade" id="folder-edit-modal" tabindex="-1" aria-labelledby="folder-edit-title" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <form action="/folders/{{ $folder->id }}" method="post">
                                @csrf
                                @method ('PATCH')
                                <input type="hidden" name="revision" value="{{ $folder->revision }}" />
                                <div class="modal-header">
                                    <h2 class="modal-title" id="folder-edit-title">Edit folder</h2>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <label for="folder-edit-name" class="form-label">Name</label
                                    ><input
                                        id="folder-edit-name"
                                        name="name"
                                        class="form-control mb-4"
                                        value="{{ $folder->name }}"
                                        maxlength="150"
                                        required /><label for="folder-edit-parent" class="form-label">Move to</label
                                    ><select id="folder-edit-parent" name="parent_id" class="form-select">
                                        <option value="">My drive (root)</option>
                                        @foreach ($folders->where('id','!=',$folder->id) as $f)
                                            <option value="{{ $f->id }}" @selected ($folder->parent_id===$f->id)>{{ $f->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button
                                    ><button type="submit" class="btn btn-primary">Save folder</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    @else
        @include ('partials.payload-modal')
    @endif
@endpush
