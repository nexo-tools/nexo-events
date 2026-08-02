<?php

// Legal pages (privacy + terms), rendered by legal/show.
//
// NOT reviewed by a lawyer. Written to describe accurately what this codebase
// actually does — which is the part an agent can get right — so that a review,
// if the owner wants one, starts from something true rather than from a
// template full of clauses about data the app never collects.
return [
    'updated' => 'Última actualización: 26 de julio de 2026',

    // Rendered only when NEXO_LEGAL_OPERATOR / NEXO_LEGAL_CONTACT are set.
    'operator' => [
        'h' => 'Quién opera esta instancia',
        'p' => 'Esta instancia la opera :operator.',
        'contact' => 'Para cualquier consulta sobre tus datos puedes escribir a :contact.',
    ],

    'privacy' => [
        'title' => 'Privacidad',
        'intro' => 'Esta instancia de Nexo Events es open source y self-hosted. Recogemos lo mínimo para que un evento funcione, y nada más. No usamos cookies de seguimiento, no hay analítica de terceros y no se envía información a redes publicitarias.',
        'sections' => [
            [
                'h' => 'Qué guardamos de los organizadores',
                'p' => 'Nombre, email y una versión cifrada (hash) de la contraseña. El email se usa para verificar la cuenta, recuperar el acceso y avisarte de cosas de tus propios eventos. Si inicias sesión con Nexo ID, guardamos además el identificador que ese servicio nos da para reconocerte.',
            ],
            [
                'h' => 'Qué guardamos de los asistentes',
                'p' => 'El nombre y el email que escribes al registrarte a un evento, el idioma en el que te registraste —para escribirte en ese idioma y no en otro— y si entraste o no. No creamos una cuenta ni te pedimos contraseña. Esos datos son visibles para el organizador de ese evento: es quien necesita saber quién va.',
            ],
            [
                'h' => 'Las entradas y su código QR',
                'p' => 'El código de tu entrada es un valor aleatorio sin ningún dato tuyo adentro. En la base de datos guardamos solo su huella (hash), no el código en sí: aunque alguien accediera a la base, no podría fabricar entradas válidas. Si pides que te reenviemos la entrada, se genera un código nuevo y el anterior deja de servir.',
            ],
            [
                'h' => 'Métricas sin cookies',
                'p' => 'Contamos cuántas personas distintas vieron la página de un evento usando una huella que se calcula con la fecha del día y se descarta: no guardamos tu IP ni tu navegador, y la huella de hoy no se puede comparar con la de mañana. No sabemos quién eres ni podemos seguirte entre sitios.',
            ],
            [
                'h' => 'Cookies',
                'p' => 'Solo las necesarias para que la web funcione: la de sesión (para mantenerte identificado si tienes cuenta) y las que recuerdan el idioma y el tema claro/oscuro que elegiste. Ninguna sirve para publicidad ni para seguimiento.',
            ],
            [
                'h' => 'Correos',
                'p' => 'Las entradas y los correos de la cuenta se envían a través de un proveedor de email externo, que necesariamente procesa la dirección de destino y el contenido del mensaje para poder entregarlo.',
            ],
            [
                'h' => 'Cuánto tiempo',
                'p' => 'Los datos de un evento se conservan mientras el organizador mantenga el evento y su cuenta. Al borrarse un evento se borran sus entradas y sus registros asociados.',
            ],
            [
                'h' => 'Tus derechos',
                'p' => 'Puedes pedir acceso a tus datos, su corrección o su borrado escribiendo a quien opera esta instancia (el contacto está en la página de ayuda). Si te registraste a un evento, el organizador de ese evento también puede darte de baja de su lista.',
            ],
            [
                'h' => 'Otras instancias',
                'p' => 'Nexo Events se puede instalar en cualquier servidor. Cada instalación es independiente y responsable de sus propios datos: esta política habla solo de esta instancia.',
            ],
        ],
    ],

    'terms' => [
        'title' => 'Términos de uso',
        'intro' => 'Al usar esta instancia de Nexo Events aceptas lo que sigue. Es un servicio gratuito, ofrecido tal cual está.',
        'sections' => [
            [
                'h' => 'Qué es el servicio',
                'p' => 'Una herramienta para publicar eventos gratuitos, recibir registros por email y validar entradas con QR en la puerta. No procesamos pagos ni vendemos entradas.',
            ],
            [
                'h' => 'Tu cuenta',
                'p' => 'Necesitas una cuenta para crear eventos, y verificar tu email para publicarlos. Eres responsable de lo que pase con tu cuenta y de mantener tu contraseña a salvo.',
            ],
            [
                'h' => 'Responsabilidad sobre tus eventos',
                'p' => 'El contenido de un evento, su veracidad, su realización y el trato de los datos de quienes se registran son responsabilidad del organizador. Quien publica un evento actúa como responsable de esos datos frente a sus asistentes, y debe cumplir la normativa que le corresponda.',
            ],
            [
                'h' => 'Uso indebido',
                'p' => 'No se permite publicar eventos falsos, engañosos, fraudulentos, que suplanten a terceros, que recolecten datos con fines ajenos al evento, ni contenido ilegal. Cualquiera puede reportar un evento, y quien opera esta instancia puede darlo de baja: la página deja de estar disponible, se cierra el registro y las entradas emitidas dejan de validar en la puerta.',
            ],
            [
                'h' => 'Disponibilidad',
                'p' => 'El servicio se ofrece sin garantías de disponibilidad. Hacemos lo razonable para que esté en línea, sobre todo durante los eventos, pero puede haber interrupciones. Un evento con conectividad dudosa en la puerta debería tener siempre un plan alternativo.',
            ],
            [
                'h' => 'Límite de responsabilidad',
                'p' => 'Quien opera esta instancia no se hace responsable de daños derivados del uso del servicio, incluidos eventos que no se realicen, entradas que no se puedan validar o pérdidas de datos.',
            ],
            [
                'h' => 'Software libre',
                'p' => 'Nexo Events se distribuye con licencia MIT: puedes leer el código, modificarlo y alojar tu propia instancia. El software se entrega sin garantías, según indica esa licencia.',
            ],
            [
                'h' => 'Cambios',
                'p' => 'Estos términos pueden cambiar. La fecha de arriba indica la última actualización.',
            ],
        ],
    ],
];
