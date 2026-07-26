<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportEventRequest;
use App\Mail\EventReported;
use App\Models\Event;
use App\Models\EventReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

class EventReportController extends Controller
{
    /**
     * Public abuse report (ADR-007 §3). No login: requiring an account to flag
     * an obvious phishing event would defeat the purpose.
     */
    public function store(ReportEventRequest $request, Event $event): RedirectResponse
    {
        abort_unless($event->status->isPublic(), 404);

        $report = new EventReport($request->safe()->only(['reason', 'reporter_email']));
        $report->event()->associate($event);
        $report->save();

        Mail::to(config('nexo.support_email'))->queue(new EventReported($report));

        // Same answer regardless of how many reports this event already has —
        // otherwise the form becomes a way to probe what is under moderation.
        return back()->with('status', __('Gracias. Revisamos los reportes y actuamos si corresponde.'));
    }
}
