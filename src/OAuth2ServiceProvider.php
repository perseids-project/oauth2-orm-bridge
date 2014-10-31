<?php

/**
 *  Modification so this works in a more various environment and can be plugged to both simple users and perseids/clients-manager
 *
 *
 * 
 * Original headers :
 *
 * 
 * This file is part of the authbucket/oauth2-php package.
 *
 * (c) Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Perseids\OAuth2;

use Perseids\OAuth2\Entity\ModelManagerFactory;
use SimpleUser\UserManager;

use AuthBucket\OAuth2\Controller\AuthorizeController;
use AuthBucket\OAuth2\Controller\ClientController;
use AuthBucket\OAuth2\Controller\OAuth2Controller;
use AuthBucket\OAuth2\Controller\ScopeController;
use AuthBucket\OAuth2\EventListener\ExceptionListener;
use AuthBucket\OAuth2\GrantType\GrantTypeHandlerFactory;
use AuthBucket\OAuth2\ResourceType\ResourceTypeHandlerFactory;
use AuthBucket\OAuth2\ResponseType\ResponseTypeHandlerFactory;
use AuthBucket\OAuth2\Security\Authentication\Provider\ResourceProvider;
use AuthBucket\OAuth2\Security\Authentication\Provider\TokenProvider;
use AuthBucket\OAuth2\Security\Firewall\ResourceListener;
use AuthBucket\OAuth2\Security\Firewall\TokenListener;
use AuthBucket\OAuth2\TokenType\TokenTypeHandlerFactory;
use Silex\Application;
use Silex\ControllerProviderInterface;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpKernel\KernelEvents;

use Perseids\OAuth2\Entity\UserRepository;

/**
 * OAuth2 service provider as plugin for Silex SecurityServiceProvider.
 *
 * @author Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 */
class OAuth2ServiceProvider implements ServiceProviderInterface, ControllerProviderInterface
{
    protected $em;
    protected $userManager;

    public function __construct(Application $app, UserManager $UserManager) {
        $this->em = new OAuth2EntityManager($app);
        $this->userManager = $UserManager;
    }

