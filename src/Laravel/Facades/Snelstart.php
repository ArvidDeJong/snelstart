<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Laravel\Facades;

use Darvis\Snelstart\Client;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Darvis\Snelstart\Client withClientKey(string $clientKey)
 * @method static \Darvis\Snelstart\Config config()
 * @method static mixed get(string $path, array<string, mixed> $query = [])
 * @method static mixed post(string $path, array<mixed> $data = [], array<string, mixed> $query = [])
 * @method static mixed put(string $path, array<mixed> $data = [], array<string, mixed> $query = [])
 * @method static mixed delete(string $path, array<string, mixed> $query = [])
 * @method static \Darvis\Snelstart\Resource resource(string $path, bool $paged = true)
 * @method static \Darvis\Snelstart\Resource actieprijzen()
 * @method static \Darvis\Snelstart\Resource artikelen()
 * @method static \Darvis\Snelstart\Resource artikelomzetgroepen()
 * @method static \Darvis\Snelstart\Resource bankboekingen()
 * @method static \Darvis\Snelstart\Resource btwaangiftes()
 * @method static \Darvis\Snelstart\Resource btwtarieven()
 * @method static \Darvis\Snelstart\Resource dagboeken()
 * @method static \Darvis\Snelstart\Resource documenten()
 * @method static \Darvis\Snelstart\Resource grootboeken()
 * @method static \Darvis\Snelstart\Resource grootboekmutaties()
 * @method static \Darvis\Snelstart\Resource inkoopboekingen()
 * @method static \Darvis\Snelstart\Resource inkoopfacturen()
 * @method static \Darvis\Snelstart\Resource kasboekingen()
 * @method static \Darvis\Snelstart\Resource kostenplaatsen()
 * @method static \Darvis\Snelstart\Resource landen()
 * @method static \Darvis\Snelstart\Resource memoriaalboekingen()
 * @method static \Darvis\Snelstart\Resource offertes()
 * @method static \Darvis\Snelstart\Resource prijsafspraken()
 * @method static \Darvis\Snelstart\Resource relaties()
 * @method static \Darvis\Snelstart\Resource vatratedefinitions()
 * @method static \Darvis\Snelstart\Resource vatrates()
 * @method static \Darvis\Snelstart\Resource verkoopboekingen()
 * @method static \Darvis\Snelstart\Resource verkoopfacturen()
 * @method static \Darvis\Snelstart\Resource verkooporders()
 * @method static \Darvis\Snelstart\Resource verkoopordersjablonen()
 * @method static array<mixed> companyInfo()
 * @method static mixed echo()
 * @method static void forgetToken()
 *
 * @see Client
 */
class Snelstart extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
