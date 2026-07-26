<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('app.dashboard', [
            // views_count is unique visitor-days, not hits: the number an organizer
            // actually wants, and the only one the cookieless counter can honestly give.
            'events' => $request->user()->events()->withCount(['tickets', 'views'])->latest()->get(),
        ]);
    }
}
