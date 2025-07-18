<?php

namespace App\Providers;

use App\Infrastructure\Persistence\Facades\FacadesUserRepository;
use App\Infrastructure\Persistence\UserRepository;
use App\Infrastructure\Services\FileService;
use App\Infrastructure\Services\S3FileService;
use Aws\S3\S3Client;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(UserRepository::class, FacadesUserRepository::class);
        $this->app->bind(FileService::class, function () {
            $config = config('services.s3');
            $s3Client = new S3Client($config);

            return new S3FileService($s3Client);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

    }
}
