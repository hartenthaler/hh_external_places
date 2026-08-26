<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Validator;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\ExternalPlacesModule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Vesta\Model\PlaceStructure;

/** Display external provider data on a dedicated shared-place page. */
final class ExternalInformationPage implements RequestHandlerInterface
{
    use ViewResponseTrait;

    public function __construct(private readonly ModuleService $moduleService)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $xref = Validator::attributes($request)->isXref()->string('xref');
        $location = Auth::checkLocationAccess(Registry::locationFactory()->make($xref, $tree), false);
        $module = $this->moduleService->findByInterface(ModuleConfigInterface::class)
            ->first(static fn ($candidate): bool => $candidate instanceof ExternalPlacesModule);

        if (!$module instanceof ExternalPlacesModule) {
            return response('External Places module is not available.', 503);
        }

        $canonical = $location->primaryPlace()->gedcomName();
        $place = PlaceStructure::fromNameAndLocNow($canonical, $location->xref(), $tree, 0, $location);
        $content = $place === null ? '' : ($module->externalInformationForPlace($place)?->getMain() ?? '');

        // Register the view namespace here as well as during module boot.  A
        // request can reach this handler before the normal module boot order
        // has registered custom namespaces (notably on webtrees 2.2).
        \Fisharebest\Webtrees\View::registerNamespace(
            'hh_external_places',
            dirname(__DIR__, 2) . '/resources/views/',
        );

        return $this->viewResponse('hh_external_places::external-information', [
            'content' => $content,
            'location' => $location,
            'title' => 'External information',
            'tree' => $tree,
        ]);
    }
}
