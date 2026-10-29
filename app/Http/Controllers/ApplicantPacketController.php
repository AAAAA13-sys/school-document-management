<?php

namespace App\Http\Controllers;

use App\Services\ApplicantPacketService;
use Illuminate\Http\Request;

class ApplicantPacketController extends Controller
{
    public function receive(Request $request, ApplicantPacketService $service)
    {
        $data = $request->validate(['event_id' => 'required|string|max:100', 'applicant_id' => 'required|string|max:100',
            'revision' => 'required|integer|min:1', 'released' => 'required|boolean', 'admitted' => 'sometimes|boolean', 'verified' => 'sometimes|boolean',
            'version_ids' => 'sometimes|array|max:50', 'version_ids.*' => 'required|uuid|distinct']);
        [$body, $status] = $service->receive($request->attributes->get('connector'), $data);

        return response()->json($body, $status);
    }

    public function show(Request $request, string $packet, ApplicantPacketService $service)
    {
        return response()->json($service->read($request->attributes->get('connector'), $packet));
    }

    public function download(Request $request, string $packet, string $version, ApplicantPacketService $service)
    {
        return $service->download($request->attributes->get('connector'), $packet, $version);
    }
}
