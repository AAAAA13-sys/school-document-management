@if ($canManageFolder)
    <div class="modal fade" id="folder-share-modal" tabindex="-1" aria-labelledby="folder-share-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title" id="folder-share-title">Share {{ $folder->name }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="modal-subtitle">Everyone granted here can open this folder and every folder inside it.</p>
                    <div id="folder-share-list">
                        @forelse ($folderShares->whereNull('revoked_at') as $share)
                            <div class="share-row" data-share-id="{{ $share->id }}">
                                <span>{{ $share->name }} · {{ $share->permission }}{{ $share->expires_at?' · expires '.$share->expires_at:'' }}</span>
                                <form action="/folders/{{ $folder->id }}/shares/{{ $share->id }}" method="post">
                                    @csrf
                                    @method ('DELETE')
                                    <button type="submit" class="icon-button" aria-label="Revoke folder access for {{ $share->name }}">×</button>
                                </form>
                            </div>
                        @empty
                            <p class="text-muted">No one else can open this folder yet.</p>
                        @endforelse
                    </div>
                    <hr />
                    <form id="folder-share-form" action="/folders/{{ $folder->id }}/shares" method="post">
                        @csrf
                        <label for="folder-share-recipient" class="form-label">Staff recipient</label
                        ><select id="folder-share-recipient" name="recipient_id" class="form-select mb-4" required>
                            <option value="">Choose a person</option>
                            @foreach ($folderRecipients as $person)
                                <option value="{{ $person->id }}">{{ $person->name }} ({{ $person->email }})</option>
                            @endforeach
                        </select>
                        <label for="folder-share-permission" class="form-label">Inherited permission</label
                        ><select id="folder-share-permission" name="permission" class="form-select mb-4">
                            <option>Viewer</option>
                            <option>Commenter</option>
                            <option>Editor</option>
                        </select>
                        <div class="permission-note">
                            <strong>How folder access works</strong>
                            <p>Access is inherited by documents and subfolders. An explicit document grant always wins. Editor can rename and add versions, but still cannot move, delete or reshare.<br />Moving a document out of this folder removes the inherited access; earlier versions and audit records stay intact.</p>
                        </div>
                        @php
                            $hasConfidentialOrRestricted = !empty($folderHasConfidentialOrRestricted)
                                || (!empty($folder) && (
                                    !empty($folder->has_confidential_or_restricted)
                                    || !empty($folder->contains_confidential_or_restricted)
                                    || !empty($folder->has_confidential)
                                    || !empty($folder->has_restricted)
                                    || (!empty($folder->classification) && in_array($folder->classification, ['Confidential', 'Restricted']))
                                ));
                        @endphp
                        @if (!$hasConfidentialOrRestricted)
                            <div class="form-check mt-3" id="folder-share-apply-all-group">
                                <input id="folder-share-apply-all" class="form-check-input" type="checkbox" name="apply_to_folder_files" value="1" />
                                <label class="form-check-label" for="folder-share-apply-all">
                                    Apply to all files in this folder (Internal only)
                                </label>
                            </div>
                        @else
                            <div class="form-check mt-3" id="folder-share-apply-all-group" style="display: none;">
                                <input id="folder-share-apply-all" class="form-check-input" type="checkbox" name="apply_to_folder_files" value="1" disabled hidden />
                                <label class="form-check-label text-muted" for="folder-share-apply-all">
                                    Apply to all files in this folder (Internal only)
                                </label>
                                <small class="text-muted d-block">This folder contains Confidential or Restricted documents.</small>
                            </div>
                        @endif
                        <label for="folder-share-expiry" class="form-label mt-3">Expires <span>(optional)</span></label
                        ><input
                            id="folder-share-expiry"
                            name="expires_at"
                            type="text"
                            class="form-control datepicker"
                            placeholder="Select a date"
                            autocomplete="off" />
                    </form>
                    <p class="security-note mt-4">No public or anonymous links. Revoking a folder grant leaves independent document grants in place.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button
                    ><button type="submit" form="folder-share-form" class="btn btn-primary">Grant folder access</button>
                </div>
            </div>
        </div>
    </div>
@endif
