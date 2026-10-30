<?php

namespace App\Http\Controllers;

use App\Models\Request as RequestModel;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;

class RequestsApprovalController extends Controller
{
    /**
     * Display a listing of requests.
     */
    public function index()
    {
        $this->authorize('admin.access');

        $requests = RequestModel::with('user')
            ->latest()
            ->paginate(20);

        return view('admin.requests', compact('requests'));
    }

    /**
     * Store a newly created request.
     */
    public function store(HttpRequest $request)
    {
        $this->authorize('admin.access');

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'type'        => 'required|in:document_access,folder_share,feature,other',
            'priority'    => 'required|in:low,medium,high',
        ]);

        RequestModel::create([
            'user_id'     => Auth::id(),
            'title'       => $data['title'],
            'description' => $data['description'],
            'type'        => $data['type'],
            'priority'    => $data['priority'],
            'status'      => 'pending',
        ]);

        return redirect()->route('requests.index')
            ->with('status', 'Your request has been submitted.');
    }

    /**
     * Approve the given request.
     */
    public function approve(HttpRequest $request, RequestModel $requestModel)
    {
        $this->authorize('admin.access');

        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $requestModel->update([
            'status'      => 'approved',
            'reviewer_id' => Auth::id(),
            'reason'      => $request->reason,
        ]);

        return back()->with('status', 'Request approved.');
    }

    /**
     * Reject the given request.
     */
    public function reject(HttpRequest $request, RequestModel $requestModel)
    {
        $this->authorize('admin.access');

        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $requestModel->update([
            'status'      => 'rejected',
            'reviewer_id' => Auth::id(),
            'reason'      => $request->reason,
        ]);

        return back()->with('status', 'Request rejected.');
    }
}
