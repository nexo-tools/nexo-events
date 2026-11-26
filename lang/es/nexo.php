<?php

// Strings for the shared Nexo chrome (header, switchers, footer, help).
// Copy into the tool's lang/es/nexo.php and merge with any existing keys.
return [
    'theme' => [
        'light' => 'Cambiar a modo claro',
        'dark' => 'Cambiar a modo oscuro',
    ],
    'locale' => [
        'label' => 'Cambiar idioma',
    ],
    'nav' => [
        'primary' => 'Navegación principal',
    ],
    'switcher' => [
        'label' => 'Ver herramientas Nexo',
        'title' => 'Ecosistema Nexo',
        'soon' => 'Pronto',
        'discover' => 'Conoce todas en Nexo Tools',
        'developers' => 'Para desarrolladores: ver el código',
    ],
    // «powered by X» y «made with X» son las dos formas de NEXO_ATTRIBUTION_LABEL
    // en uso: el footer las traduce y deja X intacto. Cualquier otra frase se
    // muestra tal cual (el footer nunca antepone nada).
    'footer' => [
        'part_of' => 'Parte del ecosistema Nexo',
        'source' => 'Código abierto en GitHub',
        'privacy' => 'Privacidad',
        'terms' => 'Términos',
        'powered_by' => 'con la tecnología de :name',
        'made_with' => 'hecho con :name',
    ],
    // Pie de las páginas públicas de contenido (D8): perfil, evento, reservas.
    'public_footer' => [
        'made_with' => 'Hecho con :app',
        'edit' => 'Editar',
        'edit_label' => 'Editar esta página',
    ],
    // Descripción de cada herramienta del registro (config/nexo-ecosystem.php),
    // traducida. La `tagline` del registro es el respaldo en español y el h1 de
    // cada landing: si cambia una, cambian las dos (nexo-doctor lo verifica).
    'ecosystem' => [
        'nexotools' => 'El hub abierto del ecosistema Nexo.',
        'nexoid' => 'Una sola cuenta para todo el ecosistema Nexo.',
        'nexolinks' => 'Comparte todos tus links en un solo lugar.',
        'nexoagenda' => 'Recibe reservas para tu negocio en minutos.',
        'nexoshort' => 'Acorta enlaces con métricas sin cookies.',
        'nexoevents' => 'Crea eventos gratis y valida entradas con QR.',
    ],
    // PUNTO DE ADAPTACIÓN: el nombre propio del panel de esta app («Tus
    // eventos», «Tus enlaces», «Tu agenda», «Tus links cortos», «Tus
    // herramientas»; en Nexo ID, «Tu cuenta»). Va en el menú de cuenta y en la
    // hoja del celular. La ruta del panel está en config/nexo.php → chrome.
    'app' => [
        'panel' => 'Tus eventos',
    ],
    // Cuenta (chrome v2): el menú de la compu y la hoja del celular. Glosario
    // del STANDARD: «Iniciar sesión», «Crear cuenta», «Tu cuenta Nexo ID»,
    // «Centro de ayuda», «Cerrar sesión» — una palabra por concepto.
    'account' => [
        'menu' => 'Tu cuenta',
        'menu_for' => 'Tu cuenta: :name',
        'panel' => 'Tu panel',
        'nexo_id' => 'Tu cuenta Nexo ID',
        'sign_in' => 'Iniciar sesión',
        'sign_up' => 'Crear cuenta',
        'sign_out' => 'Cerrar sesión',
    ],
    'mobile' => [
        'open' => 'Abrir menú',
        'close' => 'Cerrar menú',
        'title' => 'Menú',
        'language' => 'Idioma',
        'theme' => 'Tema',
    ],
    'auth' => [
        'continue' => 'Continuar con Nexo ID',
        'or_password' => 'o usa tu email y contraseña',
        'use_password' => 'Usar email y contraseña',
        'no_account' => '¿No tienes una cuenta?',
        'transition_until' => 'Hasta el :date puedes entrar también con tu email y contraseña. Después, solo con Nexo ID.',
    ],
    'help' => [
        'title' => 'Centro de ayuda',
        'intro' => '¿Tienes una duda? Empieza por aquí.',
        'contact_title' => '¿No encontraste lo que buscabas?',
        'contact_cta' => 'Escríbenos',
    ],
];
