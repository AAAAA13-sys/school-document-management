<?php

namespace App\Http\Controllers;

use App\Services\AccountChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountChangeController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->isAdmin() || $actor->isSuperAdmin(), 403);
        $changes = DB::table('account_changes as c')->join('users as u', 'u.id', '=', 'c.account_id')
            ->where('u.school_id', $actor->school_id)->where('u.campus', $actor->campus)
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('c.requester_id', $actor->id))
            ->select('c.*', 'u.email')->orderByDesc('c.created_at')->paginate(30);

        if ($request->expectsJson()) {
            return response()->json($changes);
        }

        return view('account-requests', compact('changes'));
    }

    public function decide(Request $request, string $change, AccountChangeService $service)
    {
        $data = $request->validate(['approve' => 'required|boolean', 'reason' => 'required|string|max:2000']);
        $service->decide($request->user(), $change, (bool) $data['approve'], $data['reason']);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => (bool) $data['approve'] ? 'approved' : 'rejected',
                'message' => 'Account decision recorded.',
            ]);
        }

        return back()->with('status', 'Account decision recorded.');
    }
}
