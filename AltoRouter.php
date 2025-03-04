<?php
/*
MIT License

Copyright (c) 2012 Danny van Kooten <hi@dannyvankooten.com>

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

class AltoRouter
{
		const ALLOW_GENERATE_ANONYMOUS = false;

    /**
     * @var array Array of all routes (incl. named routes).
     */
    protected $routes = [];

    /**
     * @var array Array of all named routes.
     */
    protected $namedRoutes = [];

    /**
     * @var string Can be used to ignore leading part of the Request URL (if main file lives in subdirectory of host)
     */
    protected $basePath = '';

    /**
     * @var array Array of default match types (regex helpers)
     */
    protected $matchTypes = [
        'i'  => '[0-9]++',
        'a'  => '[0-9A-Za-z]++',
        'h'  => '[0-9A-Fa-f]++',
        '*'  => '.+?',
        '**' => '.++',
        ''   => '[^/\.]++',
        '--allow-dots--' => '[^/]++'
    ];

    /**
     * Create router in one call from config.
     *
     * @param array $routes
     * @param string $basePath
     * @param array $matchTypes
     * @throws Exception
     */
    public function __construct(array $routes = [], $basePath = '', array $matchTypes = [])
    {
        $this->addRoutes($routes);
        $this->setBasePath($basePath);
        $this->addMatchTypes($matchTypes);
    }

    /**
     * Retrieves all routes.
     * Useful if you want to process or display routes.
     * @return array All routes.
     */
    public function getRoutes()
    {
        return $this->routes;
    }

    /**
     * Add multiple routes at once from array in the following format:
     *
     *   $routes = [
     *      [$method, $route, $target, $name]
     *   ];
     *
     * @param array $routes
     * @return void
     * @author Koen Punt
     * @throws Exception
     */
    public function addRoutes($routes)
    {
        if (!is_array($routes) && !$routes instanceof Traversable) {
            throw new RuntimeException('Routes should be an array or an instance of Traversable');
        }
        foreach ($routes as $route) {
            call_user_func_array([$this, 'map'], $route);
        }
    }

    /**
     * Set the base path.
     * Useful if you are running your application from a subdirectory.
     * @param string $basePath
     */
    public function setBasePath($basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * Add named match types. It uses array_merge so keys can be overwritten.
     *
     * @param array $matchTypes The key is the name and the value is the regex.
     */
    public function addMatchTypes(array $matchTypes)
    {
        $this->matchTypes = array_merge($this->matchTypes, $matchTypes);
    }

    /**
     * Map a route to a target
     *
     * @param string $method One of 5 HTTP Methods, or a pipe-separated list of multiple HTTP Methods (GET|POST|PATCH|PUT|DELETE)
     * @param string $route The route regex, custom regex must start with an @. You can use multiple pre-set regex filters, like [i:id]
     * @param mixed $target The target where this route should point to. Can be anything.
     * @param string $name Optional name of this route. Supply if you want to reverse route this url in your application.
     * @throws Exception
     */
    public function map($method, $route, $target, $name = null)
    {
        $any = [ 'GET', 'POST', 'PATCH', 'PUT', 'DELETE' ];
        $onroot = substr($route, 0, 1) === '/';

        $_method = explode( '|', strtoupper( $method ) );
        $_method = array_intersect( $_method, array_merge( [ 'ANY' ], $any ) );
        if ( ! $_method ) {
            throw new RuntimeException( "Invalid method '{$method}'" );
        }
        // normalize ANY
        if ( in_array( 'ANY', $_method ) ) {
            $_method = [ 'ANY' ];
            $method  = implode( '|', $any );
        } elseif ( count( $_method ) >= 5 ) { // all acceptable methods are requested
            $_method = [ 'ANY' ];
            $method  = implode( '|', $any );
        } else {
            $method = implode( '|', $_method );
        }

        if ($name) {
            // register named routes for all the methods, use ANY in case of exactly all.
            foreach ( $_method as $__method ) {
                if ( isset( $this->namedRoutes["$__method:$name"] ) ) {
                    throw new RuntimeException( "Can not redeclare route '{$name}' for method '{$__method}'" );
                }
                $this->namedRoutes["$__method:$name"] = $route;
            }
        }

        $this->routes[] = [ $method, $route, $target, $name, $onroot ];

        return;
    }

    /**
     * Reversed routing
     *
     * Generate the URL for a named route. Replace regexes with supplied parameters
     *
     * @param string $routeName The name of the route.
     * @param array $params Associative array of parameters to replace placeholders with.
     * @param string|null $method must match method
     *
     * @return string The URL of the route with named parameters in place.
     * @throws Exception
     */
    public function generate($routeName, array $params = [], $method = null)
    {
        $method = $method ? strtoupper( $method ) : 'ANY';
        $route  = false;
        // allow generating by exact route or name
        $allowed_names   = [];
        $allowed_names[] = "$method:$routeName"; // specific request, specific route
        $allowed_names[] = "ANY:$routeName"; // specific request, generic route
        $any             = [ 'GET', 'POST', 'PATCH', 'PUT', 'DELETE' ];
        foreach ( $any as $m ) {
            $allowed_names[] = "$m:$routeName"; // generic request, specific route
        }

        foreach ( $allowed_names as $allowed_name ) {
            if ( isset( $this->namedRoutes[$allowed_name] ) ) {
                $route = $this->namedRoutes[$allowed_name];
                break;
            }
        }

        // did not find a matching named route, try unnamed routes
        if ( ! $route && self::ALLOW_GENERATE_ANONYMOUS) {
            foreach ( $this->getRoutes() as $a_route ) {
                if ( $routeName === $a_route[1] ) {
                    if ( $method && 'ANY' !== $method && ! in_array( strtoupper( $method ), explode( '|', $a_route[0] ) ) ) {
                        continue;
                    }

                    $route = $a_route[1];
                    break;
                }
            }
        }

        // Check if named route exists
        if ( ! $route ) {
            throw new RuntimeException( "Route '{$routeName}' does not exist." );
        }

        // prepend base path to route url again
        $url = $this->basePath . $route;
        // this is special; if the route begins with /, ignore the basePath
        if (substr($route, 0, 1) == '/') {
	        $url = $route;
        }

        if (preg_match_all('`(/|\.|)\[([^:\]]*+)(?::([^:\]]*+))?\](\?|\.|)`', $route, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $index => $match) {
                list($block, $pre, $type, $param, $optional) = $match;

                if ($pre) {
                    $block = substr($block, 1);
                }

                if (isset($params[$param])) {
                    // Part is found, replace for param value
                    $url = str_replace($block, $params[$param], $url);
                } elseif ($optional && $index !== 0) {
                    // Only strip preceding slash if it's not at the base
                    $url = str_replace($pre . $block, '', $url);
                } else {
                    // Strip match block
                    $url = str_replace($block, '', $url);
                }
            }
        }
        // clean multiple slashed at the end that are a result of optional parameters.
        // do NOT replace ALL // with / as optional parameters IN THE MIDDLE of a route
        // will then break that route. Of course, one should NEVER have optional parameters
        // in the middle of a route, but some people are stupid.
        // if a route has no trailing slash, don't force it. Want to force it? use     $url .'/'    as last parameter.
        return preg_replace( '@/+$@', '/', $url );
    }

    /**
     * Match a given Request Url against stored routes
     * @param string $requestUrl
     * @param string $requestMethod
     * @return array|boolean Array with route information on success, false on failure (no match).
     */
    public function match($requestUrl = null, $requestMethod = null)
    {

        $params = [];

        // set Request Url if it isn't passed as parameter
        if ($requestUrl === null) {
            $requestUrl = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        }

        // Strip query string (?a=b) from Request Url
        if (($strpos = strpos($requestUrl, '?')) !== false) {
            $requestUrl = substr($requestUrl, 0, $strpos);
        }

        $lastRequestUrlChar = $requestUrl ? $requestUrl[strlen($requestUrl)-1] : '';

        // set Request Method if it isn't passed as a parameter
        if ($requestMethod === null) {
            $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        }

        foreach ($this->routes as $handler) {
            list($methods, $route, $target, $name, $onroot) = $handler;

            $method_match = (stripos($methods, $requestMethod) !== false);

            // Method did not match, continue to next route.
            if (!$method_match) {
                continue;
            }

            if ($onroot) {
                $verify_route = $route;
            }
            else {
                $verify_route = $this->basePath . ltrim($route, '/');
            }

            if ($route === '*') {
                // * wildcard (matches all)
                $match = true;
            } elseif (isset($route[0]) && $route[0] === '@') {
                // @ regex delimiter
                $pattern = '`' . substr($route, 1) . '`u';
                $match = preg_match($pattern, $requestUrl, $params) === 1;
            } elseif (($position = strpos($route, '[')) === false) {
                // No params in url, do string comparison
                $match = strcmp($requestUrl, $verify_route) === 0;
            } else {
                // Compare longest non-param string with url before moving on to regex
				// Check if last character before param is a slash, because it could be optional if param is optional too (see https://github.com/dannyvankooten/AltoRouter/issues/241)
                if (strncmp($requestUrl, $verify_route, $position) !== 0 && ($lastRequestUrlChar === '/' || $route[$position-1] !== '/')) {
                    continue;
                }

                $regex = $this->compileRoute($verify_route);
                $match = preg_match($regex, $requestUrl, $params) === 1;
            }

            if ($match) {
                if ($params) {
                    foreach ($params as $key => $value) {
                        if (is_numeric($key)) {
                            unset($params[$key]);
                        }
                    }
                }

                return compact( 'target', 'params', 'route', 'methods', 'name' );
            }
        }

        return false;
    }

    /**
     * Compile the regex for a given route (EXPENSIVE)
     * @param $route
     * @return string
     */
    protected function compileRoute($route)
    {
        if (preg_match_all('`(/|\.|)\[([^:\]]*+)(?::([^:\]]*+))?\]([?.]*)`', $route, $matches, PREG_SET_ORDER)) {
            $matchTypes = $this->matchTypes;
            foreach ($matches as $match) {
                list($block, $pre, $type, $param, $optional) = $match;
                $allow_dots = false !== strpos($optional,'.');
                $optional = false !== strpos($optional,'?');

                if (isset($matchTypes[$type])) {
                    $type = $matchTypes[$type];
                    if ($allow_dots && $type === $matchTypes['']) {
                        $type = $matchTypes['--allow-dots--'];
                    }
                }
                if ($pre === '.') {
                    $pre = '\.';
                }

                $optional = $optional !== '' ? '?' : null;

                //Older versions of PCRE require the 'P' in (?P<named>)
                $pattern = '(?:'
                        . ($pre !== '' ? $pre : null)
                        . '('
                        . ($param !== '' ? "?P<$param>" : null)
                        . $type
                        . ')'
                        . $optional
                        . ')'
                        . $optional;

                $route = str_replace($block, $pattern, $route);
            }
        }
        return "`^$route$`u";
    }
}
