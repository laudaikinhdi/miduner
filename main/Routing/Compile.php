<?php

namespace Main\Routing;

use App\Http\Kernel;
use Main\Http\Middleware;
use Main\Http\FormRequest;
use Main\Http\Exceptions\AppException;

class Compile
{
    /**
     * Initial constructor
     * @param string/array $action
     * @param array $params
     * @param string $middleware
     */
    public function __construct($action = null, array $params = null, $middleware = null)
    {
        if ($middleware != null) {
            return $this->handleMiddleware($middleware, $action, $params);
        }
        return $this->handle($action, $params);
    }

    /**
     * Handle route action
     * @param string/array $action
     * @param array $params
     *
     * @return void
     */
    public function handle($action, $params)
    {
        if (!is_array($action)) {
            $action = explode('@', $action);
        }
        switch (count($action)) {
            case 2:
                list($className, $methodName) = $action;
                break;
            case 1:
                list($className) = $action;
                $methodName = '__invoke';
                break;
            default:
                throw new AppException("Controller wrong format !");
                break;
        }
        if (explode('\\', $className)[0] === 'App') {
            $controller = $className;
        } else {
            $controller = 'App\\Http\\Controllers\\' . $className;
        }
        if (class_exists($controller)) {
            $params = $this->buildNeedleInjections($controller, $methodName, $params);
            $object = $this->initialInstance($controller);
            if (method_exists($controller, $methodName)) {
                return call_user_func_array([$object, $methodName], $params);
            }
            throw new AppException("Method {$className}@{$methodName} doesn't exists !");
        }
        throw new AppException("Class {$className} doesn't exists !");
    }

    /**
     * Handle middleware callback
     * @param string $middleware
     * @param array/string $action
     * @param array $param
     * @return Main\Http\Middleware
     *
     * @throws AppException
     */
    public function handleMiddleware($middleware, $action, $params)
    {
        if (count(explode('\\', $middleware)) > 1) {
            new $middleware($this, $action, $params);
        } else {
            foreach ((new Kernel)->routeMiddlewares as $key => $value) {
                if ($middleware == $key) {
                    return new $value($this, $action, $params);
                }
            }
            throw new AppException("Middleware {$middleware} doesn't exists");
        }
    }

    /**
     * Execute request action
     * @param string $controller
     * @param string $methodName
     * @param array $params
     *
     * @return array
     */
    private function buildNeedleInjections($controller, $methodName, $params)
    {
        try {
            $ref = new \ReflectionMethod($controller, $methodName);
            $listParameters = $ref->getParameters();
            $array = [];
            foreach ($listParameters as $key => $param) {
                $refParam = new \ReflectionParameter([$controller, $methodName], $key);
                if (is_object($refParam->getClass())) {
                    $object = $refParam->getClass()->getName();
                    $array[$param->getName()] = $this->buildInjectInstance($object);
                } else {
                    array_push($array, array_shift($params));
                }
            }
            return $array;
        } catch (\ReflectionException $e) {
            throw new AppException($e->getMessage());
        }
    }

    /**
     * !! Only using in this class !!
     * Handle validation for request
     * @param string $object
     *
     * @return Closure
     */
    private function buildInjectInstance($object)
    {
        try {
            $bindings = app()->getBindings();
            if (isset($bindings[$object])) {
                $object = $bindings[$object];
            }

            $object = $this->initialInstance($object);
            if ($object instanceof FormRequest) {
                $object->executeValidate();
            }
            return $object;
        } catch (\ArgumentCountError $e) {
            throw new AppException($e->getMessage());
        }
    }

    /**
     * Get object after injection dependencies
     * @param string $object
     * 
     * @return Closure
     */
    private function initialInstance(string $object)
    {
        $reflector = new \ReflectionClass($object);
        if (!$reflector->hasMethod('__construct')) {
            return new $object;
        }
        $initParams = $this->buildNeedleInjections($object, '__construct', []);
        return $reflector->newInstanceArgs($initParams);
    }
}
