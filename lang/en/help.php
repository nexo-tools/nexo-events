<?php

// Help center FAQs for Nexo Events (rendered by help/index via __('help.faqs')).
return [
    'faqs' => [
        [
            'q' => 'What is Nexo Events?',
            'a' => 'A tool to create events, publish a public registration page, and validate tickets with a QR code at the door.',
        ],
        [
            'q' => 'Is there a cost?',
            'a' => 'Nexo Events is open source and self-hosted: no commissions, no per-attendee fees.',
        ],
        [
            'q' => 'Do I need an account to attend an event?',
            'a' => 'No. You register with your name and email, and receive your QR ticket by email.',
        ],
        [
            'q' => 'How do I validate tickets at the door?',
            'a' => 'From the event panel you scan or type the ticket code. Check-in is atomic: each ticket is marked as admitted exactly once.',
        ],
        [
            'q' => 'Can I host it on my own server?',
            'a' => 'Yes. Clone the repository and deploy it wherever you like; your data stays on your own instance.',
        ],
        [
            'q' => "My ticket didn't arrive by email.",
            'a' => 'Check your spam or promotions folders. If it is not there, contact the event organizer.',
        ],
    ],
];