    public function register(Application $app) {
        $app['authbucket_oauth2.model'] = array(
            'access_token' => 'Perseids\\OAuth2\\Entity\\AccessToken',
        );

        $app['authbucket_oauth2.model_manager.factory'] = $this->em->getModelManagerFactory();

        // (Optional) For using grant_type = password, override this parameter
        // with your own user provider, e.g. using InMemoryUserProvider or a
        // Doctrine ORM EntityRepository that implements UserProviderInterface.
        $app['authbucket_oauth2.user_provider'] = $this->userManager;

        // Add default response type handler.
        $app['authbucket_oauth2.response_handler'] = array(
            'code' => 'AuthBucket\\OAuth2\\ResponseType\\CodeResponseTypeHandler',
            'token' => 'AuthBucket\\OAuth2\\ResponseType\\TokenResponseTypeHandler',
        );

        // Add default grant type handler.
        $app['authbucket_oauth2.grant_handler'] = array(
            'authorization_code' => 'AuthBucket\\OAuth2\\GrantType\\AuthorizationCodeGrantTypeHandler',
            'client_credentials' => 'AuthBucket\\OAuth2\\GrantType\\ClientCredentialsGrantTypeHandler',
            'password' => 'AuthBucket\\OAuth2\\GrantType\\PasswordGrantTypeHandler',
            'refresh_token' => 'AuthBucket\\OAuth2\\GrantType\\RefreshTokenGrantTypeHandler',
        );

        // Add default token type handler.
        $app['authbucket_oauth2.token_handler'] = array(
            'bearer' => 'AuthBucket\\OAuth2\\TokenType\\BearerTokenTypeHandler',
            'mac' => 'AuthBucket\\OAuth2\\TokenType\\MacTokenTypeHandler',
        );

        // Add default resource type handler.
        $app['authbucket_oauth2.resource_handler'] = array(
            'model' => 'AuthBucket\\OAuth2\\ResourceType\\ModelResourceTypeHandler',
            'debug_endpoint' => 'AuthBucket\\OAuth2\\ResourceType\\DebugEndpointResourceTypeHandler',
        );

        $app['authbucket_oauth2.exception_listener'] = $app->share(function () {
            return new ExceptionListener();
        });

        $app['authbucket_oauth2.response_handler.factory'] = $app->share(function ($app) {
            return new ResponseTypeHandlerFactory(
                $app['security'],
                $app['validator'],
                $app['authbucket_oauth2.model_manager.factory'],
                $app['authbucket_oauth2.token_handler.factory'],
                $app['authbucket_oauth2.response_handler']
            );
        });

        $app['authbucket_oauth2.grant_handler.factory'] = $app->share(function ($app) {
            return new GrantTypeHandlerFactory(
                $app['security'],
                $app['security.user_checker'],
                $app['security.encoder_factory'],
                $app['validator'],
                $app['authbucket_oauth2.model_manager.factory'],
                $app['authbucket_oauth2.token_handler.factory'],
                $app['authbucket_oauth2.user_provider'],
                $app['authbucket_oauth2.grant_handler']
            );
        });

        $app['authbucket_oauth2.token_handler.factory'] = $app->share(function ($app) {
            return new TokenTypeHandlerFactory(
                $app['validator'],
                $app['authbucket_oauth2.model_manager.factory'],
                $app['authbucket_oauth2.token_handler']
            );
        });

        $app['authbucket_oauth2.resource_handler.factory'] = $app->share(function ($app) {
            return new ResourceTypeHandlerFactory(
                $app,
                $app['authbucket_oauth2.model_manager.factory'],
                $app['authbucket_oauth2.resource_handler']
            );
        });

        $app['authbucket_oauth2.oauth2_controller'] = $app->share(function () use ($app) {
            return new OAuth2Controller(
                $app['security'],
                $app['validator'],
                $app['authbucket_oauth2.model_manager.factory'],
                $app['authbucket_oauth2.response_handler.factory'],
                $app['authbucket_oauth2.grant_handler.factory']
            );
        });

        $app['authbucket_oauth2.authorize_controller'] = $app->share(function () use ($app) {
            return new AuthorizeController(
                $app['validator'],
                $app['serializer'],
                $app['authbucket_oauth2.model_manager.factory']
            );
        });

        $app['authbucket_oauth2.client_controller'] = $app->share(function () use ($app) {
            return new ClientController(
                $app['validator'],
                $app['serializer'],
                $app['authbucket_oauth2.model_manager.factory']
            );
        });

        $app['authbucket_oauth2.scope_controller'] = $app->share(function () use ($app) {
            return new ScopeController(
                $app['validator'],
                $app['serializer'],
                $app['authbucket_oauth2.model_manager.factory']
            );
        });

        $app['security.authentication_provider.oauth2_token._proto'] = $app->protect(function ($name, $options) use ($app) {
            return $app->share(function () use ($app, $name, $options) {
                return new TokenProvider(
                    $name,
                    $app['authbucket_oauth2.model_manager.factory']
                );
            });
        });

        $app['security.authentication_listener.oauth2_token._proto'] = $app->protect(function ($name, $options) use ($app) {
            return $app->share(function () use ($app, $name, $options) {
                return new TokenListener(
                    $name,
                    $app['security'],
                    $app['security.authentication_manager'],
                    $app['validator']
                );
            });
        });

        $app['security.authentication_provider.oauth2_resource._proto'] = $app->protect(function ($name, $options) use ($app) {
            return $app->share(function () use ($app, $name, $options) {
                return new ResourceProvider(
                    $name,
                    $app['authbucket_oauth2.resource_handler.factory'],
                    $options['resource_type'],
                    $options['scope'],
                    $options['options']
                );
            });
        });

        $app['security.authentication_listener.oauth2_resource._proto'] = $app->protect(function ($name, $options) use ($app) {
            return $app->share(function () use ($app, $name, $options) {
                return new ResourceListener(
                    $name,
                    $app['security'],
                    $app['security.authentication_manager'],
                    $app['validator'],
                    $app['authbucket_oauth2.token_handler.factory']
                );
            });
        });

        $app['security.authentication_listener.factory.oauth2_token'] = $app->protect(function ($name, $options) use ($app) {
            if (!isset($app['security.authentication_provider.'.$name.'.oauth2_token'])) {
                $app['security.authentication_provider.'.$name.'.oauth2_token'] = $app['security.authentication_provider.oauth2_token._proto']($name, $options);
            }

            if (!isset($app['security.authentication_listener.'.$name.'.oauth2_token'])) {
                $app['security.authentication_listener.'.$name.'.oauth2_token'] = $app['security.authentication_listener.oauth2_token._proto']($name, $options);
            }

            return array(
                'security.authentication_provider.'.$name.'.oauth2_token',
                'security.authentication_listener.'.$name.'.oauth2_token',
                null,
                'pre_auth',
            );
        });

        $app['security.authentication_listener.factory.oauth2_resource'] = $app->protect(function ($name, $options) use ($app) {
            $options = array_merge(array(
                'resource_type' => 'model',
                'scope' => array(),
                'options' => array(),
            ), (array) $options);

            if (!isset($app['security.authentication_provider.'.$name.'.oauth2_resource'])) {
                $app['security.authentication_provider.'.$name.'.oauth2_resource'] = $app['security.authentication_provider.oauth2_resource._proto']($name, $options);
            }

            if (!isset($app['security.authentication_listener.'.$name.'.oauth2_resource'])) {
                $app['security.authentication_listener.'.$name.'.oauth2_resource'] = $app['security.authentication_listener.oauth2_resource._proto']($name, $options);
            }

            return array(
                'security.authentication_provider.'.$name.'.oauth2_resource',
                'security.authentication_listener.'.$name.'.oauth2_resource',
                null,
                'pre_auth',
            );
        });
    }

