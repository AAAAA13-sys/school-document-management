@can ('document.manage', $doc)
    <details class="mt-3">
        <summary>Publish an official memo</summary>
        <p>Publication pins this Clean version for the selected audience. A later upload does not replace the circulated version until publication is approved.</p>
        <form method="post" action="/documents/{{ $doc->id }}/publish">
            @csrf
            <input type="hidden" name="revision" value="{{ $doc->revision }}" />
            <label class="d-block"
                >Clean version<select class="form-select" name="version_id" required>
                    @foreach ($versions as $version)
                        @if ($version->scan_status === 'Clean')
                            <option value="{{ $version->id }}">Version {{ $version->number }}: {{ $version->filename }}</option>
                        @endif

                    @endforeach</select
            ></label>
            <label class="d-block">Memo type<input class="form-control" name="type" required maxlength="100" /></label
            ><label class="d-block">Reference<input class="form-control" name="reference" required maxlength="100" /></label
            ><label class="d-block">Issuer<input class="form-control" name="issuer" required maxlength="200" value="{{ auth()->user()->name }}" /></label>
            <label class="d-block"
                >Audience<select class="form-select" name="audience[]" multiple required>
                    @foreach (\App\Services\Access::scope(\Illuminate\Support\Facades\DB::table('users'), auth()->user())->where('active',true)->orderBy('name')->get() as $recipient)
                        <option value="{{ $recipient->id }}">{{ $recipient->name }}</option>
                    @endforeach</select></label
            ><button class="btn btn-primary mt-2">Submit publication</button>
        </form>
    </details>

@endcan
