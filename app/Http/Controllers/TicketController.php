<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\View\View;

class TicketController extends Controller
{
    /** The attendee's ticket page — reached by the opaque token (emailed + shown once). */
    public function show(string $token): View
    {
        $ticket = Ticket::query()
            ->where('token_hash', hash('sha256', $token))
            ->with('event')
            ->firstOrFail();

        return view('public.ticket', ['ticket' => $ticket, 'token' => $token]);
    }
}
