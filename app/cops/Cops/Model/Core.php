<?php
/*
 * This file is part of Silex Cops. Licensed under WTFPL
 *
 * (c) Mathieu Duplouy <mathieu.duplouy@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Cops\Model;

use Cops\Model\Config;
use Cops\Model\CoreInterface;
use Cops\Provider\MobileDetectServiceProvider;
use Cops\Provider\UrlGeneratorServiceProvider;
use Cops\Provider\ImageProcessorServiceProvider;
use Cops\EventListener\LocaleListener;
use Silex\Application as BaseApplication;
use Silex\Provider\TwigServiceProvider;
use Silex\Provider\DoctrineServiceProvider;
use Silex\Provider\SecurityServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Core class
 * @author Mathieu Duplouy <mathieu.duplouy@gmail.com>
 */
class Core implements CoreInterface
{
    /**
     * Model object instance registry
     * @var array
     */
    protected $_modelInstance = array();

    /**
     * Resource instance
     * @var \Cops\Model\Resource
     */
    protected $_resource;

    /**
     * App instance
     * @var \Silex\Application
     */
    private static $app;

    /**
     * Constructor
     *
     * @param \Silex\Application $app
     *
     * @param string $configFilePath
     */
    public function __construct($configFilePath, BaseApplication $app)
    {
        // Always instanciate configuration, so no closure use
        $app['config'] = new Config($configFilePath);

        if ($app['config']->getValue('debug')) {
            $app['debug'] = true;
        }

        // Register mobile detect service
        $app->register(new MobileDetectServiceProvider());

        // Image processor service
        $app->register(new ImageProcessorServiceProvider());

        // Detect mobile user agent
        if ($app['mobile_detect']->isMobile()) {
            $app['config']->setTemplatePrefix($app['config']->getValue('mobile_theme'));
        }

        // Register twig service
        $app->register(new TwigServiceProvider(), array(
            'twig.path' => array(
                BASE_DIR . 'themes/' . $app['config']->getValue('theme'),
                __DIR__ . '/../Templates',
            ),
            'twig.options' => array(
                'cache' => realpath(BASE_DIR . 'cache'),
            )
        ));

        // Register doctrine DBAL service
        $app->register(new DoctrineServiceProvider(), array(
            'db.options' => array(
                'driver'   => 'pdo_sqlite',
                'path'     => BASE_DIR . $app['config']->getValue('data_dir') . '/metadata.db',
            ),
        ));

        // Register security provider
        $app->register(new SecurityServiceProvider(), array(
            'security.firewalls' => array(
                'admin' => array(
                    'pattern' => '^/admin/',
                    'http' => true,
                    'users' => array(
                        'admin' => array('ROLE_ADMIN', '5FZ2Z8QIkA7UTZ4BYkoC+GsReLf569mSKDsfods6LYQ8t+a8EW9oaircfMpmaLbPBh4FOBiiFyLfuZmTSUwzZg==')
                    )
                ),
            )
        ));

        // echo $app['security.encoder.digest']->encodePassword('password', 'salt');

        $app->register(new \Cops\Provider\SearchServiceProvider());

        // Register session provider
        $app->register(new \Silex\Provider\SessionServiceProvider());

        // Register url generator service
        $app->register(new \Cops\Provider\UrlGeneratorServiceProvider());

        // Register translator
        $app->register(new \Cops\Provider\TranslationServiceProvider(array(
            'default' => $app['config']->getValue('default_lang')
        )));

        $app['translator'] = $app->share($app->extend('translator', function($translator) {
            $translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());

            foreach (array('messages', 'routes') as $domain) {
                $translator->addResource('yaml', BASE_DIR.'locales/fr/'.$domain.'.yml', 'fr', $domain);
                $translator->addResource('yaml', BASE_DIR.'locales/en/'.$domain.'.yml', 'en', $domain);
            }

            return $translator;
        }));

