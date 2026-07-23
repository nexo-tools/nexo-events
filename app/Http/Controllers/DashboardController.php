<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('app.dashboard', [
            'events' => $request->user()->events()->withCount('tickets')->latest()->get(),
        ]);
    }
}
