<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\DependencyInjection\Tests\Dumper;

use DummyProxyDumper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\RewindableGenerator;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Tests\Fixtures\StubbedTranslator;
use Symfony\Component\DependencyInjection\TypedReference;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\DependencyInjection\Tests\Fixtures\CustomDefinition;
use Symfony\Component\DependencyInjection\Tests\Fixtures\TestServiceSubscriber;
use Symfony\Component\DependencyInjection\Variable;
use Symfony\Component\ExpressionLanguage\Expression;

require_once __DIR__.'/../Fixtures/includes/classes.php';

class PhpDumperTest extends TestCase
{
    protected static $fixturesPath;

    public static function setUpBeforeClass()
    {
        self::$fixturesPath = realpath(__DIR__.'/../Fixtures/');
    }

    public function testDump()
    {
        $container = new ContainerBuilder();
        $container->compile();
        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services1.php', $dumper->dump(), '->dump() dumps an empty container as an empty PHP class');
        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services1-1.php', $dumper->dump(array('class' => 'Container', 'base_class' => 'AbstractContainer', 'namespace' => 'Symfony\Component\DependencyInjection\Dump')), '->dump() takes a class and a base_class options');
    }

    public function testDumpOptimizationString()
    {
        $definition = new Definition();
        $definition->setClass('stdClass');
        $definition->addArgument(array(
            'only dot' => '.',
            'concatenation as value' => '.\'\'.',
            'concatenation from the start value' => '\'\'.',
            '.' => 'dot as a key',
            '.\'\'.' => 'concatenation as a key',
            '\'\'.' => 'concatenation from the start key',
            'optimize concatenation' => 'string1%some_string%string2',
            'optimize concatenation with empty string' => 'string1%empty_value%string2',
            'optimize concatenation from the start' => '%empty_value%start',
            'optimize concatenation at the end' => 'end%empty_value%',
            'new line' => "string with \nnew line",
        ));
        $definition->setPublic(true);

        $container = new ContainerBuilder();
        $container->setResourceTracking(false);
        $container->setDefinition('test', $definition);
        $container->setParameter('empty_value', '');
        $container->setParameter('some_string', '-');
        $container->compile();

        $dumper = new PhpDumper($container);
        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services10.php', $dumper->dump(), '->dump() dumps an empty container as an empty PHP class');
    }

    public function testDumpRelativeDir()
    {
        $definition = new Definition();
        $definition->setClass('stdClass');
        $definition->addArgument('%foo%');
        $definition->addArgument(array('%foo%' => '%buz%/'));
        $definition->setPublic(true);

        $container = new ContainerBuilder();
        $container->setDefinition('test', $definition);
        $container->setParameter('foo', 'wiz'.dirname(__DIR__));
        $container->setParameter('bar', __DIR__);
        $container->setParameter('baz', '%bar%/PhpDumperTest.php');
        $container->setParameter('buz', dirname(dirname(__DIR__)));
        $container->compile();

        $dumper = new PhpDumper($container);
        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services12.php', $dumper->dump(array('file' => __FILE__)), '->dump() dumps __DIR__ relative strings');
    }

    /**
     * @dataProvider provideInvalidParameters
     * @expectedException \InvalidArgumentException
     */
    public function testExportParameters($parameters)
    {
        $container = new ContainerBuilder(new ParameterBag($parameters));
        $container->compile();
        $dumper = new PhpDumper($container);
        $dumper->dump();
    }

    public function provideInvalidParameters()
    {
        return array(
            array(array('foo' => new Definition('stdClass'))),
            array(array('foo' => new Expression('service("foo").foo() ~ (container.hasParameter("foo") ? parameter("foo") : "default")'))),
            array(array('foo' => new Reference('foo'))),
            array(array('foo' => new Variable('foo'))),
        );
    }

    public function testAddParameters()
    {
        $container = include self::$fixturesPath.'/containers/container8.php';
        $container->compile();
        $dumper = new PhpDumper($container);
        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services8.php', $dumper->dump(), '->dump() dumps parameters');
    }

    /**
     * @expectedException \Symfony\Component\DependencyInjection\Exception\LogicException
     * @expectedExceptionMessage Cannot dump an uncompiled container.
     */
    public function testAddServiceWithoutCompilation()
    {
        $container = include self::$fixturesPath.'/containers/container9.php';
        new PhpDumper($container);
    }