    public function connect(Application $app)
    {
        $controllers = $app['controllers_factory'];

        $controllers->get('/authorize', 'authbucket_oauth2.oauth2_controller:authorizeAction')
            ->bind('api_oauth2_authorize');

        $controllers->post('/token', 'authbucket_oauth2.oauth2_controller:tokenAction')
            ->bind('api_oauth2_token');

        $controllers->match('/debug', 'authbucket_oauth2.oauth2_controller:debugAction')
            ->bind('api_oauth2_debug')
            ->method('GET|POST');

        $controllers->get('/cron', 'authbucket_oauth2.oauth2_controller:cronAction')
            ->bind('api_oauth2_cron');

        foreach (array('authorize', 'client', 'scope') as $type) {
            $controllers->post('/'.$type.'.{_format}', 'authbucket_oauth2.'.$type.'_controller:createAction')
                ->bind('api_'.$type.'_create')
                ->assert('_format', 'json|xml');

            $controllers->get('/'.$type.'/{id}.{_format}', 'authbucket_oauth2.'.$type.'_controller:readAction')
                ->bind('api_'.$type.'_read')
                ->assert('_format', 'json|xml');

            $controllers->put('/'.$type.'/{id}.{_format}', 'authbucket_oauth2.'.$type.'_controller:updateAction')
                ->bind('api_'.$type.'_update')
                ->assert('_format', 'json|xml');

            $controllers->delete('/'.$type.'/{id}.{_format}', 'authbucket_oauth2.'.$type.'_controller:deleteAction')
                ->bind('api_'.$type.'_delete')
                ->assert('_format', 'json|xml');

            $controllers->get('/'.$type.'.{_format}', 'authbucket_oauth2.'.$type.'_controller:listAction')
                ->bind('api_'.$type.'_list')
                ->assert('_format', 'json|xml');
        }

        return $controllers;
    }

    public function boot(Application $app)
    {
        $app['dispatcher']->addListener(KernelEvents::EXCEPTION, array($app['authbucket_oauth2.exception_listener'], 'onKernelException'), -8);
    }
}
?>