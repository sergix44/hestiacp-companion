<?php

namespace App\Providers;

use AcmePhp\Core\AcmeClient;
use AcmePhp\Core\Http\Base64SafeEncoder;
use AcmePhp\Core\Http\SecureHttpClient;
use AcmePhp\Core\Http\SecureHttpClientFactory;
use AcmePhp\Core\Http\ServerErrorHandler;
use AcmePhp\Ssl\KeyPair;
use AcmePhp\Ssl\Parser\KeyParser;
use AcmePhp\Ssl\PrivateKey;
use AcmePhp\Ssl\Signer\DataSigner;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use SleekDB\Store;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Store::class, function (Application $app, array $params) {
            $path = $_SERVER['HOME'] . '/.hcpc/db/';

            if (!file_exists($path)) {
                mkdir($path, 0700, true);
            }

            return new Store($params[0], \Phar::running() ?
                $path :
                storage_path('app'));
        });

        $this->app->singleton(SecureHttpClient::class, function (Application $app, array $params) {
            $factory = new SecureHttpClientFactory(
                new GuzzleClient(),
                new Base64SafeEncoder(),
                new KeyParser(),
                new DataSigner(),
                new ServerErrorHandler()
            );

            $private = new PrivateKey($params[0]);
            $public = $private->getPublicKey();

            $account = new KeyPair($public, $private);

            return $factory->createSecureHttpClient($account);
        });

        $this->app->singleton(AcmeClient::class, function (Application $app, array $params) {
            $api = config('acme.api_url');
            return new AcmeClient($app->make(SecureHttpClient::class, $params), $api);
        });
    }
}
