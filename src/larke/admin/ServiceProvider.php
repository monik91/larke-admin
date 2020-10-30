<?php

namespace Larke\Admin;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Illuminate\Support\Facades\Response;

use Larke\Admin\Command\Install;
use Larke\Admin\Command\ImportRoute;
use Larke\Admin\Command\ResetPasword;
use Larke\Admin\Command\PassportLogout;

use Larke\Admin\Contracts\Response as ResponseContract;
use Larke\Admin\Contracts\Jwt as JwtContract;
use Larke\Admin\Jwt\Jwt;
use Larke\Admin\Http\Response as ResponseHttp;
use Larke\Admin\Service\Cache as CacheService;
use Larke\Admin\Auth\Admin as AdminData;

use Larke\Admin\Model\AdminLog as AdminLogModel;
use Larke\Admin\Model\Attachment as AttachmentModel;
use Larke\Admin\Model\AuthGroupAccess as AuthGroupAccessModel;
use Larke\Admin\Model\AuthRuleAccess as AuthRuleAccessModel;
use Larke\Admin\Observer\AdminLog as AdminLogObserver;
use Larke\Admin\Observer\Attachment as AttachmentObserver;
use Larke\Admin\Observer\AuthGroupAccess as AuthGroupAccessObserver;
use Larke\Admin\Observer\AuthRuleAccess as AuthRuleAccessObserver;

class ServiceProvider extends BaseServiceProvider
{
    protected $commands = [
        Install::class,
        ImportRoute::class,
        ResetPasword::class,
        PassportLogout::class,
    ];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'larke.admin.auth' => Middleware\Authenticate::class,
        'larke.admin.auth.admin' => Middleware\AdminCheck::class,
        'larke.admin.permission' => Middleware\Permission::class,
        'larke.admin.log' => Middleware\Log::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'larke.admin' => [
            'larke.admin.auth',
            'larke.admin.permission',
            'larke.admin.log',
        ],
    ];
    
    /**
     * {@inheritdoc}
     */
    public function register()
    {
        $this->registerConfig();

        $this->registerBind();
        
        $this->registerPublishing();
        
        $this->registerRouteMiddleware();
        
        $this->commands($this->commands);
    }

    /**
     * {@inheritdoc}
     */
    public function boot()
    {
        $this->ensureHttps();
        
        $this->loadViewsFrom(__DIR__ . '/../resource/views', 'larke-admin');
        
        $this->loadRoutesFrom(__DIR__ . '/../resource/routes/admin.php');

        $this->bootObserver();
    }

    /**
     * Force to set https scheme if https enabled.
     *
     * @return void
     */
    protected function ensureHttps()
    {
        if (config('larke.https') || config('larke.secure')) {
            url()->forceScheme('https');
            $this->app['request']->server->set('HTTPS', true);
        }
    }

    /**
     * Boot Observer.
     *
     * @return void
     */
    protected function bootObserver()
    {
        AdminLogModel::observe(new AdminLogObserver());
        
        AttachmentModel::observe(new AttachmentObserver());
        
        AuthGroupAccessModel::observe(new AuthGroupAccessObserver());
        
        AuthRuleAccessModel::observe(new AuthRuleAccessObserver());
    }
    
    /**
     * Register the config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__ . '/../resource/config/larke.php', 'larke');
    }
    
    /**
     * Register the bind.
     *
     * @return void
     */
    protected function registerBind()
    {
        // json响应
        $this->app->bind('larke.json', ResponseContract::class);
        $this->app->bind(ResponseContract::class, function() {
            $ResponseHttp = new ResponseHttp();
            
            $config = config('larke.response.json');
            $ResponseHttp->withIsAllowOrigin($config['is_allow_origin'])
                ->withAllowOrigin($config['allow_origin'])
                ->withAllowCredentials($config['allow_credentials'])
                ->withMaxAge($config['max_age'])
                ->withAllowMethods($config['allow_methods'])
                ->withAllowHeaders($config['allow_headers']);
            
            return $ResponseHttp;
        });
        
        // 系统使用缓存
        $this->app->singleton('larke.cache', function() {
            $CacheService = new CacheService();
            return $CacheService->store();
        });
        
        // 管理员登陆信息
        $this->app->singleton('larke.admin', AdminData::class);
        
        // jwt
        $this->app->bind('larke.jwt', JwtContract::class);
        $this->app->bind(JwtContract::class, function() {
            $Jwt = new Jwt();
            $config = config('larke.jwt');

            $Jwt->withAlg($config['alg']);
            $Jwt->withIss($config['iss']);
            $Jwt->withAud($config['aud']);
            $Jwt->withSub($config['sub']);
            
            $Jwt->withJti($config['jti']); // JWT ID
            $Jwt->withExpTime(intval($config['exptime']));
            $Jwt->withNotBeforeTime($config['notbeforetime']);
            
            $Jwt->withSignerType($config['signer_type']);
            $Jwt->withSecrect($config['secrect']);
            $Jwt->withPrivateKey($config['private_key']);
            $Jwt->withPublicKey($config['public_key']);
            
            return $Jwt;
        });
        
        // response()->success('success');
        Response::macro('success', function($message = '获取成功', $data = null, $code = 0) {
            return app('larke.json')->json(true, $code, $message, $data);
        });
        
        // response()->error('error');
        Response::macro('error', function($message = null, $code = 1, $data = []) {
            return app('larke.json')->json(false, $code, $message, $data);
        });
        
        // 扩展
        $this->app->singleton('larke.extension', Extension::class);
    }
    
    /**
     * Register the publishing.
     *
     * @return void
     */
    protected function registerPublishing()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resource/config/larke.php' => config_path('larke.php'),
            ], 'larke-admin-config');
        }
    }
    
    /**
     * Register the route middleware.
     *
     * @return void
     */
    protected function registerRouteMiddleware()
    {
        // register route middleware.
        foreach ($this->routeMiddleware as $key => $middleware) {
            app('router')->aliasMiddleware($key, $middleware);
        }

        // register middleware group.
        foreach ($this->middlewareGroups as $key => $middleware) {
            app('router')->middlewareGroup($key, $middleware);
        }
    }
    
}
