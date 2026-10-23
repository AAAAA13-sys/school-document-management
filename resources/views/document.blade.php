@extends ('layout')
@php $viewer=$viewer??auth()->user(); @endphp
@section ('title',$doc->title)
@section ('content')
    <div data-scan-status-url="/documents/{{ $doc->id }}/scan-status"></div>
    <div class="page-heading">
        <div>
            <span class="eyebrow">{{ $source }} / DOCUMENT DETAILS</span>
            <h1 class="break-text" data-detail-title>{{ $doc->title }}</h1>
            <p>{{ $doc->classification }} · {{ $doc->reference ?: 'No reference' }} · Revision {{ $doc->revision }}</p>
        </div>
        @if ($editor && !$doc->trashed_at)
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#upload-modal">Upload new version</button>
        @endif
    </div>
    @if ($doc->trashed_at)
        <div class="alert alert-warning">This document is in trash. Restore it before editing or downloading.</div>
    @endif
    @if ($viewer->isSuperAdmin())
        <div class="alert alert-light border">
            <strong>Super admin · Read-only oversight</strong>
            <p>You can inspect this document and its history. Only repository staff can make changes.</p>
        </div>
    @endif
    <div class="detail-grid">
        <div class="detail-main">
            @php
        $previewVersion=$versions->first();
        $canPreview=$previewVersion && $previewVersion->scan_status==='Clean' && !$doc->trashed_at && in_array($previewVersion->mime,config('dms.preview_mimes'));
    @endphp
            <section class="panel preview-panel" aria-labelledby="document-preview-title">
                <div class="panel-header">
                    <h2 id="document-preview-title">Document preview</h2>
                    <span id="preview-version-label">{{ $canPreview?'Version '.$previewVersion->number:'' }}</span>
                </div>
                <p id="preview-unavailable" class="preview-unavailable" @if ($canPreview) hidden @endif>{{ $doc->trashed_at?'Preview is unavailable while this document is in trash.':($previewVersion && $previewVersion->scan_status!=='Clean'?'Preview becomes available after the security scan succeeds.':'Preview supports PDF, PNG, JPG, GIF, WebP, plain text, CSV and JSON. This format requires a download.') }}</p>
                <iframe
                    id="document-preview-frame"
                    name="document-preview-frame"
                    class="document-preview-frame"
                    title="Document content preview"
                    @if ($canPreview) src="{{ $previewVersion->mime==='application/pdf'?'/pdf-viewer.html?file='.urlencode('/files/'.$previewVersion->id.'/preview'):'/files/'.$previewVersion->id.'/preview' }}" @else hidden @endif></iframe>
            </section>

            <section class="panel version-panel">
                <h2>Version history</h2>
                <p class="section-description">Every earlier file is preserved. Downloads are checksum-verified.</p>
                <div class="version-list">
                    @foreach ($versions as $v)
                        <article class="version-row" data-version-id="{{ $v->id }}" data-status="{{ $v->scan_status }}">
                            <span class="file-icon">{{ strtoupper(pathinfo($v->filename,PATHINFO_EXTENSION)) }}</span>
                            <div class="version-info">
                                <h3>
                                    Version {{ $v->number }}
                                    @if ($loop->first) · Current @endif
                                </h3>
                                <p>{{ Illuminate\Support\Carbon::parse($v->created_at)->format('M d, Y · H:i') }} · {{ number_format($v->size/1024,0) }} KB</p>
                                @if ($v->scan_status==='Clean')
                                    <details class="checksum">
                                        <summary>SHA-256 verified</summary>
                                        <code>{{ $v->checksum }}</code>
                                    </details>
                                @else
                                    <span class="status-badge {{ strtolower($v->scan_status) }}">{{ $v->scan_status }}</span
                                    ><small
                                        class="scan-explanation"
                                        >{{ in_array($v->scan_status,['Pending','Failed'])?'Scanning runs automatically in the background.':'This version is quarantined and cannot be downloaded.' }}</small
                                    >
                                @endif
                            </div>
                            @if ($v->scan_status==='Clean' && !$doc->trashed_at)
                                @if (in_array($v->mime,config('dms.preview_mimes')))
                                    <a
                                        class="btn btn-outline-secondary"
                                        href="{{ $v->mime==='application/pdf'?'/pdf-viewer.html?file='.urlencode('/files/'.$v->id.'/preview'):'/files/'.$v->id.'/preview' }}"
                                        target="document-preview-frame"
                                        data-preview-version="Version {{ $v->number }}"
                                        >Preview</a
                                    >
                                @endif
                                <a class="btn btn-outline-secondary" href="/files/{{ $v->id }}" @if (!empty($frontendPreview)) data-demo-download @endif
                                    >Download</a
                                >
                            @else
                                <button type="button" class="btn btn-outline-secondary" disabled>Blocked</button>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
            <section class="panel comments-panel">
                <h2>Comments</h2>
                @if ($commenter && !$doc->trashed_at)
                    <form action="/documents/{{ $doc->id }}/comments" method="post" data-demo-action="comment">
                        @csrf
                        <label for="comment" class="form-label">Add a comment</label
                        ><textarea
                            id="comment"
                            name="body"
                            class="form-control"
                            rows="2"
                            maxlength="2000"
                            required
                            placeholder="Write a note for your colleagues…"></textarea>
                        <div class="comment-submit">
                            <button type="submit" class="btn btn-primary">Post comment</button><small><span id="comment-count">0</span> / 2,000</small>
                        </div>
                    </form>
                @endif
                <div id="comments-list">
                    @forelse ($comments as $comment)
                        <article class="comment">
                            <strong>{{ $comment->name }} · {{ Illuminate\Support\Carbon::parse($comment->created_at)->format('M d, H:i') }}</strong>
                            <p>{{ $comment->body }}</p>
                        </article>
                    @empty
                        <p class="text-muted comment-empty">No comments yet.</p>
                    @endforelse
                </div>
                <p class="section-description mt-4">Comments stay with the document. Up to 2,000 characters.</p>
            </section>
        </div>
        <aside class="detail-sidebar">
            <section class="panel">
                <h2>Document information</h2>
                <dl class="metadata">
                    @foreach (['Source'=>config('dms.sources.'.$source) ?? $source,'Classification'=>$doc->classification,'Reference'=>$doc->reference?:'—','Folder'=>$folders->firstWhere('id',$doc->folder_id)?->name??'Workspace root'] as $label=>$value)
                        <div>
                            <dt>{{ $label }}</dt>
                            <dd>{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
                @if ($editor && !$doc->trashed_at)
                    <button type="button" class="text-button" data-bs-toggle="modal" data-bs-target="#rename-modal">
                        {{ $owner?'Edit document details':'Rename document' }}
                    </button>
                @endif
            </section>
            <section class="panel">
                <h2>People with access</h2>
                <div id="share-list">
                    <div class="share-row"><strong>{{ $doc->owner_id===$viewer->id?$viewer->name:'Document owner' }}</strong><span>Owner</span></div>
                    @foreach ($shares->whereNull('revoked_at') as $share)
                        <div class="share-row" data-share-id="{{ $share->id }}">
                            <span>{{ $share->name }} · {{ $share->permission }}{{ $share->version_id?' · Pinned version':'' }}</span>
                            @if ($sharer)
                                <form action="/documents/{{ $doc->id }}/shares/{{ $share->id }}" method="post" data-demo-action="revoke-share">
                                    @csrf
                                    @method ('DELETE')
                                    <button type="submit" class="icon-button" aria-label="Revoke access for {{ $share->name }}">×</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($sharer && !$doc->trashed_at)
                    <button type="button" class="btn btn-outline-secondary mt-3" data-bs-toggle="modal" data-bs-target="#share-modal">Manage access</button>
                @endif
            </section>
            <section class="panel">
                <h2>Activity</h2>
                <div class="activity-list">
                    @forelse ($activity as $event)
                        <article class="activity-item">
                            <div>
                                <strong>{{ ucfirst(str_replace(['document.','share.','comment.','_'],['','','',' '],$event->action)) }}</strong
                                ><small>{{ Illuminate\Support\Carbon::parse($event->created_at)->format('M d, Y · H:i') }}</small>
                            </div>
                        </article>
                    @empty
                        <p class="text-muted">No activity yet.</p>
                    @endforelse
                </div>
            </section>
            @if ($owner)
                <form
                    method="post"
                    action="/documents/{{ $doc->id }}/{{ $doc->trashed_at?'restore':'trash' }}"
                    data-demo-action="{{ $doc->trashed_at?'restore':'trash' }}"
                    data-document-id="{{ $doc->id }}"
                    data-confirm="{{ $doc->trashed_at?'Restore this document?':'Move this document to trash? All versions are retained.' }}">
                    @csrf
                    <input type="hidden" name="revision" value="{{ $doc->revision }}" /><button
                        type="submit"
                        class="btn {{ $doc->trashed_at?'btn-primary':'btn-outline-danger' }}">
                        {{ $doc->trashed_at?'Restore document':'Move to trash' }}
                    </button>
                </form>
            @endif
        </aside>
    </div>
@endsection
@push ('dialogs')
    @if ($editor && !$doc->trashed_at)
        @include ('partials.upload')
        <div class="modal fade" id="rename-modal" tabindex="-1" aria-labelledby="rename-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" action="/documents/{{ $doc->id }}" data-demo-action="rename" data-ajax-move="true" data-document-id="{{ $doc->id }}">
                        @csrf
                        @method ('PATCH')
                        <input type="hidden" name="revision" value="{{ $doc->revision }}" />
                        <div class="modal-header">
                            <h2 class="modal-title" id="rename-title">Document details</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <label for="rename" class="form-label">Document title</label
                            ><input class="form-control mb-4" id="rename" name="title" maxlength="200" value="{{ $doc->title }}" required />
                            @if ($owner)
                                <label for="document-classification" class="form-label">Classification</label
                                ><select id="document-classification" name="classification" class="form-select mb-4">
                                    @foreach (($classifications ?? ['Internal','Confidential','Restricted']) as $classification)
                                        <option @selected ($doc->classification===$classification)>{{ $classification }}</option>
                                    @endforeach</select
                                ><label for="move-folder" class="form-label">Folder</label
                                ><select id="move-folder" name="folder_id" class="form-select">
                                    <option value="">Workspace root</option>
                                    @foreach ($folders as $f)
                                        <option value="{{ $f->id }}" @selected ($doc->folder_id===$f->id)>{{ $f->name }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="hidden" name="folder_id" value="{{ $doc->folder_id }}" />
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button
                            ><button type="submit" class="btn btn-primary">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
    @if ($sharer && !$doc->trashed_at)
        <div class="modal fade" id="share-modal" tabindex="-1" aria-labelledby="share-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form action="/documents/{{ $doc->id }}/shares" method="post" data-demo-action="share">
                        @csrf
                        <div class="modal-header">
                            <h2 class="modal-title" id="share-title">Share with school staff</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="modal-subtitle">{{ $doc->title }} · {{ $doc->classification }}</p>
                            <label for="share-search" class="form-label">Staff recipient</label
                            ><input
                                id="share-search"
                                type="text"
                                class="form-control mb-2"
                                placeholder="Search staff name or email…"
                                autocomplete="off"
                                aria-describedby="share-search-help" /><small id="share-search-help" class="text-muted d-block mb-2"
                                >Choose a named school account below.</small
                            ><label for="share-recipient" class="visually-hidden">Recipient account</label
                            ><select id="share-recipient" name="recipient_id" class="form-select mb-4" required>
                                <option value="">Choose a person</option>
                                @foreach ($recipients as $person)
                                    <option value="{{ $person->id }}">{{ $person->name }} ({{ $person->email }})</option>
                                @endforeach</select
                            ><label for="share-permission" class="form-label">Permission</label
                            ><select id="share-permission" name="permission" class="form-select mb-4">
                                <option>Viewer</option>
                                <option>Commenter</option>
                                <option>Editor</option>
                            </select>
                            <div class="permission-note">
                                <strong>Choose the right level of access</strong>
                                <p>Viewer: download. Commenter: download and comment.<br />Editor: rename and upload new versions.<br />Moving, deleting and resharing remain owner actions.</p>
                            </div>
                            <label for="share-version" class="form-label">Version access</label
                            ><select id="share-version" name="version_id" class="form-select mb-4">
                                <option value="">All versions, including future uploads</option>
                                @foreach ($versions as $v)
                                    <option value="{{ $v->id }}">Version {{ $v->number }} only</option>
                                @endforeach</select
                            ><label for="share-expiry" class="form-label">Expires <span>(optional)</span></label
                            ><input
                                id="share-expiry"
                                name="expires_at"
                                type="text"
                                class="form-control datepicker"
                                placeholder="Select a date"
                                autocomplete="off" />
                            <p class="security-note mt-4">Named staff only. No public or anonymous links.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button
                            ><button type="submit" class="btn btn-primary">Grant access</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endpush
