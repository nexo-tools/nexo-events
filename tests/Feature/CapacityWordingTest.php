<?php

use App\Models\Event;

/**
 * One word for the capacity (PLAN 10.20, H-nexoevents-14) — SPEC
 * docs/specs/SPEC-event-panel.md. The edit form said «Cupo» and ticket
 * management said «Aforo» for the same number, one field apart.
 */

/** Every Spanish/Portuguese string a person can read: the JSON maps and the lang files. */
function capacityWordingSources(string $locale): string
{
    $text = (string) file_get_contents(lang_path("{$locale}.json"));

    foreach (glob(lang_path("{$locale}/*.php")) ?: [] as $file) {
        if (basename($file) !== 'countries.php') {
            $text .= (string) file_get_contents($file);
        }
    }

    return $text;
}

it('AC-PANEL-7: no "Aforo" anywhere a person can read it', function (): void {
    $views = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)) as $file) {
        $views .= (string) file_get_contents($file->getPathname());
    }

    expect(preg_match('/aforo/iu', capacityWordingSources('es').$views))->toBe(0)
        ->and(preg_match('/lotação|capacidade/iu', capacityWordingSources('pt').$views))->toBe(0);
});

it('AC-PANEL-7: every capacity string says «cupo» in Spanish and «vagas» in Portuguese', function (): void {
    foreach (['es' => '/\bcupo\b/iu', 'pt' => '/\bvagas\b/iu'] as $locale => $word) {
        $map = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);
        $capacity = array_filter($map, fn (string $key): bool => stripos($key, 'capacity') !== false, ARRAY_FILTER_USE_KEY);

        expect($capacity)->not->toBeEmpty();
        foreach ($capacity as $key => $translation) {
            expect(preg_match($word, $translation))->toBe(1, "[{$locale}] «{$key}» → «{$translation}»");
        }
    }
});

it('AC-PANEL-7: the summary and the edit form name the capacity the same way', function (): void {
    $event = Event::factory()->withCapacity(80)->create();

    $summary = (string) $this->actingAs($event->organizer)->get(route('events.manage', $event).'?lang=es')->getContent();
    $edit = (string) $this->actingAs($event->organizer)->get(route('events.edit', $event).'?lang=es')->getContent();

    expect($summary)->toMatch('#<label[^>]*for="capacity"[^>]*>\s*Cupo\s*</label>#u')
        ->and($edit)->toMatch('#<label[^>]*for="capacity"[^>]*>\s*Cupo \(opcional\)\s*</label>#u');
});
