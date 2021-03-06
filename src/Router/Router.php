<?php

namespace Application\Router;

use Application\Exception\HttpException;
use Application\Security\CsrfProtector;
use Controller\MemberController;

class Router
{
    /**
     * Disable the constructor to simulate a static class
     */
    private function __construct()
    {}

    /**
     * Analyze the url and return the controller name, the method to call and the parameters
     *
     * @return Route
     * @throws \Application\Exception\AccessException
     * @throws \Application\Exception\CsrfSecurityException
     * @throws HttpException
     */
    public static function run(): Route
    {
        $requestedUrl = self::getUrl();

        if (!$route = self::getMatchingRoute($requestedUrl)) {
            throw new HttpException("There is no route matching the requested URL: " . implode("", $requestedUrl), 404);
        }
        return $route;
    }

    // Private

    /**
     * Get the url
     *
     * @return mixed
     */
    private static function getUrl()
    {
        $urlParts = explode('?', $_SERVER['REQUEST_URI']);

        return $urlParts[0];
    }

    /**
     * Get the matching route of an url
     *
     * @param string $requestedUrl
     * @return Route|null
     * @throws \Application\Exception\AccessException
     * @throws \Application\Exception\CsrfSecurityException
     */
    private static function getMatchingRoute(string $requestedUrl): ?Route
    {
        $routes = require ROOT_PATH . '/src/Router/routes.php';

        foreach ($routes as $route) {
            if (self::isAKnownUrl($route['urls'], $requestedUrl)) {
                foreach ($route as $key => $value) {
                    if ($key === "checkAccess") {
                        MemberController::verifyAccess($value);
                    }
                    if ($key === "checkCsrf") {
                        CsrfProtector::checkCsrf();
                    }
                }
                return new Route($route['controller'], $route['method'], $route['params'] ?? []);
            }
        }
        return null;
    }

    /**
     * Check if an url exists in the router
     *
     * @param array $knownUrls
     * @param string $requestedUrl
     * @return bool
     */
    private static function isAKnownUrl(array $knownUrls, string $requestedUrl)
    {
        foreach ($knownUrls as $url) {
            if ($url === $requestedUrl) {
                return true;
            }
        }
        return false;
    }
}
