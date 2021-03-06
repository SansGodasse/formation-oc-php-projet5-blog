<?php
/**
 * Created by PhpStorm.
 * User: asus
 * Date: 22/01/2019
 * Time: 14:19
 */

namespace Application;


use Application\Exception\AccessException;
use Application\Exception\AppException;
use Application\Exception\HttpException;
use Application\Exception\CsrfSecurityException;
use Application\Logger\Logger;
use Application\Router\Router;
use Application\Security\CsrfProtector;
use Controller\AdminController;
use Controller\BlogController;
use Controller\ErrorController;
use Controller\HomeController;
use Controller\MediaController;
use Controller\MemberController;
use Exception;
use ReflectionException;
use ReflectionMethod;

class Application
{
    private function __construct()
    {
        // Disabled
    }

    /**
     * Begin the show! Enjoy!
     *
     * @throws \Twig_Error_Loader
     * @throws \Twig_Error_Runtime
     * @throws \Twig_Error_Syntax
     */
    public static function run()
    {
        define('ROOT_PATH', dirname(__DIR__));

        // Session
        session_start();

        // Time zone
        date_default_timezone_set("Europe/Paris");

        // Security
        try {
            // CSRF protection
            CsrfProtector::setCounterCsrfToken(bin2hex(random_bytes(87)));
            $_SESSION['csrf-token'] = CsrfProtector::getCounterCsrfToken();
        } catch (Exception $e) {
            $errorController = DIC::newErrorController();
            $errorController->showError500();
        }

        // Routing
        try {
            $route = Router::run();

            switch ($route->controller) {
                case BlogController::class:
                    $controller = DIC::newBlogController();
                    break;

                case AdminController::class:
                    $controller = DIC::newAdminController();
                    break;

                case HomeController::class:
                    $controller = DIC::newHomeController();
                    break;

                case ErrorController::class:
                    $controller = DIC::newErrorController();
                    break;

                case MemberController::class:
                    $controller = DIC::newMemberController();
                    break;

                case MediaController::class:
                    $controller = DIC::newMediaController();
                    break;

                default:
                    throw new AppException('The controller ' . $route->controller . ' is missing from the Application::run() routing switch');
            }

            try {
                $method = new ReflectionMethod($route->controller, $route->method);

            } catch (ReflectionException $e) {
                throw new HttpException('The method ' . $route->method . ' was not found in ' . $route->controller, 404, $e);
            }

            $method->invokeArgs($controller, $route->params);

        } catch (AccessException $e) {
            $errorController = DIC::newErrorController();
            $errorController->showError403();
        } catch (CsrfSecurityException $e) {
            $errorController = DIC::newErrorController();
            $errorController->showCustomError('Une attaque CSRF a été détectée. Si vous êtes à l\'origine de cette attaque, c\'est pas gentil.');
        } catch (HttpException $e) {
            $errorController = DIC::newErrorController();
            switch ($e->getCode()) {
                case 403:
                    $errorController->showError403();
                    break;
                case 404:
                    $errorController->showError404();
                    break;
                case 500:
                    $errorController->showError500();
                    break;
                default:
                    $errorController->showCustomError("Un bug ! Quelle horreur !");
            }
        } catch (AppException $e) {
            $errorController = DIC::newErrorController();
            Logger::addLog("Unknown error occured: " . $e->getMessage());
            $errorController->showCustomError("Mais qu'est-ce qui s'est passé ??? Une erreur inconnue a été détectée !");
        }
    }
}
