<?php

// Legal pages (privacy + terms). See lang/es/legal.php — Spanish is the source.
// NOT reviewed by a lawyer: written to describe accurately what this codebase
// actually does, so a review starts from something true.
return [
    'updated' => 'Last updated: 26 July 2026',

    // Rendered only when NEXO_LEGAL_OPERATOR / NEXO_LEGAL_CONTACT are set.
    'operator' => [
        'h' => 'Who runs this instance',
        'p' => 'This instance is run by :operator.',
        'contact' => 'For anything about your data you can write to :contact.',
    ],

    'privacy' => [
        'title' => 'Privacy',
        'intro' => 'This Nexo Events instance is open source and self-hosted. We collect the minimum an event needs, and nothing else. No tracking cookies, no third-party analytics, and nothing sent to advertising networks.',
        'sections' => [
            [
                'h' => 'What we store about organizers',
                'p' => 'Name, email and a hashed version of the password. The email is used to verify the account, recover access and notify you about your own events. If you sign in with Nexo ID we also store the identifier that service gives us so we can recognise you.',
            ],
            [
                'h' => 'What we store about attendees',
                'p' => 'The name and email you type when registering for an event, and whether you checked in. We do not create an account or ask for a password. Those details are visible to that event\'s organizer — they are the ones who need to know who is coming.',
            ],
            [
                'h' => 'Tickets and their QR code',
                'p' => 'Your ticket code is a random value with none of your data inside it. The database stores only its fingerprint (a hash), never the code itself: even with access to the database, nobody could forge valid tickets. If you ask us to resend your ticket, a new code is generated and the previous one stops working.',
            ],
            [
                'h' => 'Metrics without cookies',
                'p' => 'We count how many distinct people viewed an event page using a fingerprint computed with today\'s date and then discarded: we do not store your IP or your browser, and today\'s fingerprint cannot be compared with tomorrow\'s. We do not know who you are and cannot follow you across sites.',
            ],
            [
                'h' => 'Cookies',
                'p' => 'Only the ones the site needs to work: the session cookie (to keep you signed in if you have an account) and the ones remembering your language and light/dark preference. None are used for advertising or tracking.',
            ],
            [
                'h' => 'Email',
                'p' => 'Tickets and account emails are delivered through an external email provider, which necessarily processes the destination address and the message content in order to deliver it.',
            ],
            [
                'h' => 'How long',
                'p' => 'An event\'s data is kept for as long as the organizer keeps the event and their account. Deleting an event deletes its tickets and associated records.',
            ],
            [
                'h' => 'Your rights',
                'p' => 'You can request access to your data, its correction or its deletion by writing to whoever runs this instance (the contact is on the help page). If you registered for an event, that event\'s organizer can also remove you from their list.',
            ],
            [
                'h' => 'Other instances',
                'p' => 'Nexo Events can be installed on any server. Each installation is independent and responsible for its own data: this policy covers only this instance.',
            ],
        ],
    ],

    'terms' => [
        'title' => 'Terms of use',
        'intro' => 'By using this Nexo Events instance you accept the following. It is a free service, offered as is.',
        'sections' => [
            [
                'h' => 'What the service is',
                'p' => 'A tool to publish free events, take registrations by email and validate tickets with a QR code at the door. We do not process payments or sell tickets.',
            ],
            [
                'h' => 'Your account',
                'p' => 'You need an account to create events, and a verified email to publish them. You are responsible for what happens with your account and for keeping your password safe.',
            ],
            [
                'h' => 'Responsibility for your events',
                'p' => 'An event\'s content, its accuracy, whether it actually happens, and the handling of registrants\' data are the organizer\'s responsibility. Whoever publishes an event acts as the controller of that data towards their attendees and must comply with whatever rules apply to them.',
            ],
            [
                'h' => 'Misuse',
                'p' => 'Publishing fake, misleading or fraudulent events, impersonating others, harvesting data for purposes unrelated to the event, or posting illegal content is not allowed. Anyone can report an event, and whoever runs this instance can take it down: the page becomes unavailable, registration closes, and tickets already issued stop validating at the door.',
            ],
            [
                'h' => 'Availability',
                'p' => 'The service is offered without availability guarantees. We do what is reasonable to keep it online, especially during events, but interruptions are possible. An event with doubtful connectivity at the door should always have a fallback plan.',
            ],
            [
                'h' => 'Limitation of liability',
                'p' => 'Whoever runs this instance is not liable for damages arising from use of the service, including events that do not take place, tickets that cannot be validated, or data loss.',
            ],
            [
                'h' => 'Free software',
                'p' => 'Nexo Events is distributed under the MIT licence: you can read the code, modify it and host your own instance. The software is provided without warranty, as that licence states.',
            ],
            [
                'h' => 'Changes',
                'p' => 'These terms may change. The date above shows the last update.',
            ],
        ],
    ],
];
