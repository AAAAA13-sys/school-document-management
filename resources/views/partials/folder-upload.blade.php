<div class="modal fade" id="folder-upload-modal" tabindex="-1" aria-labelledby="folder-upload-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="folder-upload-form" action="/documents/batch" method="post" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="submission_key" value="{{ (string)Illuminate\Support\Str::uuid() }}" /><input
                    type="hidden"
                    name="source"
                    value="{{ $source }}" /><input type="hidden" name="folder_id" value="{{ $folder?->id }}" />
                <div class="modal-header">
                    <h2 class="modal-title" id="folder-upload-title">Upload a folder</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Choose up to 50 files of any format, at most 10 MB each. Folder structure is preserved. Each file is scanned automatically.</p>
                    <label for="folder-files" class="form-label">Folder</label
                    ><input id="folder-files" class="form-control mb-3" type="file" name="files[]" webkitdirectory multiple required /><label
                        class="form-label"
                        for="folder-classification"
                        >Classification</label
                    ><select id="folder-classification" class="form-select" name="classification">
                        <option>Confidential</option>
                        <option>Restricted</option>
                        <option>Internal</option>
                    </select>
                    <div class="form-check mt-3">
                        <input id="folder-confirm-duplicates" class="form-check-input" type="checkbox" name="confirm_duplicate" value="1" />
                        <label class="form-check-label" for="folder-confirm-duplicates"
                            >Keep intentional copies of files that already exist. The warning only names documents you can already open.</label
                        >
                    </div>
                    <p id="folder-upload-feedback" class="mt-3 mb-0" role="status"></p>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit">Upload folder</button>
                </div>
            </form>
        </div>
    </div>
</div>
