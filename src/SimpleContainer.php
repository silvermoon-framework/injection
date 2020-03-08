<?php
declare(strict_types=1);

namespace Silvermoon\Injection;

use Silvermoon\Contracts\Injection\ContainerInterface;
use Silvermoon\Contracts\Injection\InjectorServiceInterface;
use Silvermoon\Contracts\Injection\SingletonInterface;
use Silvermoon\Injection\Exception\ClassDoesNotExistsException;
use Silvermoon\Injection\Exception\ImplementationDoesNotExistsException;
use Silvermoon\Injection\Exception\InterfaceDoesNotExistsException;
use Silvermoon\Injection\Exception\WrongTypeException;
use Silvermoon\Injection\Service\DependencyInjectorService;

/**
 * Class SimpleContainer
 */
class SimpleContainer implements ContainerInterface
{
    /**
     * @var string[]
     */
    protected array $mapInterfaceToClass = [];

    /**
     * @var object[]
     */
    protected array $singletonArray = [];

    /**
     * @var InjectorServiceInterface[]
     */
    private array $injectorServices = [];

    public function __construct()
    {
        $this->injectorServices[] = new DependencyInjectorService();
    }

    /**
     * @param string $interfaceName
     * @return object|null
     * @throws ClassDoesNotExistsException
     * @throws ImplementationDoesNotExistsException
     * @throws WrongTypeException
     */
    public function getByInterface(string $interfaceName): ?object
    {
        if (!\array_key_exists($interfaceName, $this->mapInterfaceToClass)) {
            return null;
        }
        $className = $this->mapInterfaceToClass[$interfaceName];
        return $this->get($className);
    }

    /**
     * @param string $className
     * @param mixed ...$constructorArguments
     * @return object
     * @throws ClassDoesNotExistsException
     * @throws ImplementationDoesNotExistsException
     * @throws WrongTypeException
     */
    public function get(string $className, ...$constructorArguments): object
    {
        if (\array_key_exists($className, $this->singletonArray)) {
            return $this->singletonArray[$className];
        }
        if (!\class_exists($className)) {
            throw new ClassDoesNotExistsException('The Class ' . $className . ' does not exists.');
        }
        /** @var  InjectorServiceInterface $injectorService */
        foreach ($this->injectorServices as $injectorService) {
        }

        return $this->_get($className, ...$constructorArguments);
    }

    /**
     * @param string $interfaceName
     * @param string $className
     * @throws ClassDoesNotExistsException
     * @throws InterfaceDoesNotExistsException
     * @throws WrongTypeException
     */
    public function register(string $interfaceName, string $className): void
    {
        if (!\interface_exists($interfaceName)) {
            throw new InterfaceDoesNotExistsException('Given interface <' . $interfaceName . '> not found or is not an interface');
        }
        if (!\class_exists($className)) {
            throw new ClassDoesNotExistsException('Given class <' . $className . '> not found');
        }
        if ($this->implementsInterface($interfaceName, $className) === false) {
            throw new WrongTypeException('Given class <' . $className . '> must implement the interface <' . $interfaceName . '>');
        }
        $this->mapInterfaceToClass[$interfaceName] = $className;
    }

    /**
     * @param string $className
     * @param mixed ...$constructorArguments
     * @return object
     * @throws ClassDoesNotExistsException
     * @throws ImplementationDoesNotExistsException
     * @throws WrongTypeException
     */
    private function _get(string $className, ...$constructorArguments): object
    {
        $dependencies = $this->getDependencies($className);

        if (count($dependencies) === 0) {
            $newObject = new $className(...$constructorArguments);
            $this->checkForSingletonInterface($className, $newObject);
            return $newObject;
        }

        $dependObjects = [];
        foreach ($dependencies as $dependency) {
            $interfaceClassName = $dependency['dependency'];
            $optional = $dependency['optional'];

            if ($interfaceClassName === ContainerInterface::class) {
                $dependObjects[] = $this;
                continue;
            }

            if (\interface_exists($interfaceClassName)) {
                $object = $this->getByInterface($interfaceClassName);
                if ($object === null && $optional === false) {
                    throw new ImplementationDoesNotExistsException('No Implementation for the interface ' . $interfaceClassName . ' dependency does not exists. Please register.');
                }
                $dependObjects[] = $object;
                continue;
            }

            if (!\class_exists($interfaceClassName)) {
                throw new ClassDoesNotExistsException('No class ' . $interfaceClassName . ' does not exists.');
            }

            $dependObjects[] = $this->get($interfaceClassName);
        }

        $newObject = new $className(...$constructorArguments);
        $newObject->inject(...$dependObjects);
        $this->checkForSingletonInterface($className, $newObject);
        return $newObject;
    }


    /**
     * @param string $className
     * @param object $newObject
     */
    private function checkForSingletonInterface(string $className, object $newObject): void
    {
        if ($this->implementsInterface(SingletonInterface::class, $className)) {
            $this->singletonArray[$className] = $newObject;
        }
    }


    /**
     * @param string $className
     * @param string $methodName
     * @return array
     * @throws WrongTypeException
     */
    protected function getDependencies(string $className, string $methodName = 'inject'): array
    {
        try {
            $reflectionClass = new \ReflectionClass($className);
        } catch (\ReflectionException $e) {
            return [];
        }
        $hasInjectMethod = $reflectionClass->hasMethod($methodName);
        if ($hasInjectMethod === false) {
            return [];
        }
        try {
            $injectionMethod = $reflectionClass->getMethod($methodName);
        } catch (\ReflectionException $e) {
            return [];
        }
        $out = [];
        $reflectionParameters = $injectionMethod->getParameters();
        /** @var \ReflectionParameter $reflectionParameter */
        foreach ($reflectionParameters as $reflectionParameter) {
            $info = [];
            $info['name'] = $reflectionParameter->getName();
            $interfaceClass = $reflectionParameter->getClass();
            $type = $reflectionParameter->getType();
            if ($interfaceClass !== null) {
                $info['type'] =  'class';
                $info['dependency'] = $interfaceClass->getName();
            } else {
                $info['type'] =  (string) $type;
            }
            if ($type !== null) {
                $info['optional'] = $reflectionParameter->getType()->allowsNull();
            } else {
                $info['optional'] = false;
            }

            $out[] = $info;
        }
        return $out;
    }

    /**
     * @param string $interfaceName
     * @param string $className
     * @return bool
     */
    private function implementsInterface(string $interfaceName, string $className): bool
    {
        $interfaces = \class_implements($className, true);
        return \in_array($interfaceName, $interfaces, true);
    }
}
