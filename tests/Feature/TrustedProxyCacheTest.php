<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TrustedProxyCacheTest extends TestCase
{
    public function test_dotenv_proxy_trust_survives_configuration_caching(): void
    {
        $relative = 'storage/framework/testing/proxy-'.bin2hex(random_bytes(8));
        $directory = base_path($relative);
        File::makeDirectory($directory, 0755, true);
        file_put_contents($directory.'/.env', "APP_ENV=testing\nTRUSTED_PROXIES=10.0.0.2\n");
        $bootstrap = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->useEnvironmentPath($argv[1]); ';
        $environment = ['APP_CONFIG_CACHE' => $relative.'/config.php', 'TRUSTED_PROXIES' => false];
        try {
            $cache = new Process([PHP_BINARY, '-r', $bootstrap.'$app->make(Illuminate\Contracts\Console\Kernel::class)->call("config:cache");', $directory], base_path(), $environment);
            $cache->mustRun();
            $probe = new Process([PHP_BINARY, '-r', $bootstrap.'
                $app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
                $request = Illuminate\Http\Request::create("http://veritas.example.test/login", "GET", [], [], [], ["REMOTE_ADDR" => "10.0.0.2", "HTTP_X_FORWARDED_PROTO" => "https"]);
                $app->make(Illuminate\Http\Middleware\TrustProxies::class)->handle($request, function ($request) { echo $request->isSecure() ? "secure" : "insecure"; });
            ', $directory], base_path(), $environment);
            $probe->mustRun();
            $this->assertSame('secure', $probe->getOutput());
        } finally {
            File::delete($directory.'/.env', $directory.'/config.php');
            rmdir($directory);
        }
    }
}
