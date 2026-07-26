import Alpine from 'alpinejs';
import './nexo-ui.js'; // registers the shared chrome Alpine data (nexoTheme, nexoMenu)
import './nexo-beacon.js'; // no-op unless NEXO_BEACON_ENABLED renders its metas
import { initScanner } from './scanner.js'; // no-op unless the door scanner is on the page

window.Alpine = Alpine;
Alpine.start();

initScanner();
