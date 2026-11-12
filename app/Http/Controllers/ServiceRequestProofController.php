<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ServiceRequestProofController extends Controller
{
    public function __invoke(Request $request, ServiceRequest $serviceRequest): StreamedResponse
    {
        abort_unless($serviceRequest->user_id === $request->user()->id || Gate::allows('requests.view'), 404);
        abort_unless($serviceRequest->screenshot_path !== null && Storage::disk('local')->exists($serviceRequest->screenshot_path), 404);

        return Storage::disk('local')->download($serviceRequest->screenshot_path, 'proof-'.$serviceRequest->reference.'.'.pathinfo($serviceRequest->screenshot_path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
