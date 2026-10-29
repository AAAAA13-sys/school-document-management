<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Sensitive change requests · DMS</title>
    <link rel="stylesheet" href="/vendor/bootstrap.min.css" />
</head>
<body>
    <main class="container py-4">
        <a href="{{ auth()->user()->homePath() }}">Back to workspace</a>
        <h1>Sensitive change requests</h1>
        <p>Live documents remain unchanged until approval. Decisions require a reason and cannot be made by the requester.</p>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p role="alert">{{ $errors->first() }}</p>
        @endif
        <div class="table-responsive">
            <table class="table table-striped">
                <caption>
                    Your requests and requests awaiting your review
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Action / requested</th>
                        <th scope="col">State</th>
                        <th scope="col">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($changes as $change)
                        <tr>
                            <td>
                                {{ $change->action }}<br />
                                <details>
                                    <summary>Proposed change</summary>
                                    <pre>{{ json_encode(json_decode($change->payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                                <br />{{ $change->created_at }}<br />Requester #{{ $change->requester_id }} · document revision {{ $change->revision }}<br /><a
                                    href="/documents/{{ $change->document_id }}"
                                    >Open document</a
                                >
                                <details>
                                    <summary>Proposed changes</summary>
                                    <pre>{{ json_encode(json_decode($change->payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            </td>
                            <td>
                                {{ $change->status }}
                                @if ($change->reason)
                                    <br
                                    />{{ $change->reason }}
                                @endif
                            </td>
                            <td>
                                @if ($change->status === 'pending' && (int)$change->requester_id !== auth()->id())
                                    <form method="post" action="/approvals/{{ $change->id }}">
                                        @csrf
                                        <label class="d-block">Reason<textarea class="form-control" name="reason" maxlength="2000" required></textarea></label
                                        ><button class="btn btn-primary mt-2" name="decision" value="approve">Approve</button>
                                        <button class="btn btn-outline-danger mt-2" name="decision" value="reject">Reject</button>
                                    </form>
                                @else
                                    {{ $change->decided_at ?? 'Awaiting the next role’s review' }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">No requests.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </main>
</body>
</html>
