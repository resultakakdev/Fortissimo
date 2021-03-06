<?php
/**
 * Unit tests for the Fortissimo class.
 */
namespace Fortissimo\Tests;

require_once 'TestCase.php';

/**
 * @group deprecated
 */
class FortissimoTest extends TestCase {
  
  const config = './test/test_commands.php';
  
  public function setUp() {
    \Fortissimo\Registry::initialize();
  }
  
  public function testConstructor() {
    $ff = new \Fortissimo(self::config);
    
    $this->assertTrue($ff instanceof \Fortissimo);
  }
  
  public function testFetchParams() {
    $_GET['foo'] = 'bar';
    $ff = new FortissimoHarness(self::config);
    
    $ff->setParams(array('foo' => 'bar'), 'get');
    
    $bar = $ff->fetchParam('get:foo');
    $this->assertEquals('bar', $bar, 'GET test');
    
    $bar = $ff->fetchParam('g:foo');
    $this->assertEquals('bar', $bar, 'GET test');
    
    $bar = $ff->fetchParam('GET:foo');
    $this->assertEquals('bar', $bar, 'GET test');
    
    $ff->setParams(array('foo' => 'bar'), 'post');
    $bar = $ff->fetchParam('post:foo');
    $this->assertEquals('bar', $bar, 'POST test');
    
    $ff->setParams(array('foo' => 'bar'), 'cookie');
    $bar = $ff->fetchParam('cookie:foo');
    $this->assertEquals('bar', $bar, 'Cookie test');
    
    $ff->setParams(array('foo2' => 'bar2'), 'get');
    $bar = $ff->fetchParam('request:foo2');
    $this->assertEquals('bar2', $bar, 'Reqest test');
    
    $ff->setParams(array('foo3' => 'bar2'), 'post');
    $bar = $ff->fetchParam('request:foo3');
    $this->assertEquals('bar2', $bar, 'Reqest test');
    
    $bar = $ff->fetchParam('request:noSuchThing');
    $this->assertNull($bar, 'Test miss');
    
    $bar = $ff->fetchParam('get:noSuchThing');
    $this->assertNull($bar, 'Test miss');
  }
  
  public function testHandleRequest() {
    $ff = new FortissimoHarness(self::config);
    $ff->handleRequest('testHandleRequest1');
    
    $cxt = $ff->getContext();
    
    $this->assertEquals('test', $cxt->get('mockCommand'));
    
    $ff->handleRequest('testHandleRequest2');
    $this->assertEquals('From Default', $ff->getContext()->get('mockCommand2'));
    
    $ff->handleRequest('testHandleRequest3');
    $this->assertEquals('From Default 2', $ff->getContext()->get('repeater'));
    
  }
  
  public function testLogger() {
    $ff = new FortissimoHarness(self::config);
    $ff->logException();
    
    $logger = $ff->loggerManager()->getLoggerByName('fail');
    $this->assertNotNull($logger, 'Logger exists.');
    $this->assertEquals(1, count($logger->getMessages()));
  }
    
  public function testRequestCache() {
    
    // Munge the config.
    include self::config;
    Config::cache('foo')->whichInvokes('MockAlwaysReturnFooCache');
    $config = Config::getConfiguration();
    //$config[Config::CACHES]['foo']['class'] = 'MockAlwaysReturnFooCache';
    Config::initialize($config);
    
    $ff = new FortissimoHarness();
    
    ob_start();
    $ff->handleRequest('testRequestCache1');
    $res = ob_get_contents();
    ob_end_clean();
    
    $this->assertEquals('foo', $res);
    
    unset($config[Config::CACHES]['foo']);
    
    // Second, test to see if values can be written to cache.
    //$config[Config::CACHES]['foo']['class'] = 'MockAlwaysSetValueCache';
    Config::cache('foo')
      ->whichInvokes('MockAlwaysSetValueCache')
       ->withParam('isDefault')->whoseValueIs(TRUE);
    //Config::initialize($config);
    
    $ff = new FortissimoHarness();
    
    ob_start();
    $ff->handleRequest('testRequestCache2');
    $res = ob_get_contents();
    ob_end_clean();
    
    $cacheManager = $ff->cacheManager();
    
    $key = $ff->genCacheKey('testRequestCache2');
    $this->assertEquals('bar', $cacheManager->get($key), 'Has cached item.');
    // We also want to make sure that the output was passed on correctly.
    $this->assertEquals('bar', $res, 'Output was passed through correctly.');
    
    // Finally, make sure that a request still works if no cacher is configured.
    $ff = new FortissimoHarness(self::config);
    ob_start();
    $ff->handleRequest('testRequestCache2');
    $res = ob_get_contents();
    ob_end_clean();
    
    $this->assertEquals('bar', $res);
  }
  
  public function testForwardRequest() {
    $ff = new FortissimoHarness(self::config);
    $ff->handleRequest('testForwardRequest1');
    
    $cxt = $ff->getContext();
    //$this->assertEquals(2, $cxt->size(), 'There should be two items in the context.');
    $this->assertTrue($cxt->has('mockCommand2'), 'Mock command is in context.');
    $this->assertTrue($cxt->has('forwarder'), 'Forwarder command is in context.');
  }
  
  public function testAutoloader() {
    $path = get_include_path();
    $paths = explode(PATH_SEPARATOR, $path);
    
    $this->assertTrue(in_array('test/Tests/Fortissimo/Stubs', $paths));
    
    //$class = new LoaderStub();
    //$this->assertTrue($class->isLoaded(), 'Verify that classes are autoloaded.');
  }
  
