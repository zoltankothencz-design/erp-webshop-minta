<?php
declare(strict_types=1);

namespace App\Controller;

use App\Response;
use App\Service\FeedExporter;

/** Nyilvános termék-feedek (a hirdetési rendszerek és marketplace-ek rendszeresen lehúzzák). */
final class FeedController
{
    public function __construct(private FeedExporter $feeds = new FeedExporter())
    {
    }

    public function google(): Response
    {
        return Response::xml($this->feeds->googleMerchant());
    }

    public function marketplace(): Response
    {
        return Response::xml($this->feeds->marketplaceXml());
    }
}