        $app->get('/', function () use ($app) {
            // redirect to /default_lang/
            $redirect = $app['url_generator']->generate('homepage', array(
                '_locale' => $app['config']->getValue('default_lang')
            ));
            return $app->redirect($redirect, 301);
        });

        // Set the mount points for the controllers
        $app->mount('/',            new \Cops\Controller\IndexController());
        $app->mount('/book/',       new \Cops\Controller\BookController());
        $app->mount('/serie/',      new \Cops\Controller\SerieController());
        $app->mount('/author/',     new \Cops\Controller\AuthorController());
        $app->mount('/search/',     new \Cops\Controller\SearchController());

        $app->mount('/admin/',      new \Cops\Controller\AdminController());
        $app->mount('/admin/feed/', new \Cops\Controller\Admin\OpdsFeedController());

        $app->mount('/login/',       new \Cops\Controller\LoginController());
        $app->mount('/opds/',        new \Cops\Controller\OpdsController());

        $app['core'] = $this;

        self::$app = $app;
    }

    /**
     * Simple object loader
     *
     * @param string $className
     * @param array $args
     *
     * @return \Cops\Model\Common
     */
    public function getModel($className, $args = array())
    {
        if (!isset($this->_objecInstance[$className])) {
            $fullClassName = $className;
            if (!class_exists($fullClassName)) {
                $fullClassName = __NAMESPACE__.'\\'.$className;
            }
            if (!class_exists($fullClassName)) {
                throw new \InvalidArgumentException(
                    sprintf('Could not get model %s, class does not exists', $fullClassName)
                );
            }
            $obj = new \ReflectionClass($fullClassName);

            if (!is_array($args)) {
                $args = array($args);
            }
            return $obj->newInstanceArgs($args);
        }
        return $this->_objecInstance[$className];
    }

    /**
     * Resource object loader
     *
     * @return \Cops\Model\Resource\Common
     */
    public function getResource()
    {
        if (is_null($this->_resource)) {
            $this->_resource = $this->getModel(get_called_class().'\\Resource', $this);
        }
        return $this->_resource;
    }

    /**
     * Collection object loader
     *
     * @return \Cops\Model\Collection
     */
    public function getCollection()
    {
        $fullClassName = get_called_class().'\\Collection';
        if (!class_exists($fullClassName)) {
            $fullClassName = __NAMESPACE__.'\\'.$fullClassName;
        }
        if (!class_exists($fullClassName)) {
            throw new \RuntimeException(
                sprintf('Requested collection %s does not exists', $fullClassName)
            );
        }
        return new $fullClassName($this);
    }

    /**
     * App getter
     *
     * @return \Silex\Application
     */
    public static function getApp()
    {
        return self::$app;
    }

    /**
     * Config getter
     *
     * @return \Cops\Model\Config
     */
    public static function getConfig()
    {
        $app = self::getApp();
        return $app['config'];
    }

    /**
     * DBAL connection getter
     *
     * @return Doctrine\DBAL\Connection
     */
    public static function getDb()
    {
        $app = self::getApp();
        return $app['db'];
    }

    /**
     * Get alphabetic letters
     *
     * @return array
     */
    public static function getLetters()
    {
        return array(
            'A','B','C','D','E','F','G','H','I','J','K','L','M',
            'N','O','P','Q','R','S','T','U','V','W','X','Y','Z',
        );
    }

    /**
     * Remove accent from a string
     */
    public function removeAccents($str, $charset='utf-8')
    {
        $str = htmlentities($str, ENT_NOQUOTES, $charset);
        $str = preg_replace('#&([A-za-z])(?:acute|cedil|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $str);
        $str = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $str);
        $str = preg_replace('#&[^;]+;#', '', $str);
        return $str;
    }

    /**
     * Empty properties on clone
     */
    public function __clone()
    {
        $this->_objecInstance = array();
        $this->_resource = null;
    }
}
