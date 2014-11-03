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


use Silex\Application;
use Silex\ControllerProviderInterface;
use Silex\ServiceProviderInterface;

use Perseids\OAuth2\OAuth2Controller;

/**
 * OAuth2 service provider as plugin for Silex SecurityServiceProvider.
 *
 * @author Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 */
class OAuth2Authorize implements ServiceProviderInterface, ControllerProviderInterface
{
	public function register(Application $app) {

	}
    public function connect(Application $app)
    {
	    $controllers = $app['controllers_factory'];
	    $controllers->get('/authorize', 'perseids.oauth2.controller:authorizeAction')
	        ->bind('user_authorize');
        return $controllers;
    }
	public function boot(Application $app) {
		
	}
}