    public function testAddService()
    {
        $container = include self::$fixturesPath.'/containers/container9.php';
        $container->compile();
        $dumper = new PhpDumper($container);
        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services9_compiled.php', str_replace(str_replace('\\', '\\\\', self::$fixturesPath.DIRECTORY_SEPARATOR.'includes'.DIRECTORY_SEPARATOR), '%path%', $dumper->dump()), '->dump() dumps services');

        $container = new ContainerBuilder();
        $container->register('foo', 'FooClass')->addArgument(new \stdClass())->setPublic(true);
        $container->compile();
        $dumper = new PhpDumper($container);
        try {
            $dumper->dump();
            $this->fail('->dump() throws a RuntimeException if the container to be dumped has reference to objects or resources');
        } catch (\Exception $e) {
            $this->assertInstanceOf('\Symfony\Component\DependencyInjection\Exception\RuntimeException', $e, '->dump() throws a RuntimeException if the container to be dumped has reference to objects or resources');
            $this->assertEquals('Unable to dump a service container if a parameter is an object or a resource.', $e->getMessage(), '->dump() throws a RuntimeException if the container to be dumped has reference to objects or resources');
        }
    }

    public function testDumpAsFiles()
    {
        $container = include self::$fixturesPath.'/containers/container9.php';
        $container->getDefinition('bar')->addTag('hot');
        $container->compile();
        $dumper = new PhpDumper($container);
        $dump = print_r($dumper->dump(array('as_files' => true, 'file' => __DIR__, 'hot_path_tag' => 'hot')), true);
        if ('\\' === DIRECTORY_SEPARATOR) {
            $dump = str_replace('\\\\Fixtures\\\\includes\\\\foo.php', '/Fixtures/includes/foo.php', $dump);
        }
        $this->assertStringMatchesFormatFile(self::$fixturesPath.'/php/services9_as_files.txt', $dump);
    }

    public function testServicesWithAnonymousFactories()
    {
        $container = include self::$fixturesPath.'/containers/container19.php';
        $container->compile();
        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services19.php', $dumper->dump(), '->dump() dumps services with anonymous factories');
    }

    public function testAddServiceIdWithUnsupportedCharacters()
    {
        $class = 'Symfony_DI_PhpDumper_Test_Unsupported_Characters';
        $container = new ContainerBuilder();
        $container->register('bar$', 'FooClass')->setPublic(true);
        $container->register('bar$!', 'FooClass')->setPublic(true);
        $container->compile();
        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(array('class' => $class)));

