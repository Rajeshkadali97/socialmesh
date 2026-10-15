<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\WorkspaceSubscriptionGate;
use App\Services\Posts\BulkScheduleImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BulkScheduleController extends Controller
{
    public function show(Request $request): Response
    {
        $this->authorize('create', Post::class);

        return Inertia::render('posts/bulk', [
            'template' => BulkScheduleImporter::TEMPLATE,
            'maxRows' => BulkScheduleImporter::MAX_ROWS,
            'timezone' => $this->workspace($request)->timezone,
        ]);
    }

    public function preview(Request $request, BulkScheduleImporter $importer): JsonResponse
    {
        $this->authorize('create', Post::class);

        $csv = $request->validate(['csv' => ['required', 'string', 'max:300000']])['csv'];

        return response()->json($importer->preview($this->workspace($request), $csv));
    }

    public function store(Request $request, BulkScheduleImporter $importer, WorkspaceSubscriptionGate $subscriptions): JsonResponse
    {
        $this->authorize('create', Post::class);

        $workspace = $this->workspace($request);
        if (! $subscriptions->canPublish($workspace)) {
            return response()->json([
                'message' => 'Subscribe to publish these posts.',
                'billing_url' => route('billing.index'),
            ], 402);
        }

        $csv = $request->validate(['csv' => ['required', 'string', 'max:300000']])['csv'];
        /** @var User $user */
        $user = $request->user();

        return response()->json($importer->commit($workspace, $user, $csv));
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->user()?->currentWorkspace;
        abort_if($workspace === null, 404);

        return $workspace;
    }
}
