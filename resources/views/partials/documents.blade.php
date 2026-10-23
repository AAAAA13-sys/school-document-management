@php $repositoryReadOnly=$viewer->isSuperAdmin(); @endphp
<section class="panel documents-panel">
    <div class="panel-header">
        <h2>{{ $section==='overview'?'Recent documents':($view==='trash'?'Trashed documents':($view==='shared'?'Shared documents':'All documents')) }}</h2>
        @if ($section==='overview')
            <a href="{{ $root }}/documents" class="text-link">View all documents <x-figma-icon name="arrow" :size="16" /></a>
        @endif
    </div>
    <div class="document-items {{ request('layout')==='grid'?'is-grid':'' }}" id="document-items">
        <div class="document-column-headings" role="row">
            <span class="sort-header" data-sort="title" role="columnheader" tabindex="0" aria-sort="none" title="Sort by document name">Document name <span class="sort-indicator" aria-hidden="true"></span></span>
            <span class="sort-header" data-sort="class" role="columnheader" tabindex="0" aria-sort="none" title="Sort by classification">Classification <span class="sort-indicator" aria-hidden="true"></span></span>
            <span class="sort-header" data-sort="date" role="columnheader" tabindex="0" aria-sort="none" title="Sort by date updated">Last updated <span class="sort-indicator" aria-hidden="true"></span></span>
            <span class="sort-header" data-sort="status" role="columnheader" tabindex="0" aria-sort="none" title="Sort by scan status">Scan status <span class="sort-indicator" aria-hidden="true"></span></span>
            <span role="columnheader"></span>
        </div>
        @foreach ($documents as $item)
            @php $v=$versions[$item->id]??null; $canMove=!$item->trashed_at && $viewer->can('document.manage',$item); @endphp
            <article
                class="document-row"
                data-document-id="{{ $item->id }}"
                data-revision="{{ $item->revision }}"
                data-folder-id="{{ $item->folder_id??'' }}"
                data-can-move="{{ $canMove?'true':'false' }}"
                data-title="{{ $item->title }}"
                data-reference="{{ $item->reference??'' }}"
                data-starred="{{ in_array($item->id,$starred)?'true':'false' }}"
                data-trashed="{{ $item->trashed_at?'true':'false' }}"
                data-status="{{ $v?->scan_status??'Pending' }}"
                data-source="{{ $item->source }}">
                <div class="file-cell">
                    <span class="file-icon">{{ strtoupper(pathinfo($v?->filename??'file',PATHINFO_EXTENSION)) }}</span>
                    <div>
                        <a class="document-title" href="/documents/{{ $item->id }}">{{ $item->title }}</a
                        ><small>{{ $v?number_format($v->size/1024,$v->size%1024===0?0:1).' KB · Version '.$v->number:'No version yet' }}</small>
                    </div>
                </div>
                <span class="classification class-{{ strtolower($item->classification) }}" data-class="{{ $item->classification }}">{{ $item->classification }}</span
                ><time
                    class="modified-date"
                    datetime="{{ $item->updated_at }}"
                    >{{ Illuminate\Support\Carbon::parse($item->updated_at)->format('M d, Y') }}</time
                >
                <span class="status-badge {{ strtolower($v?->scan_status??'Pending') }}" data-status="{{ $v?->scan_status??'Pending' }}">{{ $v?->scan_status??'Pending' }}</span>
                <div class="dropdown document-actions">
                    <button
                        class="icon-button document-menu-toggle"
                        type="button"
                        id="document-actions-{{ $item->id }}"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        aria-label="Actions for {{ $item->title }}">
                        <span class="menu-dots" aria-hidden="true">⋯</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="document-actions-{{ $item->id }}">
                        <li>
                            <a class="dropdown-item" href="/documents/{{ $item->id }}"><x-figma-icon name="file" />Open document</a>
                        </li>
                        @if ($viewer->can('document.star',$item))
                            <li>
                                <form
                                    method="post"
                                    action="/documents/{{ $item->id }}/star"
                                    data-document-id="{{ $item->id }}"
                                    data-document-title="{{ $item->title }}">
                                    @csrf
                                    @php
    $isStarred = in_array($item->id, $starred);
    $starVerb = $isStarred ? 'Remove star from' : 'Star';
@endphp
                                    {{-- D-9: aria-pressed reflects the real starred state so the toggle is announced as on/off. --}}
                                    <button
                                        type="submit"
                                        class="dropdown-item"
                                        aria-pressed="{{ $isStarred ? 'true' : 'false' }}"
                                        aria-label="{{ $starVerb }} {{ $item->title }}">
                                        <x-figma-icon name="star" />{{ $isStarred ? 'Remove star' : 'Star document' }}
                                    </button>
                                </form>
                            </li>
                        @endif
                        @if ($viewer->can('document.manage',$item))
                            @if ($canMove)
                                    <li>
                                        <button
                                            type="button"
                                            class="dropdown-item"
                                            data-bs-toggle="modal"
                                            data-bs-target="#move-document-modal"
                                            data-move-document="{{ $item->id }}">
                                            <x-figma-icon name="folder" />Move to folder
                                        </button>
                                    </li>
                                @endif
                                <li><hr class="dropdown-divider" /></li>
                                <li>
                                    <form
                                        method="post"
                                        action="/documents/{{ $item->id }}/{{ $item->trashed_at?'restore':'trash' }}"
                                        data-document-id="{{ $item->id }}"
                                        data-confirm="{{ $item->trashed_at?'Restore this document?':'Move this document to trash? All versions will be preserved.' }}">
                                        @csrf
                                        <input type="hidden" name="revision" value="{{ $item->revision }}" /><button
                                            type="submit"
                                            class="dropdown-item {{ $item->trashed_at?'':'text-danger' }}">
                                            <x-figma-icon name="trash" />{{ $item->trashed_at?'Restore document':'Move to trash' }}
                                        </button>
                                    </form>
                                </li>
                            @endif
                    </ul>
                </div>
            </article>
        @endforeach
    </div>
    <div class="empty-state" id="document-empty" @if (!$documents->isEmpty()) hidden @endif>
        <span class="empty-symbol"><x-figma-icon name="folder-card" :size="28" /></span>
        <h3>No documents here yet</h3>
        <p>{{ $repositoryReadOnly?'Try a different search or return to the workspace overview.':'Upload your first document, or try a different search.' }}</p>
        @if (!$repositoryReadOnly)
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#upload-modal">Upload document</button>
        @else
            <a class="btn btn-outline-secondary" href="/workspaces/{{ strtolower($source) }}">Workspace overview</a>
        @endif
    </div>
    @if ($documents->hasPages())
        <div class="panel-pagination">{{ $documents->links('pagination::bootstrap-5') }}</div>
    @endif
</section>

@push ('dialogs')
    @if (!$repositoryReadOnly)
        <div class="modal fade" id="move-document-modal" tabindex="-1" aria-labelledby="move-document-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form id="move-document-form">
                        <div class="modal-header">
                            <h2 class="modal-title" id="move-document-title">Move to folder</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="modal-subtitle" id="move-document-name"></p>
                            <label for="move-document-folder" class="form-label">Destination folder</label
                            ><select class="form-select" id="move-document-folder">
                                <option value="">Workspace root</option>
                                @foreach ($folders as $destination)
                                    <option value="{{ $destination->id }}" data-source="{{ $destination->source }}">{{ $destination->name }}</option>
                                @endforeach
                            </select>
                            <p id="move-document-feedback" class="mt-3 mb-0" role="status"></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button
                            ><button type="submit" class="btn btn-primary">Move document</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endpush
