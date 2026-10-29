<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Requests\EventRequest;
use App\Models\ApiToken;
use App\Models\Event;
use App\Services\EventCreator;
use App\Services\EventLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * The event lifecycle over HTTP (SPEC-integration-api). Everything that decides
 * anything lives elsewhere on purpose: EventRequest validates, EventCreator
 * creates, EventLifecycle transitions — the same three the panel uses. What is
 * genuinely this class's own is the shape of the answers and the two rules that
 * only exist at this boundary: idempotency by external_ref, and 404 for
 * somebody else's event.
 *
 * Messages here are plain English, not __() strings: they are read by the
 * developer wiring an integration, not by an attendee. The product's Spanish
 * belongs to the surfaces people use.
 */
class EventApiController extends Controller
{
    public function __construct(
        private readonly EventCreator $creator,
        private readonly EventLifecycle $lifecycle,
    ) {}

    public function store(EventRequest $request): JsonResponse
    {
        $token = $this->token($request);
        $data = $this->normalizeStartsAt($request->validated());
        $externalRef = $this->externalRef($request);

        // Idempotency (AC-API-6): the consumer retried after a timeout it could
        // not tell apart from a failure. Answer with what already exists.
        if ($externalRef !== null) {
            $existing = $this->eventsOf($token)->where('external_ref', $externalRef)->first();

            if ($existing !== null) {
                return new JsonResponse($this->payload($existing), 200);
            }
        }

        try {
            $event = $this->creator->create($token->user, Arr::except($data, ['external_ref']), $externalRef);
        } catch (UniqueConstraintViolationException) {
            // Two retries in flight at once. The unique index is what makes the
            // guarantee real — the check above is only the fast path — so losing
            // the race still returns the event rather than an error.
            $event = $this->eventsOf($token)->where('external_ref', $externalRef)->firstOrFail();

            return new JsonResponse($this->payload($event), 200);
        }

        return new JsonResponse($this->payload($event), 201);
    }

    public function show(Request $request, string $event): JsonResponse
    {
        return new JsonResponse($this->payload($this->ownedEvent($request, $event)));
    }

    public function update(EventRequest $request, string $event): JsonResponse
    {
        $found = $this->ownedEvent($request, $event);

        // The slug is deliberately NOT regenerated when the title changes: it is
        // the public URL the consumer already printed on its own site, and the
        // person who clicks it days later is not part of this conversation.
        // external_ref is likewise not updatable — repointing another system's
        // idempotency key is never what somebody meant to do.
        $found->update(Arr::except($this->normalizeStartsAt($request->validated()), ['external_ref']));

        return new JsonResponse($this->payload($found));
    }

    public function publish(Request $request, string $event): JsonResponse
    {
        $found = $this->ownedEvent($request, $event);

        if ($this->lifecycle->publish($this->token($request)->user, $found) === EventLifecycle::UNVERIFIED) {
            return new JsonResponse([
                'message' => 'This account must verify its email address before it can publish events.',
                // Machine-readable, because the consumer has to tell this apart
                // from a validation error to show the right instruction — and it
                // is the one failure a person can actually fix.
                'code' => 'email_unverified',
            ], 422);
        }

        return new JsonResponse($this->payload($found->refresh()));
    }

    public function close(Request $request, string $event): JsonResponse
    {
        $found = $this->ownedEvent($request, $event);
        $this->lifecycle->close($found);

        return new JsonResponse($this->payload($found->refresh()));
    }

    public function cancel(Request $request, string $event): JsonResponse
    {
        $found = $this->ownedEvent($request, $event);
        $this->lifecycle->cancel($found);

        return new JsonResponse($this->payload($found->refresh()));
    }

    /**
     * The one shape every event answer takes (AC-API-6c). One presenter, so
     * "no attendee PII ever crosses this API" is a property of a single
     * function instead of a habit repeated in six places: names, emails and
     * ticket tokens are not absent by omission here, they are absent by design.
     *
     * @return array<string, mixed>
     */
    private function payload(Event $event): array
    {
        return [
            'id' => $event->id,
            'slug' => $event->slug,
            'title' => $event->title,
            'description' => $event->description,
            'status' => $event->status->value,
            'starts_at' => $event->starts_at->toIso8601String(),
            'venue' => $event->venue,
            'capacity' => $event->capacity,
            'issued_tickets_count' => $event->issuedTicketsCount(),
            'is_sold_out' => $event->isSoldOut(),
            'public_url' => route('public.event', $event),
            // v1.1 (ADR-012, SPEC-integration-api AC-API-6c). Event fields and a
            // counter — still nothing about who those people are.
            'access_info' => $event->access_info,
            'payment_info' => $event->payment_info,
            'checked_in_count' => $event->checkedInCount(),
        ];
    }

    /**
     * A time arrives as ISO-8601 with an explicit offset — that is how the
     * consumer says which instant it means — and is stored on the instance's
     * clock, exactly as the web form stores it. Skipping this is not a no-op:
     * Eloquent persists the wall time of whatever offset it was handed, so a
     * UTC payload would land three hours early in Buenos Aires and the event
     * page would show that wrong time as if it had been typed in.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeStartsAt(array $data): array
    {
        if (isset($data['starts_at']) && is_string($data['starts_at'])) {
            $data['starts_at'] = Carbon::parse($data['starts_at'])->setTimezone((string) config('app.timezone'));
        }

        return $data;
    }

    private function externalRef(Request $request): ?string
    {
        $ref = trim((string) $request->input('external_ref', ''));

        return $ref === '' ? null : $ref;
    }

    /**
     * Somebody else's event answers 404, never 403 (AC-API-8): a 403 would
     * confirm that the id exists, which is a directory of this instance's
     * events for anyone with a token and a for-loop.
     */
    private function ownedEvent(Request $request, string $id): Event
    {
        return $this->eventsOf($this->token($request))->whereKey($id)->firstOrFail();
    }

    /** @return Builder<Event> */
    private function eventsOf(ApiToken $token): Builder
    {
        return Event::query()->where('organizer_id', $token->user_id);
    }

    private function token(Request $request): ApiToken
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);

        return $token;
    }
}
