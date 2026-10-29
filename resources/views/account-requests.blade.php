<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Account change requests · DMS</title>
    <link rel="stylesheet" href="/vendor/bootstrap.min.css" />
</head>
<body>
    <main class="container py-4">
        <a href="/admin/accounts">Back to accounts</a>
        <h1>Account change requests</h1>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p role="alert">{{ $errors->first() }}</p>
        @endif
        <table class="table table-striped">
            <caption>
                Proposed account changes remain inactive until approved.
            </caption>
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Proposed values</th>
                    <th>State</th>
                    <th>Decision</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($changes as $change)
                    <tr>
                        <td>{{ $change->email }}</td>
                        <td>
                            <dl>
                                @foreach (json_decode($change->payload,true) as $key=>$value)
                                    <dt>{{ $key }}</dt>
                                    <dd>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</dd>
                                @endforeach
                            </dl>
                        </td>
                        <td>{{ $change->status }}<br />{{ $change->reason }}</td>
                        <td>
                            @if ($change->status === 'pending' && auth()->user()->isSuperAdmin())
                                <form method="post" action="/admin/account-requests/{{ $change->id }}/decide">
                                    @csrf
                                    <label>Reason<input name="reason" required maxlength="2000" /></label><button name="approve" value="1">Approve</button
                                    ><button name="approve" value="0">Reject</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        {{ $changes->links() }}
    </main>
</body>
</html>
