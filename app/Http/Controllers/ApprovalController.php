<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access;
use App\Services\SensitiveChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
    public function index(Request $request, SensitiveChangeService $service)
    {
        $user = $request->user();
        $changes = DB::table('sensitive_changes')->orderByDesc('created_at')->get()->filter(function ($change) use ($user, $service) {
            if ((int) $change->requester_id === $user->id) return true;
            $doc = DB::table('documents')->where('id', $change->document_id)->first();
            $requester = User::find($change->requester_id);
            return $doc && $requester && $service->canReview($user, $requester, $doc);
        });
        return view('approvals.index', compact('changes'));
    }

    public function decide(Request $request, string $approval, SensitiveChangeService $service)
    {
        $p = $request->validate(['decision' => 'required|in:approve,reject', 'reason' => 'required|string|max:2000']);
        $result = $service->decide($request->user(), $approval, $p['decision'] === 'approve', $p['reason']);
        return $request->expectsJson() ? response()->json($result) : back()->with('status', 'Request '.$result['status'].'.');
    }

    public function publish(Request $request, string $document, SensitiveChangeService $service)
    {
        $p = $request->validate(['revision' => 'required|integer|min:1', 'version_id' => 'required|uuid', 'type' => 'required|string|max:100',
            'reference' => 'required|string|max:100', 'issuer' => 'required|string|max:200', 'audience' => 'required|array|min:1|max:500', 'audience.*' => 'required|integer|distinct']);
        $result = $service->submit($request->user(), Access::document($request->user(), $document), 'memo.publish', $p);
        return $request->expectsJson() ? response()->json($result, $result['status'] === 'pending' ? 202 : 200) : back()->with('status', 'Publication '.$result['status'].'.');
    }
}
