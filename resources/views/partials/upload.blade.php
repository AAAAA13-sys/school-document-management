@if (!isset($doc))
    <div class="modal fade" id="upload-modal" tabindex="-1" aria-labelledby="upload-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered plain-upload-dialog">
            <div class="modal-content">
                <form method="post" action="/documents" enctype="multipart/form-data" id="upload-form" data-multi-upload="true">
                    @csrf
                    <input type="hidden" name="source" value="{{ $source }}" /><input type="hidden" name="folder_id" value="{{ $folder->id??'' }}" />
                    <div class="modal-header">
                        <h2 id="upload-title" class="modal-title">Upload files</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="upload-classification-row">
                            <label for="upload-class" class="form-label">Classification</label
                            ><select id="upload-class" name="classification" class="form-select" aria-describedby="upload-class-help">
                                <option>Internal</option>
                                <option selected>Confidential</option>
                                <option>Restricted</option>
                            </select>
                        </div>
                        <p id="upload-class-help" class="security-note mb-3">Applies to the files you drop or select next.</p>
                        <div class="upload-zone plain-upload-zone" id="upload-zone">
                            <x-figma-icon name="upload" :size="28" /><label for="upload-file" class="form-label">Drop your files here</label
                            ><span>or choose files</span
                            ><input id="upload-file" name="file" type="file" multiple class="form-control" aria-describedby="upload-file-help" /><small
                                id="upload-file-help"
                                >Any format · 10 MB per file · Up to 50 files</small
                            >
                        </div>
                        <p class="security-note mt-3">Uploads start automatically. Filenames become titles. Files use your selected classification and current folder.</p>
                        <p id="upload-summary" role="status" aria-live="polite" class="mt-3">Ready for files.</p>
                        <ul id="upload-file-list" class="plain-upload-list" aria-label="File upload results"></ul>
                        <button type="button" class="btn btn-outline-secondary mt-3" id="retry-uploads" hidden>Retry failed uploads</button>
                        <p class="security-note mt-3 mb-0">Security scanning runs in the background after upload.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Done</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@else
    <div class="modal fade" id="upload-modal" tabindex="-1" aria-labelledby="upload-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered upload-dialog">
            <div class="modal-content">
                <form method="post" action="{{ isset($doc)?'/documents/'.$doc->id.'/versions':'/documents' }}" enctype="multipart/form-data" id="upload-form">
                    @csrf
                    <input type="hidden" name="submission_key" value="{{ (string) Illuminate\Support\Str::uuid() }}" /><input
                        type="hidden"
                        name="source"
                        value="{{ $source }}" />
                    <div class="modal-header">
                        <h2 id="upload-title" class="modal-title">{{ isset($doc)?'Upload a new version':'Upload a document' }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body upload-layout">
                        <div class="upload-fields">
                            @if (isset($doc))
                                <input type="hidden" name="revision" value="{{ $doc->revision }}" />
                                <p>Every earlier version of <strong>{{ $doc->title }}</strong> is preserved.</p>
                            @else
                                <div class="upload-field">
                                    <label for="upload-name" class="form-label">Document title</label
                                    ><input
                                        id="upload-name"
                                        class="form-control"
                                        name="title"
                                        maxlength="200"
                                        required
                                        value="{{ old('title') }}"
                                        placeholder="e.g. Employee information form" />
                                </div>
                                <div class="row g-3 upload-field">
                                    <div class="col-6">
                                        <label for="upload-class" class="form-label">Classification</label
                                        ><select id="upload-class" name="classification" class="form-select">
                                            <option>Confidential</option>
                                            <option>Restricted</option>
                                            <option>Internal</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label for="upload-folder" class="form-label">Folder</label
                                        ><select id="upload-folder" name="folder_id" class="form-select">
                                            <option value="">My drive</option>
                                            @foreach ($folders as $f)
                                                <option value="{{ $f->id }}" @selected (($folder->id??null)===$f->id)>{{ $f->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="upload-field">
                                    <label for="upload-reference" class="form-label">Reference <span>(optional)</span></label
                                    ><input id="upload-reference" name="reference" class="form-control" maxlength="100" placeholder="e.g. EMP-2026-001" />
                                </div>
                            @endif
                        </div>
                        <div class="upload-file-panel">
                            <div class="upload-zone" id="upload-zone">
                                <x-figma-icon name="upload" :size="28" /><label for="upload-file" class="form-label">Choose a file or drop it here</label
                                ><input id="upload-file" name="file" type="file" class="form-control" required aria-describedby="upload-file-help" /><small
                                    id="upload-file-help"
                                    >Any file format · Up to 10 MB</small
                                ><span class="selected-file" id="selected-file" aria-live="polite"></span>
                            </div>
                            <p class="security-note mt-3 mb-0">Scanning starts automatically in the background after upload. Your file stays private and downloads remain blocked until it is clean.</p>
                        </div>
                        <div id="upload-duplicates" class="alert alert-warning mt-3" role="alert" hidden>
                            <p class="mb-2">
                                <strong>This file looks like one you already have.</strong> Only documents you can already open are listed. Open the existing
                                document, or keep a separate copy.
                            </p>
                            <ul id="upload-duplicate-list" class="mb-2"></ul>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="upload-duplicate-confirm">Upload as a separate copy</button>
                        </div>
                        <input type="hidden" name="confirm_duplicate" value="0" id="upload-confirm-duplicate" />
                        <div class="upload-progress mt-3" hidden>
                            <div class="progress" role="progressbar" aria-label="File upload" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                                <div class="progress-bar"></div>
                            </div>
                            <small aria-live="polite">Uploading…</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button
                        ><button class="btn btn-primary" type="submit">Upload {{ isset($doc)?'version':'document' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

<div class="modal fade" id="duplicate-warning-modal" tabindex="-1" aria-labelledby="duplicate-warning-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="duplicate-warning-title">Duplicate file detected</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="modal-subtitle">An identical file already exists in a document you can access.</p>
                <div class="duplicate-info-card p-3 my-3 rounded" style="background: var(--canvas, #f8f9fa); border: 1px solid var(--line, #e2e8f0)">
                    <p class="mb-2"><strong>Document Title:</strong> <span id="duplicate-modal-doc-title">—</span></p>
                    <p class="mb-0"><strong>Document Owner:</strong> <span id="duplicate-modal-doc-owner">—</span></p>
                </div>
                <p class="text-muted small mb-0">You can upload this file as a new version of the existing document, or cancel the upload.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="duplicate-modal-cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="duplicate-modal-upload-new-version">Upload as New Version</button>
            </div>
        </div>
    </div>
</div>
