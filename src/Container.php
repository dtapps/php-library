<?php

declare (strict_types=1);

namespace dtapps\library;

use Closure;
use Exception;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;

/**
 * 容器管理类 支持PSR-11
 * 代码参考ThinkPHP6的Container.php
 */
class Container implements ContainerInterface
{
    /**
     * 容器对象实例
     * @var Container|Closure
     */
    protected static $instance;

    /**
     * 容器中的对象实例
     * @var array
     */
    protected $instances = [];

    /**
     * 容器绑定标识
     * @var array
     */
    protected $bind = [];

    /**
     * 容器回调
     * @var array
     */
    protected $invokeCallback = [];

    /**
     * 获取当前容器的实例（单例）
     * @access public
     * @return static
     */
    public static function getInstance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }

        if (static::$instance instanceof Closure) {
            return (static::$instance)();
        }

        return static::$instance;
    }

    /**
     * 获取容器中的对象实例
     * @access public
     * @param string $abstract 类名或者标识
     * @return object
     * @throws ReflectionException
     * @throws Exception
     */
    public function get($abstract)
    {
        if ($this->has($abstract)) {
            return $this->make($abstract);
        }

        throw new Exception('class not exists: ' . $abstract, $abstract);
    }

    /**
     * 根据别名获取真实类名
     * @param string $abstract
     * @return string
     */
    public function getAlias(string $abstract): string
    {
        if (isset($this->bind[$abstract])) {
            $bind = $this->bind[$abstract];

            if (is_string($bind)) {
                return $this->getAlias($bind);
            }
        }

        return $abstract;
    }

    /**
     * 判断容器中是否存在类及标识
     * @access public
     * @param string $abstract 类名或者标识
     * @return bool
     */
    public function bound(string $abstract): bool
    {
        return isset($this->bind[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * 判断容器中是否存在类及标识
     * @access public
     * @param string $name 类名或者标识
     * @return bool
     */
    public function has($name): bool
    {
        return $this->bound($name);
    }

    /**
     * 创建类的实例 已经存在则直接获取
     * @access public
     * @param string $abstract 类名或者标识
     * @param array $vars 变量
     * @param bool $newInstance 是否每次创建新的实例
     * @return mixed
     * @throws ReflectionException
     */
    public function make(string $abstract, array $vars = [], bool $newInstance = false)
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->instances[$abstract]) && !$newInstance) {
            return $this->instances[$abstract];
        }

        if (isset($this->bind[$abstract]) && $this->bind[$abstract] instanceof Closure) {
            $object = $this->invokeFunction($this->bind[$abstract], $vars);
        } else {
            $object = $this->invokeClass($abstract, $vars);
        }

        if (!$newInstance) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * 执行函数或者闭包方法 支持参数调用
     * @access public
     * @param string|Closure $function 函数或者闭包
     * @param array $vars 参数
     * @return mixed
     * @throws ReflectionException
     * @throws Exception
     */
    public function invokeFunction($function, array $vars = [])
    {
        try {
            $reflect = new ReflectionFunction($function);
        } catch (ReflectionException $e) {
            throw new Exception("function not exists: {$function}()", $function, $e);
        }

        $args = $this->bindParams($reflect, $vars);

        return $function(...$args);
    }

    /**
     * 调用反射执行类的实例化 支持依赖注入
     * @access public
     * @param string $class 类名
     * @param array $vars 参数
     * @return mixed
     * @throws ReflectionException
     * @throws Exception
     */
    public function invokeClass(string $class, array $vars = [])
    {
        try {
            $reflect = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            throw new Exception('class not exists: ' . $class, $class, $e);
        }

        if ($reflect->hasMethod('__make')) {
            $method = $reflect->getMethod('__make');
            if ($method->isPublic() && $method->isStatic()) {
                $args = $this->bindParams($method, $vars);
                $object = $method->invokeArgs(null, $args);
                $this->invokeAfter($class, $object);
                return $object;
            }
        }

        $constructor = $reflect->getConstructor();

        $args = $constructor ? $this->bindParams($constructor, $vars) : [];

        $object = $reflect->newInstanceArgs($args);

        $this->invokeAfter($class, $object);

        return $object;
    }

    /**
     * 执行invokeClass回调
     * @access protected
     * @param string $class 对象类名
     * @param object $object 容器对象实例
     * @return void
     */
    protected function invokeAfter(string $class, $object): void
    {
        if (isset($this->invokeCallback['*'])) {
            foreach ($this->invokeCallback['*'] as $callback) {
                $callback($object, $this);
            }
        }

        if (isset($this->invokeCallback[$class])) {
            foreach ($this->invokeCallback[$class] as $callback) {
                $callback($object, $this);
            }
        }
    }

    /**
     * 绑定参数
     * @access protected
     * @param ReflectionFunctionAbstract $reflect 反射类
     * @param array $vars 参数
     * @return array
     * @throws ReflectionException
     */
    protected function bindParams(ReflectionFunctionAbstract $reflect, array $vars = []): array
    {
        if ($reflect->getNumberOfParameters() == 0) {
            return [];
        }

        // 判断数组类型 数字数组时按顺序绑定参数
        reset($vars);
        $type = key($vars) === 0 ? 1 : 0;
        $params = $reflect->getParameters();
        $args = [];

        foreach ($params as $param) {
            $name = $param->getName();
            $lowerName = self::snake($name);
            $reflectionType = $param->getType();

            if ($reflectionType && $reflectionType->isBuiltin() === false) {
                $args[] = $this->getObjectParam($reflectionType->getName(), $vars);
            } elseif (1 == $type && !empty($vars)) {
                $args[] = array_shift($vars);
            } elseif (0 == $type && array_key_exists($name, $vars)) {
                $args[] = $vars[$name];
            } elseif (0 == $type && array_key_exists($lowerName, $vars)) {
                $args[] = $vars[$lowerName];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new InvalidArgumentException('method param miss:' . $name);
            }
        }

        return $args;
    }

    protected static $snakeCache = [];

    /**
     * 驼峰转下划线
     *
     * @param string $value
     * @param string $delimiter
     * @return string
     */
    private static function snake(string $value, string $delimiter = '_'): string
    {
        $key = $value;

        if (isset(static::$snakeCache[$key][$delimiter])) {
            return static::$snakeCache[$key][$delimiter];
        }

        if (!ctype_lower($value)) {
            $value = preg_replace('/\s+/u', '', $value);

            $value = static::lower(preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value));
        }

        return static::$snakeCache[$key][$delimiter] = $value;
    }

    /**
     * 字符串转小写
     *
     * @param string $value
     * @return string
     */
    private static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * 获取对象类型的参数值
     * @access protected
     * @param string $className 类名
     * @param array $vars 参数
     * @return mixed
     * @throws ReflectionException
     */
    protected function getObjectParam(string $className, array &$vars)
    {
        $array = $vars;
        $value = array_shift($array);

        if ($value instanceof $className) {
            $result = $value;
            array_shift($vars);
        } else {
            $result = $this->make($className);
        }

        return $result;
    }

    public function __get($name)
    {
        return $this->get($name);
    }

    public function offsetGet($key)
    {
        return $this->make($key);
    }
}