        $this->assertTrue(method_exists($class, 'getBarService'));
        $this->assertTrue(method_exists($class, 'getBar2Service'));
    }

    public function testConflictingServiceIds()
    {
        $class = 'Symfony_DI_PhpDumper_Test_Conflicting_Service_Ids';
        $container = new ContainerBuilder();
        $container->register('foo_bar', 'FooClass')->setPublic(true);
        $container->register('foobar', 'FooClass')->setPublic(true);
        $container->compile();
        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(array('class' => $class)));

        $this->assertTrue(method_exists($class, 'getFooBarService'));
        $this->assertTrue(method_exists($class, 'getFoobar2Service'));
    }

    public function testConflictingMethodsWithParent()
    {
        $class = 'Symfony_DI_PhpDumper_Test_Conflicting_Method_With_Parent';
        $container = new ContainerBuilder();
        $container->register('bar', 'FooClass')->setPublic(true);
        $container->register('foo_bar', 'FooClass')->setPublic(true);
        $container->compile();
        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(array(
            'class' => $class,
            'base_class' => 'Symfony\Component\DependencyInjection\Tests\Fixtures\containers\CustomContainer',
        )));

        $this->assertTrue(method_exists($class, 'getBar2Service'));
        $this->assertTrue(method_exists($class, 'getFoobar2Service'));
    }

    /**
     * @dataProvider provideInvalidFactories
     * @expectedException \Symfony\Component\DependencyInjection\Exception\RuntimeException
     * @expectedExceptionMessage Cannot dump definition
     */
    public function testInvalidFactories($factory)
    {
        $container = new ContainerBuilder();
        $def = new Definition('stdClass');
        $def->setPublic(true);
        $def->setFactory($factory);
        $container->setDefinition('bar', $def);
        $container->compile();
        $dumper = new PhpDumper($container);
        $dumper->dump();
    }

    public function provideInvalidFactories()
    {
        return array(
            array(array('', 'method')),
            array(array('class', '')),
            array(array('...', 'method')),
            array(array('class', '...')),
        );
    }

    public function testAliases()
    {
        $container = include self::$fixturesPath.'/containers/container9.php';
        $container->setParameter('foo_bar', 'foo_bar');
        $container->compile();
        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Aliases')));

        $container = new \Symfony_DI_PhpDumper_Test_Aliases();
        $foo = $container->get('foo');
        $this->assertSame($foo, $container->get('alias_for_foo'));
        $this->assertSame($foo, $container->get('alias_for_alias'));
    }

    public function testFrozenContainerWithoutAliases()
    {
        $container = new ContainerBuilder();
        $container->compile();

        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Frozen_No_Aliases')));

        $container = new \Symfony_DI_PhpDumper_Test_Frozen_No_Aliases();
        $this->assertFalse($container->has('foo'));
    }

    /**
     * @expectedException \Symfony\Component\DependencyInjection\Exception\InvalidArgumentException
     * @expectedExceptionMessage The "decorator_service" service is already initialized, you cannot replace it.
     */
    public function testOverrideServiceWhenUsingADumpedContainer()
    {
        require_once self::$fixturesPath.'/php/services9_compiled.php';

        $container = new \ProjectServiceContainer();
        $container->get('decorator_service');
        $container->set('decorator_service', $decorator = new \stdClass());

        $this->assertSame($decorator, $container->get('decorator_service'), '->set() overrides an already defined service');
    }

    /**
     * @expectedException \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
     */
    public function testCircularReference()
    {
        $container = new ContainerBuilder();
        $container->register('foo', 'stdClass')->addArgument(new Reference('bar'))->setPublic(true);
        $container->register('bar', 'stdClass')->setPublic(false)->addMethodCall('setA', array(new Reference('baz')));
        $container->register('baz', 'stdClass')->addMethodCall('setA', array(new Reference('foo')))->setPublic(true);
        $container->compile();

        $dumper = new PhpDumper($container);
        $dumper->dump();
    }

    public function testDumpAutowireData()
    {
        $container = include self::$fixturesPath.'/containers/container24.php';
        $container->compile();
        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services24.php', $dumper->dump());
    }

    public function testEnvParameter()
    {
        $rand = mt_rand();
        putenv('Baz='.$rand);
        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator(self::$fixturesPath.'/yaml'));
        $loader->load('services26.yml');
        $container->setParameter('env(json_file)', self::$fixturesPath.'/array.json');
        $container->compile();
        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services26.php', $dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_EnvParameters', 'file' => self::$fixturesPath.'/php/services26.php')));

        require self::$fixturesPath.'/php/services26.php';
        $container = new \Symfony_DI_PhpDumper_Test_EnvParameters();
        $this->assertSame($rand, $container->getParameter('baz'));
        $this->assertSame(array(123, 'abc'), $container->getParameter('json'));
        $this->assertSame('sqlite:///foo/bar/var/data.db', $container->getParameter('db_dsn'));
        putenv('Baz');
    }

    public function testResolvedBase64EnvParameters()
    {
        $container = new ContainerBuilder();
        $container->setParameter('env(foo)', base64_encode('world'));
        $container->setParameter('hello', '%env(base64:foo)%');
        $container->compile(true);

        $expected = array(
          'env(foo)' => 'd29ybGQ=',
          'hello' => 'world',
        );
        $this->assertSame($expected, $container->getParameterBag()->all());
    }

    public function testDumpedBase64EnvParameters()
    {
        $container = new ContainerBuilder();
        $container->setParameter('env(foo)', base64_encode('world'));
        $container->setParameter('hello', '%env(base64:foo)%');
        $container->compile();

        $dumper = new PhpDumper($container);
        $dumper->dump();

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services_base64_env.php', $dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Base64Parameters')));

        require self::$fixturesPath.'/php/services_base64_env.php';
        $container = new \Symfony_DI_PhpDumper_Test_Base64Parameters();
        $this->assertSame('world', $container->getParameter('hello'));
    }

    public function testCustomEnvParameters()
    {
        $container = new ContainerBuilder();
        $container->setParameter('env(foo)', str_rot13('world'));
        $container->setParameter('hello', '%env(rot13:foo)%');
        $container->register(Rot13EnvVarProcessor::class)->addTag('container.env_var_processor')->setPublic(true);
        $container->compile();

        $dumper = new PhpDumper($container);
        $dumper->dump();

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services_rot13_env.php', $dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Rot13Parameters')));

        require self::$fixturesPath.'/php/services_rot13_env.php';
        $container = new \Symfony_DI_PhpDumper_Test_Rot13Parameters();
        $this->assertSame('world', $container->getParameter('hello'));
    }

    public function testFileEnvProcessor()
    {
        $container = new ContainerBuilder();
        $container->setParameter('env(foo)', __FILE__);
        $container->setParameter('random', '%env(file:foo)%');
        $container->compile(true);

        $this->assertStringEqualsFile(__FILE__, $container->getParameter('random'));
    }

    /**
     * @expectedException \Symfony\Component\DependencyInjection\Exception\EnvParameterException
     * @expectedExceptionMessage Environment variables "FOO" are never used. Please, check your container's configuration.
     */
    public function testUnusedEnvParameter()
    {
        $container = new ContainerBuilder();
        $container->getParameter('env(FOO)');
        $container->compile();
        $dumper = new PhpDumper($container);
        $dumper->dump();
    }

    /**
     * @expectedException \Symfony\Component\DependencyInjection\Exception\ParameterCircularReferenceException
     * @expectedExceptionMessage Circular reference detected for parameter "env(resolve:DUMMY_ENV_VAR)" ("env(resolve:DUMMY_ENV_VAR)" > "env(resolve:DUMMY_ENV_VAR)").
     */
    public function testCircularDynamicEnv()
    {
        $container = new ContainerBuilder();
        $container->setParameter('foo', '%bar%');
        $container->setParameter('bar', '%env(resolve:DUMMY_ENV_VAR)%');
        $container->compile();

        $dumper = new PhpDumper($container);
        $dump = $dumper->dump(array('class' => $class = __FUNCTION__));

        eval('?>'.$dump);
        $container = new $class();

        putenv('DUMMY_ENV_VAR=%foo%');
        try {
            $container->getParameter('bar');
        } finally {
            putenv('DUMMY_ENV_VAR');
        }
    }

    public function testInlinedDefinitionReferencingServiceContainer()
    {
        $container = new ContainerBuilder();
        $container->register('foo', 'stdClass')->addMethodCall('add', array(new Reference('service_container')))->setPublic(false);
        $container->register('bar', 'stdClass')->addArgument(new Reference('foo'))->setPublic(true);
        $container->compile();

        $dumper = new PhpDumper($container);
        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services13.php', $dumper->dump(), '->dump() dumps inline definitions which reference service_container');
    }

    public function testInitializePropertiesBeforeMethodCalls()
    {
        require_once self::$fixturesPath.'/includes/classes.php';

        $container = new ContainerBuilder();
        $container->register('foo', 'stdClass')->setPublic(true);
        $container->register('bar', 'MethodCallClass')
            ->setPublic(true)
            ->setProperty('simple', 'bar')
            ->setProperty('complex', new Reference('foo'))
            ->addMethodCall('callMe');
        $container->compile();

        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Properties_Before_Method_Calls')));

        $container = new \Symfony_DI_PhpDumper_Test_Properties_Before_Method_Calls();
        $this->assertTrue($container->get('bar')->callPassed(), '->dump() initializes properties before method calls');
    }

    public function testCircularReferenceAllowanceForLazyServices()
    {
        $container = new ContainerBuilder();
        $container->register('foo', 'stdClass')->addArgument(new Reference('bar'))->setPublic(true);
        $container->register('bar', 'stdClass')->setLazy(true)->addArgument(new Reference('foo'))->setPublic(true);
        $container->compile();

        $dumper = new PhpDumper($container);
        $dumper->dump();

        $this->addToAssertionCount(1);
    }

    public function testCircularReferenceAllowanceForInlinedDefinitionsForLazyServices()
    {
        /*
         *   test graph:
         *              [connection] -> [event_manager] --> [entity_manager](lazy)
         *                                                           |
         *                                                           --(call)- addEventListener ("@lazy_service")
         *
         *              [lazy_service](lazy) -> [entity_manager](lazy)
         *
         */

        $container = new ContainerBuilder();

        $eventManagerDefinition = new Definition('stdClass');

        $connectionDefinition = $container->register('connection', 'stdClass')->setPublic(true);
        $connectionDefinition->addArgument($eventManagerDefinition);

        $container->register('entity_manager', 'stdClass')
            ->setPublic(true)
            ->setLazy(true)
            ->addArgument(new Reference('connection'));

        $lazyServiceDefinition = $container->register('lazy_service', 'stdClass');
        $lazyServiceDefinition->setPublic(true);
        $lazyServiceDefinition->setLazy(true);
        $lazyServiceDefinition->addArgument(new Reference('entity_manager'));

        $eventManagerDefinition->addMethodCall('addEventListener', array(new Reference('lazy_service')));

        $container->compile();

        $dumper = new PhpDumper($container);

        $dumper->setProxyDumper(new DummyProxyDumper());
        $dumper->dump();

        $this->addToAssertionCount(1);
    }

    public function testLazyArgumentProvideGenerator()
    {
        require_once self::$fixturesPath.'/includes/classes.php';

        $container = new ContainerBuilder();
        $container->register('lazy_referenced', 'stdClass')->setPublic(true);
        $container
            ->register('lazy_context', 'LazyContext')
            ->setPublic(true)
            ->setArguments(array(
                new IteratorArgument(array('k1' => new Reference('lazy_referenced'), 'k2' => new Reference('service_container'))),
                new IteratorArgument(array()),
            ))
        ;
        $container->compile();

        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Lazy_Argument_Provide_Generator')));

        $container = new \Symfony_DI_PhpDumper_Test_Lazy_Argument_Provide_Generator();
        $lazyContext = $container->get('lazy_context');

        $this->assertInstanceOf(RewindableGenerator::class, $lazyContext->lazyValues);
        $this->assertInstanceOf(RewindableGenerator::class, $lazyContext->lazyEmptyValues);
        $this->assertCount(2, $lazyContext->lazyValues);
        $this->assertCount(0, $lazyContext->lazyEmptyValues);

        $i = -1;
        foreach ($lazyContext->lazyValues as $k => $v) {
            switch (++$i) {
                case 0:
                    $this->assertEquals('k1', $k);
                    $this->assertInstanceOf('stdCLass', $v);
                    break;
                case 1:
                    $this->assertEquals('k2', $k);
                    $this->assertInstanceOf('Symfony_DI_PhpDumper_Test_Lazy_Argument_Provide_Generator', $v);
                    break;
            }
        }

        $this->assertEmpty(iterator_to_array($lazyContext->lazyEmptyValues));
    }

    public function testNormalizedId()
    {
        $container = include self::$fixturesPath.'/containers/container33.php';
        $container->compile();
        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services33.php', $dumper->dump());
    }

    public function testDumpContainerBuilderWithFrozenConstructorIncludingPrivateServices()
    {
        $container = new ContainerBuilder();
        $container->register('foo_service', 'stdClass')->setArguments(array(new Reference('baz_service')))->setPublic(true);
        $container->register('bar_service', 'stdClass')->setArguments(array(new Reference('baz_service')))->setPublic(true);
        $container->register('baz_service', 'stdClass')->setPublic(false);
        $container->compile();

        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services_private_frozen.php', $dumper->dump());
    }

    public function testServiceLocator()
    {
        $container = new ContainerBuilder();
        $container->register('foo_service', ServiceLocator::class)
            ->setPublic(true)
            ->addArgument(array(
                'bar' => new ServiceClosureArgument(new Reference('bar_service')),
                'baz' => new ServiceClosureArgument(new TypedReference('baz_service', 'stdClass')),
                'nil' => $nil = new ServiceClosureArgument(new Reference('nil')),
            ))
        ;

        // no method calls
        $container->register('translator.loader_1', 'stdClass')->setPublic(true);
        $container->register('translator.loader_1_locator', ServiceLocator::class)
            ->setPublic(false)
            ->addArgument(array(
                'translator.loader_1' => new ServiceClosureArgument(new Reference('translator.loader_1')),
            ));
        $container->register('translator_1', StubbedTranslator::class)
            ->setPublic(true)
            ->addArgument(new Reference('translator.loader_1_locator'));

        // one method calls
        $container->register('translator.loader_2', 'stdClass')->setPublic(true);
        $container->register('translator.loader_2_locator', ServiceLocator::class)
            ->setPublic(false)
            ->addArgument(array(
                'translator.loader_2' => new ServiceClosureArgument(new Reference('translator.loader_2')),
            ));
        $container->register('translator_2', StubbedTranslator::class)
            ->setPublic(true)
            ->addArgument(new Reference('translator.loader_2_locator'))
            ->addMethodCall('addResource', array('db', new Reference('translator.loader_2'), 'nl'));

        // two method calls
        $container->register('translator.loader_3', 'stdClass')->setPublic(true);
        $container->register('translator.loader_3_locator', ServiceLocator::class)
            ->setPublic(false)
            ->addArgument(array(
                'translator.loader_3' => new ServiceClosureArgument(new Reference('translator.loader_3')),
            ));
        $container->register('translator_3', StubbedTranslator::class)
            ->setPublic(true)
            ->addArgument(new Reference('translator.loader_3_locator'))
            ->addMethodCall('addResource', array('db', new Reference('translator.loader_3'), 'nl'))
            ->addMethodCall('addResource', array('db', new Reference('translator.loader_3'), 'en'));

        $nil->setValues(array(null));
        $container->register('bar_service', 'stdClass')->setArguments(array(new Reference('baz_service')))->setPublic(true);
        $container->register('baz_service', 'stdClass')->setPublic(false);
        $container->compile();

        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services_locator.php', $dumper->dump());
    }

    public function testServiceSubscriber()
    {
        $container = new ContainerBuilder();
        $container->register('foo_service', TestServiceSubscriber::class)
            ->setPublic(true)
            ->setAutowired(true)
            ->addArgument(new Reference(ContainerInterface::class))
            ->addTag('container.service_subscriber', array(
                'key' => 'bar',
                'id' => TestServiceSubscriber::class,
            ))
        ;
        $container->register(TestServiceSubscriber::class, TestServiceSubscriber::class)->setPublic(true);

        $container->register(CustomDefinition::class, CustomDefinition::class)
            ->setPublic(false);
        $container->compile();

        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services_subscriber.php', $dumper->dump());
    }

    public function testPrivateWithIgnoreOnInvalidReference()
    {
        require_once self::$fixturesPath.'/includes/classes.php';

        $container = new ContainerBuilder();
        $container->register('not_invalid', 'BazClass')
            ->setPublic(false);
        $container->register('bar', 'BarClass')
            ->setPublic(true)
            ->addMethodCall('setBaz', array(new Reference('not_invalid', SymfonyContainerInterface::IGNORE_ON_INVALID_REFERENCE)));
        $container->compile();

        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Private_With_Ignore_On_Invalid_Reference')));

        $container = new \Symfony_DI_PhpDumper_Test_Private_With_Ignore_On_Invalid_Reference();
        $this->assertInstanceOf('BazClass', $container->get('bar')->getBaz());
    }

    public function testArrayParameters()
    {
        $container = new ContainerBuilder();
        $container->setParameter('array_1', array(123));
        $container->setParameter('array_2', array(__DIR__));
        $container->register('bar', 'BarClass')
            ->setPublic(true)
            ->addMethodCall('setBaz', array('%array_1%', '%array_2%', '%%array_1%%', array(123)));
        $container->compile();

        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services_array_params.php', str_replace('\\\\Dumper', '/Dumper', $dumper->dump(array('file' => self::$fixturesPath.'/php/services_array_params.php'))));
    }

    public function testExpressionReferencingPrivateService()
    {
        $container = new ContainerBuilder();
        $container->register('private_bar', 'stdClass')
            ->setPublic(false);
        $container->register('private_foo', 'stdClass')
            ->setPublic(false);
        $container->register('public_foo', 'stdClass')
            ->setPublic(true)
            ->addArgument(new Expression('service("private_foo")'));

        $container->compile();
        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services_private_in_expression.php', $dumper->dump());
    }

    public function testUninitializedReference()
    {
        $container = include self::$fixturesPath.'/containers/container_uninitialized_ref.php';
        $container->compile();
        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/services_uninitialized_ref.php', $dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Uninitialized_Reference')));

        require self::$fixturesPath.'/php/services_uninitialized_ref.php';

        $container = new \Symfony_DI_PhpDumper_Test_Uninitialized_Reference();

        $bar = $container->get('bar');

        $this->assertNull($bar->foo1);
        $this->assertNull($bar->foo2);
        $this->assertNull($bar->foo3);
        $this->assertNull($bar->closures[0]());
        $this->assertNull($bar->closures[1]());
        $this->assertNull($bar->closures[2]());
        $this->assertSame(array(), iterator_to_array($bar->iter));

        $container = new \Symfony_DI_PhpDumper_Test_Uninitialized_Reference();

        $container->get('foo1');
        $container->get('baz');

        $bar = $container->get('bar');

        $this->assertEquals(new \stdClass(), $bar->foo1);
        $this->assertNull($bar->foo2);
        $this->assertEquals(new \stdClass(), $bar->foo3);
        $this->assertEquals(new \stdClass(), $bar->closures[0]());
        $this->assertNull($bar->closures[1]());
        $this->assertEquals(new \stdClass(), $bar->closures[2]());
        $this->assertEquals(array('foo1' => new \stdClass(), 'foo3' => new \stdClass()), iterator_to_array($bar->iter));
    }

    public function testAlmostCircularPrivate()
    {
        $public = false;
        $container = include self::$fixturesPath.'/containers/container_almost_circular.php';
        $container->compile();
        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/container_almost_circular_private.php', $dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Almost_Circular_Private')));

        require self::$fixturesPath.'/php/container_almost_circular_private.php';

        $container = new \Symfony_DI_PhpDumper_Test_Almost_Circular_Private();
        $foo = $container->get('foo');

        $this->assertSame($foo, $foo->bar->foobar->foo);
    }

    public function testAlmostCircularPublic()
    {
        $public = true;
        $container = include self::$fixturesPath.'/containers/container_almost_circular.php';
        $container->compile();
        $dumper = new PhpDumper($container);

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/container_almost_circular_public.php', $dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Almost_Circular_Public')));

        require self::$fixturesPath.'/php/container_almost_circular_public.php';

        $container = new \Symfony_DI_PhpDumper_Test_Almost_Circular_Public();
        $foo = $container->get('foo');

        $this->assertSame($foo, $foo->bar->foobar->foo);
    }

    public function testHotPathOptimizations()
    {
        $container = include self::$fixturesPath.'/containers/container_inline_requires.php';
        $container->setParameter('inline_requires', true);
        $container->compile();
        $dumper = new PhpDumper($container);

        $dump = $dumper->dump(array('hot_path_tag' => 'container.hot_path', 'inline_class_loader_parameter' => 'inline_requires', 'file' => self::$fixturesPath.'/php/container_inline_requires.php'));
        if ('\\' === DIRECTORY_SEPARATOR) {
            $dump = str_replace("'\\\\includes\\\\HotPath\\\\", "'/includes/HotPath/", $dump);
        }

        $this->assertStringEqualsFile(self::$fixturesPath.'/php/container_inline_requires.php', $dump);
    }

    public function testDumpHandlesLiteralClassWithRootNamespace()
    {
        $container = new ContainerBuilder();
        $container->register('foo', '\\stdClass')->setPublic(true);
        $container->compile();

        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Literal_Class_With_Root_Namespace')));

        $container = new \Symfony_DI_PhpDumper_Test_Literal_Class_With_Root_Namespace();

        $this->assertInstanceOf('stdClass', $container->get('foo'));
    }

    /**
     * This test checks the trigger of a deprecation note and should not be removed in major releases.
     *
     * @group legacy
     * @expectedDeprecation The "foo" service is deprecated. You should stop using it, as it will soon be removed.
     */
    public function testPrivateServiceTriggersDeprecation()
    {
        $container = new ContainerBuilder();
        $container->register('foo', 'stdClass')
            ->setPublic(false)
            ->setDeprecated(true);
        $container->register('bar', 'stdClass')
            ->setPublic(true)
            ->setProperty('foo', new Reference('foo'));

        $container->compile();

        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Private_Service_Triggers_Deprecation')));

        $container = new \Symfony_DI_PhpDumper_Test_Private_Service_Triggers_Deprecation();

        $container->get('bar');
    }

    public function testParameterWithMixedCase()
    {
        $container = new ContainerBuilder(new ParameterBag(array('Foo' => 'bar', 'BAR' => 'foo')));
        $container->compile();

        $dumper = new PhpDumper($container);
        eval('?>'.$dumper->dump(array('class' => 'Symfony_DI_PhpDumper_Test_Parameter_With_Mixed_Case')));

        $container = new \Symfony_DI_PhpDumper_Test_Parameter_With_Mixed_Case();

        $this->assertSame('bar', $container->getParameter('Foo'));
        $this->assertSame('foo', $container->getParameter('BAR'));
    }
}

class Rot13EnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv($prefix, $name, \Closure $getEnv)
    {
        return str_rot13($getEnv($name));
    }

    public static function getProvidedTypes()
    {
        return array('rot13' => 'string');
    }
}
