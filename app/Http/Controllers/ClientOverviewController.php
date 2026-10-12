<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ClientOverview;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientOverviewController extends Controller
{
    /**
     * Every workspace (client) the user belongs to, with what needs attention.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('clients/index', [
            'clients' => ClientOverview::make($user),
        ]);
    }
}