  public function testRequestMapper() {
    $ff = new FortissimoHarness(self::config);
    
    $ff->handleRequest('NonExistentRequestName');
    $this->assertEquals('From Default', $ff->getContext()->get('mockCommand2'));
  }

}

// //////////////////////////// //
// MOCKS
// //////////////////////////// //

class MockAlwaysReturnFooCache extends \Fortissimo\Cache\Base /* implements FortissimoRequestCache */ {
  public function init(){}
  public function set($k, $v, $t = NULL) {}
  public function get($key) { return 'foo'; }
  public function delete($key) {}
  public function clear() {}
}

class MockAlwaysSetValueCache extends \Fortissimo\Cache\Base /* implements FortissimoRequestCache */ {
  public $cache = array();
  public function init(){}
  public function set($k, $v, $t = NULL) {$this->cache[$k] = $v;}
  public function get($key) {
    // Avoid E_STRICT warnings.
    return isset($this->cache[$key]) ? $this->cache[$key] : NULL;
  }
  public function delete($key) {}
  public function clear() {}
}

class MockCommand implements \Fortissimo\Command {
  public $name = NULL;
  public function __construct($name) {
    $this->name = $name;
  }
  
  public function execute($paramArray, \Fortissimo\ExecutionContext $cxt) {
    $value = isset($paramArray['value']) ? $paramArray['value'] : 'test';
    $cxt->add($this->name, $value);
  }
  
  public function isCacheable() {return FALSE;}
}

class MockPrintBarCommand implements \Fortissimo\Command {
  public $name = NULL;
  public function __construct($name) {
    $this->name = $name;
  }
  
  public function execute($p, \Fortissimo\ExecutionContext $cxt) {
    print 'bar';
  }
  
  public function isCacheable() {return TRUE;}
}

/**
 * re-maps all requests to 'default'.
 */
class MockRequestMapper extends \Fortissimo\RequestMapper {
  public function uriToRequest($string) {
    if ($string == 'NonExistentRequestName') return 'testHandleRequest2';
    
    return parent::uriToRequest($string);
  }
}

class CommandRepeater implements \Fortissimo\Command {
  public $name = NULL;
  public function __construct($name) {
    $this->name = $name;
  }
  
  public function execute($paramArray, \Fortissimo\ExecutionContext $cxt) {
    $cxt->add($this->name, $paramArray['cmd']);
  }
  public function isCacheable() {return FALSE;}
  
}

class CommandForward implements \Fortissimo\Command {
  public $name;
  
  public function __construct($name) {
    $this->name = $name;
  }
  
  public function execute($paramArray, \Fortissimo\ExecutionContext $cxt) {
    $forwardTo = $paramArray['forward'];
    $cxt->add($this->name, __CLASS__);
    throw new \Fortissimo\ForwardRequest($forwardTo, $cxt);
  }
  public function isCacheable() {return FALSE;}
  
}

/**
 * Harness methods for testing specific parts of Fortissimo.
 */
class FortissimoHarness extends \Fortissimo {
  
  public function __construct($file = NULL) {
    if (isset($file)) {
      \Fortissimo\Registry::initialize();
    }
    parent::__construct($file);
  }
  
  public function hasRequest($requestName) {
    
    $r = $this->requestMapper->uriToRequest($requestName);
    return $this->commandConfig->hasRequest($r);
    
  }
  
  public $pSources = array(
    'get' => array(),
    'post' => array(),
    'cookie' => array(),
    'session' => array(),
    'env' => array(),
    'server' => array(),
    'argv' => array(),
  );
  
  /**
   * Push an exception into the system as if it were real.
   */
  public function logException($e = NULL) {
    if (empty($e)) {
      $e = new Exception('Dummy exception');
    }
    $this->logManager->log($e, 'Exception');
  }
  
  public function getContext() {
    return $this->cxt;
  }
  
  public function fetchParam($param) {
    return $this->fetchParameterFromSource($param);
  }
  
  public function setParams($params = array(), $source = 'get') {
    $this->pSources[$source] = $params;
  } 
   
  protected function fetchParameterFromSource($from) {
    list($proto, $paramName) = explode(':', $from, 2);
    $proto = strtolower($proto);
    switch ($proto) {
      case 'g':
      case 'get':
        return isset($this->pSources['get'][$paramName]) ? $this->pSources['get'][$paramName] : NULL;
      case 'p':
      case 'post':
        return $this->pSources['post'][$paramName];
      case 'c':
      case 'cookie':
      case 'cookies':
        return $this->pSources['cookie'][$paramName];
      case 's':
      case 'session':
        return $this->pSources['session'][$paramName];
      case 'x':
      case 'cmd':
      case 'context':
        return $this->cxt->get($paramName);
      case 'e':
      case 'env':
      case 'environment':
        return $this->pSources['env'][$paramName];
      case 'server':
        return $this->pSources['server'][$paramName];
      case 'r':
      case 'request':
        return isset($this->pSources['get'][$paramName]) ? $this->pSources['get'][$paramName] : (isset($this->pSources['post'][$paramName]) ? $this->pSources['post'][$paramName] : NULL);
      case 'a':
      case 'arg':
      case 'argv':
        return $argv[(int)$paramName];
    }
  }
  
}
