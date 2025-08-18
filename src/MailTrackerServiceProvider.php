<?php

namespace jdavidbakr\MailTracker;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\EventDispatcher\EventDispatcher;

class MailTrackerServiceProvider extends ServiceProvider
{
    /**
     * Check to see if we're using lumen or laravel.
     *
     * @return bool
     */
    public function isLumen()
    {
        $lumenClass = 'Laravel\Lumen\Application';
        return ($this->app instanceof $lumenClass);
    }

    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish pieces
        $this->publishConfig();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->publishViews();

        // Hook into the mailer using Symfony Mailer events
        $this->registerSymfonyMailerPlugin();

        // Install the routes
        $this->installRoutes();
    }

    /**
     * Register any package services.
     *
     * @return void
     */
    public function register()
    {
        // Merge config
        $this->mergeConfigFrom(__DIR__.'/../config/mail-tracker.php', 'mail-tracker');
    }

    /**
     * Publish the configuration files
     *
     * @return void
     */
    protected function publishConfig()
    {
        if (!$this->isLumen()) {
            $this->publishes([
                __DIR__.'/../config/mail-tracker.php' => config_path('mail-tracker.php')
            ], 'config');
        }
    }

    /**
     * Publish the views
     *
     * @return void
     */
    protected function publishViews()
    {
        if (!$this->isLumen()) {
            $this->loadViewsFrom(__DIR__.'/views', 'emailTrakingViews');
            $this->publishes([
                __DIR__.'/views' => base_path('resources/views/vendor/emailTrakingViews'),
                ]);
        }
    }

    /**
     * Register the Symfony Mailer event subscriber
     *
     * @return void
     */
    protected function registerSymfonyMailerPlugin()
    {
        $this->app->resolving('mail.manager', function ($mailManager) {
            // Get the default mailer
            $mailer = $mailManager->mailer();
            
            // Get the Symfony mailer instance
            $symfonyMailer = $mailer->getSymfonyTransport();
            
            // If it has an event dispatcher, add our subscriber
            if (method_exists($symfonyMailer, 'getDispatcher')) {
                $dispatcher = $symfonyMailer->getDispatcher();
                if ($dispatcher) {
                    $dispatcher->addSubscriber(new MailTracker());
                }
            }
        });

        // Alternative approach for Laravel's mail events
        $this->app['events']->listen(\Illuminate\Mail\Events\MessageSending::class, function ($event) {
            try {
                // Create a MailTracker instance and handle the message
                $tracker = new MailTracker();
                
                // Get the Symfony message from the Laravel event
                if (isset($event->message)) {
                    $message = $event->message;
                } else {
                    \Log::warning('MailTracker: Could not get message from event');
                    return;
                }
                
                // Handle the tracking for this message
                if ($message instanceof \Symfony\Component\Mime\Email) {
                    $tracker->createTrackers($message);
                    $tracker->purgeOldRecords();
                } else {
                    \Log::warning('MailTracker: Message is not a Symfony Email instance, got: ' . get_class($message));
                }
            } catch (\Exception $e) {
                // Log error but don't break email sending
                \Log::error('MailTracker error in MessageSending: ' . $e->getMessage());
            }
        });
    }


    /**
     * Install the needed routes
     *
     * @return void
     */
    protected function installRoutes()
    {
        $config = $this->app['config']->get('mail-tracker.route', []);
        $config['namespace'] = 'jdavidbakr\MailTracker';

        if (!$this->isLumen()) {
            // Register routes for all domains
            Route::group($config, function () {
                Route::get('t/{hash}', 'MailTrackerController@getT')->name('mailTracker_t');
                Route::get('l/{url}/{hash}', 'MailTrackerController@getL')->name('mailTracker_l');
                Route::get('n', 'MailTrackerController@getN')->name('mailTracker_n');
                Route::post('sns', 'SNSController@callback')->name('mailTracker_SNS');
            });
        } else {
            $app = $this->app;
            $app->group($config, function () use ($app) {
                $app->get('t/{hash}', 'MailTrackerController@getT');
                $app->get('l/{url}/{hash}', 'MailTrackerController@getL');
                $app->get('n', 'MailTrackerController@getN');
                $app->post('sns', 'SNSController@callback');
            });
        }
        
        // Install the Admin routes
        $config_admin = $this->app['config']->get('mail-tracker.admin-route', []);
        $config_admin['namespace'] = 'jdavidbakr\MailTracker';

        if (Arr::get($config_admin, 'enabled', true)) {
            if (!$this->isLumen()) {
                Route::group($config_admin, function () {
                    Route::get('/', 'AdminController@getIndex')->name('mailTracker_Index');
                    Route::post('search', 'AdminController@postSearch')->name('mailTracker_Search');
                    Route::get('clear-search', 'AdminController@clearSearch')->name('mailTracker_ClearSearch');
                    Route::get('show-email/{id}', 'AdminController@getShowEmail')->name('mailTracker_ShowEmail');
                    Route::get('url-detail/{id}', 'AdminController@getUrlDetail')->name('mailTracker_UrlDetail');
                    Route::get('smtp-detail/{id}', 'AdminController@getSmtpDetail')->name('mailTracker_SmtpDetail');
                });
            } else {
                $app = $this->app;
                $app->group($config_admin, function () use ($app) {
                    $app->get('/', 'AdminController@getIndex');
                    $app->post('search', 'AdminController@postSearch');
                    $app->get('clear-search', 'AdminController@clearSearch');
                    $app->get('show-email/{id}', 'AdminController@getShowEmail');
                    $app->get('url-detail/{id}', 'AdminController@getUrlDetail');
                    $app->get('smtp-detail/{id}', 'AdminController@getSmtpDetail');
                });
            }
        }
    }
}