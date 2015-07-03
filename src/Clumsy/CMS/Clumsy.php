<?php namespace Clumsy\CMS;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Cartalyst\Sentry\Facades\Laravel\Sentry;
use Clumsy\Assets\Facade as Asset;

class Clumsy {

    protected $app;

    protected $view;

    protected $admin_prefix;

    public function __construct(Application $app)
    {
        $this->app = $app;
        
        $admin_locale = $this->app['config']->get('clumsy::admin_locale');
        $this->app['config']->set('app.locale', $admin_locale);
        $this->app->setLocale($admin_locale);

        $this->app->register('Cartalyst\Sentry\SentryServiceProvider');

        // Bind view resolver conditionally so we can provide legacy support for Laravel 4.1
        $this->app->bind('clumsy.view-resolver-factory',
            class_exists('Illuminate\View\Environment')
                ? 'Illuminate\View\Environment' // Laravel 4.1
                : 'Illuminate\View\Factory'     // Laravel 4.2+
        );
        $this->app['clumsy.view-resolver'] = $this->app->make('Clumsy\CMS\Support\ViewResolver');
        $this->view = $this->app['clumsy.view-resolver'];

        $this->app->instance('clumsy', $this);

        $path = __DIR__.'/../..';
        require $path.'/macros/admin/html.php';
        require $path.'/macros/admin/form.php';

        $this->admin_prefix = $this->app->runningInConsole() ? null : RouteFacade::getCurrentRoute()->getPrefix();
    }

    public function boot(Route $route, Request $request, $filters = null)
    {
        if (!$filters)
        {
            $filters = 'auth+assets+user';
        }
        elseif ($filters === 'init')
        {
            return;
        }

        $filters = explode('+', $filters);

        foreach ($filters as $filter)
        {
            if (method_exists($this, $filter))
            {
                $response = $this->{$filter}($route, $request);
                if ($response instanceof SymfonyResponse)
                {
                    return $response;
                }
            }
        }
    }

    public function auth(Route $route, Request $request)
    {
        if (!Sentry::check())
        {
            return Redirect::guest(route('clumsy.login'));
        }
    }

    public function assets(Route $route, Request $request)
    {
        View::share(array(
            'admin_prefix'   => $route->getPrefix(),
            'navbar_wrapper' => $this->view->resolve('navbar-wrapper'),
            'navbar'         => $this->view->resolve('navbar'),
            'view'           => $this->view,
            'columns'        => $this->app['config']->get('clumsy::default_columns'),
            'alert'          => Session::get('alert', false),
            'alert_status'   => Session::get('alert_status', 'warning'),
            'body_class'     => str_replace('.', '-', $route->getName()),
        ));

        Asset::enqueue('admin.css');
        Asset::enqueue('admin.js');
        Asset::json('admin', array(
            'urls' => array(
                'base'   => URL::to($route->getPrefix()),
                'update' => URL::route('clumsy.update'),
            ),
            'strings' => array(
                'filter_no_results'   => trans('clumsy::fields.filter-no-results'),
                'delete_confirm'      => trans('clumsy::alerts.delete_confirm'),
                'delete_confirm_user' => trans('clumsy::alerts.user.delete_confirm'),
            ),
        ));
    }

    public function user(Route $route, Request $request)
    {
        $user = Sentry::getUser();

        $username = array_filter(array(
            $user->first_name,
            $user->last_name,
        ));

        if (!count($username))
        {
            $username = (array)$user->email;
        }

        $usergroup = str_singular($user->getGroups()->first()->name);

        if (Lang::has('clumsy::fields.roles.'.Str::lower($usergroup)))
        {
            $usergroup = trans('clumsy::fields.roles.'.Str::lower($usergroup));
        }

        View::share(array(
            'user'      => $user,
            'username'  => implode(' ', $username),
            'usergroup' => $usergroup,
        ));
    }

    public function prefix()
    {
        return $this->admin_prefix;
    }
}