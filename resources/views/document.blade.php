@extends ('layout')
@php $viewer=$viewer??auth()->user(); @endphp
@section ('title',$doc->title)
@section ('content')
    @php
        $previewVersion=$versions->first();
        $canDownload=$previewVersion && $previewVersion->scan_status==='Clean' && !$doc->trashed_at;
        $canPreview=$canDownload && in_array($previewVersion->mime,config('dms.preview_mimes'));
        $sourceName=$source==='DMS'?'My workspace':(($workspaceLabels ?? App\Services\WorkspaceRegistry::labels($viewer))[$source] ?? $source);
        $ownerName=$doc->owner_id===$viewer->id?$viewer->name:($doc->owner_name??($doc->owner_id?'Document owner':'ERP connector'));
        $detailRoot=$source==='DMS'?'/my-workspace':'/workspaces/'.strtolower(in_array($source,$viewer->sources())?$source:$viewer->source);
        $detailClose=$detailRoot.'/documents'.($doc->folder_id?'?folder='.$doc->folder_id:'');
        if($doc->owner_id!==$viewer->id && ($viewer->role==='staff' || $source==='DMS' || !in_array($source,$viewer->sources()))) $detailClose=$detailRoot.'/documents?view=shared';
    @endphp
    <div data-scan-status-url="/documents/{{ $doc->id }}/scan-status"></div>
    <article class="document-detail-card" aria-labelledby="file-detail-title">
        <header class="file-detail-heading">
            <h1 class="break-text" id="file-detail-title" data-detail-title>{{ $doc->title }}</h1>
            <a class="btn btn-outline-secondary file-detail-close" href="{{ $detailClose }}" aria-label="Close document details">×</a>
        </header>
        <div class="file-detail-state">
            <span
                class="status-badge {{ strtolower($doc->trashed_at?'neutral':($previewVersion->scan_status??'neutral')) }}"
                >{{ $doc->trashed_at?'In trash':($previewVersion->scan_status??'No file') }}</span
            >
            <span>{{ $sourceName }}</span><span aria-hidden="true">·</span><span>{{ $doc->classification }}</span>
        </div>
        @if ($doc->trashed_at)
            <div class="alert alert-warning">This document is in trash. Restore it before editing or downloading.</div>
        @endif
        @include ('partials.memo-publication')
        <div id="document-tabs" class="file-detail-tabs">
            <ul class="file-detail-tab-list" aria-label="Document sections">
                <li><a href="#file-details">Details</a></li>
                <li><a href="#file-versions">Version history</a></li>
                <li><a href="#file-preview">Preview</a></li>
                <li><a href="#file-shares">Shares</a></li>
            </ul>
            <section id="file-details" aria-label="Document details">
                <dl class="file-detail-metadata">
                    <div>
                        <dt>Title</dt>
                        <dd data-detail-title>{{ $doc->title }}</dd>
                    </div>
                    <div>
                        <dt>Subject</dt>
                        <dd>{{ $doc->subject ?: 'Not provided' }}</dd>
                    </div>
                    <div>
                        <dt>Reference</dt>
                        <dd>{{ $doc->reference ?: 'Not provided' }}</dd>
                    </div>
                    <div>
                        <dt>Source</dt>
                        <dd>{{ $sourceName }}<span class="metadata-secondary">Category: {{ $doc->category ?: 'Not provided' }}</span></dd>
                    </div>
                    <div>
                        <dt>Classification</dt>
                        <dd>
                            {{ $doc->classification }}
                            @if (!empty($grant?->expires_at))
                                <span class="metadata-secondary"
                                    >Your access expires: {{ Illuminate\Support\Carbon::parse($grant->expires_at)->format('M d, Y · H:i') }}</span
                                >
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Checksum</dt>
                        <dd>
                            <code>{{ $previewVersion->checksum??'No file version' }}</code
                            ><small>Stored SHA-256 · checked again when previewed or downloaded</small>
                        </dd>
                    </div>
                    <div>
                        <dt>Created</dt>
                        <dd>
                            {{ Illuminate\Support\Carbon::parse($doc->created_at)->format('M d, Y · H:i') }}<span class="metadata-secondary"
                                >Modified: {{ Illuminate\Support\Carbon::parse($doc->updated_at)->format('M d, Y · H:i') }}</span
                            >
                        </dd>
                    </div>
                    <div>
                        <dt>Owner</dt>
                        <dd>{{ $ownerName }}</dd>
                    </div>
                    <div>
                        <dt>Folder</dt>
                        <dd>{{ $folders->firstWhere('id',$doc->folder_id)?->name??($doc->folder_id?'Shared folder':'My drive') }}</dd>
                    </div>
                </dl>
                @if ($editor && !$doc->trashed_at)
                    <button type="button" class="btn btn-outline-secondary mt-3" data-bs-toggle="modal" data-bs-target="#rename-modal">
                        {{ $owner?'Edit document details':'Rename document' }}
                    </button>
                @endif
                <details class="file-detail-supplement">
                    <summary>Comments <span>{{ $comments->count() }}</span></summary>
                    <section class="panel comments-panel">
                        <h2>Comments</h2>
                        @if ($commenter && !$doc->trashed_at)
                            <form action="/documents/{{ $doc->id }}/comments" method="post">
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
                </details>
                <details class="file-detail-supplement">
                    <summary>Activity</summary>
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
                </details>
            </section>
            <section id="file-versions" aria-label="Version history">
                <div class="file-detail-section-heading">
                    <p>Every earlier file is preserved. Downloads are checksum-verified.</p>
                    @if ($editor && !$doc->trashed_at)
                        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#upload-modal">Upload new version</button>
                    @endif
                </div>
                <div class="table-responsive file-version-table">
                    <table class="table table-striped align-middle">
                        <caption class="visually-hidden">
                            Stored document versions and available actions
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Version</th>
                                <th scope="col">Created</th>
                                <th scope="col">File</th>
                                <th scope="col">Security</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($versions as $v)
                                <tr class="version-row" data-version-id="{{ $v->id }}" data-status="{{ $v->scan_status }}">
                                    <th scope="row">Version {{ $v->number }}<small>{{ $loop->first?'Latest accessible':'Earlier version' }}</small></th>
                                    <td>{{ Illuminate\Support\Carbon::parse($v->created_at)->format('M d, Y · H:i') }}</td>
                                    <td><span class="break-text">{{ $v->filename }}</span><small>{{ number_format($v->size/1024,0) }} KB</small></td>
                                    <td>
                                        <span class="status-badge {{ strtolower($v->scan_status) }}">{{ $v->scan_status }}</span>
                                        <details class="checksum">
                                            <summary>{{ $v->scan_status==='Clean'?'SHA-256 verified':'Stored SHA-256' }}</summary>
                                            <code>{{ $v->checksum }}</code>
                                        </details>
                                        @if ($v->scan_status!=='Clean')
                                            <small
                                                class="scan-explanation"
                                                >{{ in_array($v->scan_status,['Pending','Failed'])?'Scanning runs automatically in the background.':'This version is quarantined and cannot be downloaded.' }}</small
                                            >
                                        @endif
                                    </td>
                                    <td>
                                        <div class="file-version-actions">
                                            @if ($v->scan_status==='Clean' && !$doc->trashed_at)
                                                @if (in_array($v->mime,config('dms.preview_mimes')))
                                                    <a
                                                        class="btn btn-outline-secondary"
                                                        href="{{ $v->mime==='application/pdf'?'/pdf-viewer.html?file='.urlencode('/files/'.$v->id.'/preview'):'/files/'.$v->id.'/preview' }}"
                                                        target="document-preview-frame"
                                                        data-preview-version="Version {{ $v->number }}"
                                                        data-version-download="/files/{{ $v->id }}"
                                                        >Preview</a
                                                    >
                                                @endif
                                                <a class="btn btn-outline-secondary" href="/files/{{ $v->id }}">Download</a>
                                            @else
                                                <button type="button" class="btn btn-outline-secondary" disabled>Blocked</button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            <section id="file-preview" aria-label="Document preview">
                <section class="preview-panel" aria-labelledby="document-preview-title">
                    <div class="panel-header">
                        <h2 id="document-preview-title">Document preview</h2>
                        <span id="preview-version-label">{{ $canPreview?'Version '.$previewVersion->number:'' }}</span>
                    </div>
                    <p id="preview-unavailable" class="preview-unavailable" @if ($canPreview) hidden @endif>{{ $doc->trashed_at?'Preview is unavailable while this document is in trash.':($previewVersion && $previewVersion->scan_status!=='Clean'?'Preview becomes available after the security scan succeeds.':'Preview supports PDF, PNG, JPG, GIF, WebP, plain text, CSV and JSON. This format requires a download.') }}</p>
                    <iframe
                        id="document-preview-frame"
                        name="document-preview-frame"
                        loading="lazy"
                        class="document-preview-frame"
                        title="Document content preview"
                        @if ($canPreview) src="{{ $previewVersion->mime==='application/pdf'?'/pdf-viewer.html?file='.urlencode('/files/'.$previewVersion->id.'/preview'):'/files/'.$previewVersion->id.'/preview' }}" @else hidden @endif></iframe>
                </section>
            </section>
            <section id="file-shares" aria-label="Document shares">
                <h2 class="visually-hidden">People with access</h2>
                <div id="share-list" class="file-detail-grants">
                    <div class="share-row file-detail-grant"><strong>{{ $ownerName }}</strong><span>Owner</span></div>
                    @forelse ($shares as $share)
                        @php $shareStatus=$share->revoked_at?'Revoked':(!empty($share->expires_at) && Illuminate\Support\Carbon::parse($share->expires_at)->isPast()?'Expired':'Active'); @endphp
                        <div class="share-row file-detail-grant" data-share-id="{{ $share->id }}">
                            <div>
                                <strong>{{ $share->name }}</strong
                                ><span>{{ $share->permission }} · {{ $share->version_id?'Pinned version':'All versions' }}</span
                                ><small
                                    >{{ !empty($share->expires_at)?'Expires '.Illuminate\Support\Carbon::parse($share->expires_at)->format('M d, Y · H:i'):'No expiry' }}</small
                                >
                            </div>
                            <span class="status-badge {{ $shareStatus==='Active'?'clean':'neutral' }}">{{ $shareStatus }}</span>
                            @if ($sharer && !$share->revoked_at && !$doc->trashed_at)
                                <form action="/documents/{{ $doc->id }}/shares/{{ $share->id }}" method="post">
                                    @csrf
                                    <input type="hidden" name="revision" value="{{ $doc->revision }}" />
                                    @method ('DELETE')
                                    <input type="hidden" name="revision" value="{{ $doc->revision }}" />
                                    <button type="submit" class="text-button" aria-label="Revoke access for {{ $share->name }}">Revoke</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="text-muted">No individual sharing grants.</p>
                    @endforelse
                </div>
                <p class="section-description mt-3">Repository access also follows your assigned role and workspace.</p>
                @if ($sharer && !$doc->trashed_at)
                    <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal" data-bs-target="#share-modal">Manage access</button>
                @endif
            </section>
        </div>
        <footer class="file-detail-footer">
            <a class="btn btn-outline-secondary" href="{{ $detailClose }}">Close</a>
            @if ($canDownload)
                <a class="btn btn-primary" id="detail-download" href="/files/{{ $previewVersion->id }}">Download</a>
            @else
                <button type="button" class="btn btn-primary" disabled>Download unavailable</button>
            @endif
            <div class="file-detail-trash">
                @if ($owner)
                    <form
                        method="post"
                        action="/documents/{{ $doc->id }}/{{ $doc->trashed_at?'restore':'trash' }}"
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
            </div>
        </footer>
    </article>
@endsection
@push ('dialogs')
    @if ($editor && !$doc->trashed_at)
        @include ('partials.upload')
        <div class="modal fade" id="rename-modal" tabindex="-1" aria-labelledby="rename-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" action="/documents/{{ $doc->id }}" data-ajax-move="true" data-document-id="{{ $doc->id }}">
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
                    <form action="/documents/{{ $doc->id }}/shares" method="post">
                        @csrf
                        <input type="hidden" name="revision" value="{{ $doc->revision }}" />
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
                            ><label for="share-expiry" class="form-label">Expires <span>(within 30 days)</span></label
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
                            ><button type="submit" class="btn btn-primary">{{ $viewer->isSuperAdmin()?'Grant access':'Request access grant' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif
@endpush
