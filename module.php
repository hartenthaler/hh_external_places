<?php

declare(strict_types=1);

// The dedicated external-information handler may be dispatched before the
// module's boot() method. Register its view namespace during module loading
// so webtrees can render it with the normal page layout on all supported
// versions.
\Fisharebest\Webtrees\View::registerNamespace(
    'hh_external_places',
    __DIR__ . '/resources/views/',
);
// Vesta resolves its shared-place templates by module name. Register these
// replacements during loading as well as in boot(), so the tab is available
// even when the shared-place request is rendered before module boot order.
foreach (['vesta_shared_places', 'vesta_shared_places_20', 'Vesta_Views_Namespace'] as $vestaNamespace) {
    \Fisharebest\Webtrees\View::registerCustomView(
        $vestaNamespace . '::shared-place-page-links',
        'hh_external_places::shared-place-page-links',
    );
    \Fisharebest\Webtrees\View::registerCustomView(
        $vestaNamespace . '::shared-place-page_20',
        'hh_external_places::shared-place-page_20',
    );
}

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\ExternalPlacesModule;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\VestaExternalPlacesModule;
use Composer\Autoload\ClassLoader;

// webtrees loads custom modules independently.  Register Vesta's classes
// before loading our class declaration, which implements Vesta's hook API.
$vestaAutoload = dirname(__DIR__) . '/vesta_common/autoload.php';
if (is_file($vestaAutoload)) {
    require_once $vestaAutoload;
}

$loader = new ClassLoader();
$loader->addPsr4(
    'Hartenthaler\\Webtrees\\Module\\ExternalPlacesModule\\',
    __DIR__ . DIRECTORY_SEPARATOR . 'src',
);
$loader->register();

if (interface_exists(\Vesta\Hook\HookInterfaces\PrintFunctionsPlaceInterface::class)) {
    require __DIR__ . '/src/VestaExternalPlacesModule.php';

    return new VestaExternalPlacesModule();
}

return new ExternalPlacesModule();
