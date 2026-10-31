{{-- Share dialog partial --}}
@php
    $hasFolder = !empty($folder);
    $hasConfidentialOrRestricted = !empty($folderHasConfidentialOrRestricted)
        || (!empty($folder) && (
            !empty($folder->has_confidential_or_restricted)
            || !empty($folder->contains_confidential_or_restricted)
            || !empty($folder->has_confidential)
            || !empty($folder->has_restricted)
            || (!empty($folder->classification) && in_array($folder->classification, ['Confidential', 'Restricted']))
        ));
@endphp

<div class="modal fade" id="share-modal" tabindex="-1" aria-labelledby="share-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form
                action="{{ !empty($folder) ? '/folders/'.$folder->id.'/shares' : (isset($doc) ? '/documents/'.$doc->id.'/shares' : '#') }}"
                method="post"
                data-demo-action="share"
                id="share-form">
                @csrf
                @if (isset($doc))
                    <input type="hidden" name="revision" value="{{ $doc->revision }}" />
                @elseif (!empty($folder))
                    <input type="hidden" name="revision" value="{{ $folder->revision ?? 1 }}" />
                @endif
                <div class="modal-header">
                    <h2 class="modal-title" id="share-title">
                        {{ !empty($folder) ? 'Share '.$folder->name : (isset($doc) ? 'Share with school staff' : 'Share') }}
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @if (isset($doc))
                        <p class="modal-subtitle">{{ $doc->title }} · {{ $doc->classification }}</p>
                        <div class="p-3 mb-4 rounded border" style="background: #f8fafc;">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label for="shareable-doc-link-partial" class="form-label fw-bold mb-0">Shareable link</label>
                                <button type="button" class="btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1" id="copy-doc-link-btn-partial">
                                    <x-figma-icon name="link" />
                                    <span>Copy link</span>
                                </button>
                            </div>
                            <input type="text" id="shareable-doc-link-partial" class="form-control form-control-sm bg-white" readonly value="{{ url('/documents/'.$doc->id) }}" />
                            <small class="text-muted mt-1 d-block">Anyone with access to this workspace or document can use this link directly.</small>
                        </div>
                    @elseif (!empty($folder))
                        <p class="modal-subtitle">Folder: {{ $folder->name }}</p>
                    @endif

                    <label for="share-recipient-input" class="form-label">Staff recipient</label>
                    <input
                        id="share-recipient-input"
                        name="recipient_email"
                        type="text"
                        class="form-control share-search mb-2"
                        placeholder="Enter staff email (e.g. staff@plp.edu.ph) or choose from list…"
                        list="share-staff-datalist"
                        required
                        autocomplete="off" />
                    <datalist id="share-staff-datalist">
                        @if (isset($recipients))
                            @foreach ($recipients as $person)
                                <option value="{{ $person->email }}">{{ $person->name }} ({{ $person->email }})</option>
                            @endforeach
                        @elseif (isset($folderRecipients))
                            @foreach ($folderRecipients as $person)
                                <option value="{{ $person->email }}">{{ $person->name }} ({{ $person->email }})</option>
                            @endforeach
                        @endif
                    </datalist>
                    <input type="hidden" name="recipient_id" id="share-recipient-id" value="" />
                    <small class="text-muted d-block mb-4">Type the recipient's email address or select them from the suggestions.</small>

                    <label for="share-permission" class="form-label">Permission</label>
                    <select id="share-permission" name="permission" class="form-select mb-4">
                        <option>Viewer</option>
                        <option>Commenter</option>
                        <option>Editor</option>
                    </select>

                    <div class="permission-note">
                        <strong>Choose the right level of access</strong>
                        <p>Viewer: download. Commenter: download and comment.<br />Editor: rename and upload new versions.<br />Moving, deleting and resharing remain owner actions.</p>
                    </div>

                    @if (isset($versions))
                        <label for="share-version" class="form-label">Version access</label>
                        <select id="share-version" name="version_id" class="form-select mb-4">
                            <option value="">All versions, including future uploads</option>
                            @foreach ($versions as $v)
                                <option value="{{ $v->id }}">Version {{ $v->number }} only</option>
                            @endforeach
                        </select>
                    @endif

                    {{-- Folder Sharing Checkbox: shown only when a folder is selected; hidden/disabled if flagged confidential/restricted --}}
                    <div id="folder-sharing-container" @if (!$hasFolder) style="display: none" @endif>
                        @if ($hasFolder && !$hasConfidentialOrRestricted)
                            <div class="form-check mt-3" id="folder-sharing-checkbox-group">
                                <input id="share-apply-folder-files" class="form-check-input" type="checkbox" name="apply_to_folder_files" value="1" />
                                <label class="form-check-label" for="share-apply-folder-files"> Apply to all files in this folder (Internal only) </label>
                            </div>
                        @else
                            <div class="form-check mt-3" id="folder-sharing-checkbox-group" style="display: none">
                                <input
                                    id="share-apply-folder-files"
                                    class="form-check-input"
                                    type="checkbox"
                                    name="apply_to_folder_files"
                                    value="1"
                                    disabled
                                    hidden />
                                <label class="form-check-label text-muted" for="share-apply-folder-files">
                                    Apply to all files in this folder (Internal only)
                                </label>
                                @if ($hasConfidentialOrRestricted)
                                    <small class="text-muted d-block mt-1"
                                        >This folder contains Confidential or Restricted documents. Bulk folder sharing applies to Internal documents
                                        only.</small
                                    >
                                @endif
                            </div>
                        @endif
                    </div>

                    <label for="share-expiry" class="form-label mt-3">Expires <span>(within 30 days)</span></label>
                    <input
                        id="share-expiry"
                        name="expires_at"
                        type="date"
                        class="form-control datepicker"
                        aria-describedby="share-expiry-help"
                        min="{{ now()->addDay()->format('Y-m-d') }}"
                        max="{{ now()->addDays(30)->format('Y-m-d') }}" />
                    <p id="share-expiry-help" class="form-text security-note mt-4">Named staff only. No public or anonymous links. Share link expires within 30 days.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        {{ (isset($viewer) && $viewer->isSuperAdmin()) ? 'Grant access' : 'Request access grant' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
