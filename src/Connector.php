<?php

namespace Cake\Codeception;

use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Http\Request;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Routing\DispatcherFactory;
use Cake\Utility\Hash;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\BrowserKit\Request as BrowserKitRequest;
use Symfony\Component\BrowserKit\Response as BrowserKitResponse;

class Connector extends AbstractBrowser
{
    /**
     * Associative array of CakePHP classes:
     *
     *  - request: \Cake\Http\Request
     *  - response: \Cake\Http\Response
     *  - session: \Cake\Http\Session
     *  - controller: \Cake\Controller\Controller
     *  - view: \Cake\View\View
     *  - auth: \Cake\Controller\Component\AuthComponent
     *  - cookie: \Cake\Controller\Component\CookieComponent
     *
     * @var array
     */
    public $cake;

    /**
     * Get instance of the session.
     *
     * @return \Cake\Http\Session
     */
    public function getSession()
    {
        if (!empty($this->cake['session'])) {
            return $this->cake['session'];
        }

        if (!empty($this->cake['request'])) {
            $this->cake['session'] = $this->cake['request']->session();
            return $this->cake['session'];
        }

        $config = (array)Configure::read('Session') + ['defaults' => 'php'];
        $this->cake['session'] = Session::create($config);
        return $this->cake['session'];
    }

    /**
     * Filters the BrowserKit request to the cake one.
     *
     * @param \Symfony\Component\BrowserKit\Request $request BrowserKit request.
     * @return \Cake\Http\Request Cake request.
     */
    protected function filterRequest(BrowserKitRequest $request)
    {
        $url = preg_replace('/^https?:\/\/[a-z0-9\-\.]+/', '', $request->getUri());

        $_ENV = $environment = ['REQUEST_METHOD' => $request->getMethod()] + $request->getServer();

        $files = (array)$request->getFiles();
        $props = [
            'url' => $url,
            'post' => (array)$request->getParameters(),
            'files' => $files,
            'cookies' => (array)$request->getCookies(),
            'session' => $this->getSession(),
            'environment' => $environment,
        ];

        $request = new \Cake\Http\ServerRequest($props);
        $this->cake['request'] = $this->marshalFiles($files, $request);

        return $this->cake['request'];
    }

    /**
     * ServerRequestFactory::marshalFiles のコピー
     *
     * 該当のメソッドが protected で呼び出せない（そもそも構造がテストデータの生成に適していない）ので
     * 同等の処理をコピーしている.
     *
     * @see \Cake\Http\ServerRequestFactory::marshalFiles()
     * @param array $files
     * @param \Cake\Http\ServerRequest $request
     * @return \Cake\Http\ServerRequest
     */
    protected function marshalFiles(array $files, ServerRequest $request): ServerRequest
    {
        $files = \Laminas\Diactoros\normalizeUploadedFiles($files);
        $request = $request->withUploadedFiles($files);

        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody)) {
            return $request;
        }

        if (Configure::read('App.uploadedFilesAsObjects', true)) {
            $parsedBody = Hash::merge($parsedBody, $files);
        } else {
            // Make a flat map that can be inserted into body for BC.
            $fileMap = Hash::flatten($files);
            foreach ($fileMap as $key => $file) {
                $error = $file->getError();
                $tmpName = '';
                if ($error === UPLOAD_ERR_OK) {
                    $tmpName = $file->getStream()->getMetadata('uri');
                }
                $parsedBody = Hash::insert($parsedBody, (string)$key, [
                    'tmp_name' => $tmpName,
                    'error' => $error,
                    'name' => $file->getClientFilename(),
                    'type' => $file->getClientMediaType(),
                    'size' => $file->getSize(),
                ]);
            }
        }

        return $request->withParsedBody($parsedBody);
    }

    /**
     * Filters the cake response to the BrowserKit one.
     *
     * @param \Cake\Http\Response $response Cake response.
     * @return \Symfony\Component\BrowserKit\Response BrowserKit response.
     */
    protected function filterResponse($response)
    {
        $this->cake['response'] = $response;
        if (is_a($response, '\Laminas\Diactoros\Response')) {
            $response = $this->convertToCakeResponse($response);
        }

        foreach ($response->getCookies() as $cookie) {
            $this->getCookieJar()->set(new Cookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['expires'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httponly']
            ));
        }

        return new BrowserKitResponse(
            $response->getBody(),
            $response->getStatusCode(),
            $response->getHeaders()
        );
    }

    /**
     * Convert to CakeResponse.
     *
     * @param \Laminas\Diactoros\Response $response
     * @return void
     */
    private function convertToCakeResponse(\Laminas\Diactoros\Response $response)
    {
        $body = $this->parseBody($response);
        $data = [
            'status' => $response->getStatusCode(),
            'body' => $body['body'],
            'headers' => $response->getHeaders(),
        ];
        $cake = new Response($data);
        if ($body['file']) {
            $cake = $cake->withFile($body['file']);
        }
        $cookies = $this->parseCookies($response->getHeader('Set-Cookie'));
        foreach ($cookies as $cookie) {
            $cake = $cake->withCookie($cookie);
        }
        return $cake;
    }

    /**
     * Parse response body.
     *
     * @param \Laminas\Diactoros\Response $response
     * @return array
     */
    private function parseBody(\Laminas\Diactoros\Response $response)
    {
        $stream = $response->getBody();
        if ($stream->getMetadata('wrapper_type') === 'plainfile') {
            return ['body' => '', 'file' => $stream->getMetadata('uri')];
        }
        if ($stream->getSize() === 0) {
            return ['body' => '', 'file' => false];
        }
        $stream->rewind();

        return ['body' => $stream->getContents(), 'file' => false];
    }

    /**
     * Parse cookies.
     *
     * @param array $cookieHeader
     * @return array
     */
    private function parseCookies(array $cookieHeader)
    {
        $cookies = [];
        foreach ($cookieHeader as $cookie) {
            if (strpos($cookie, '";"') !== false) {
                $cookie = str_replace('";"', '{__cookie_replace__}', $cookie);
                $parts = preg_split('/\;[ \t]*/', $cookie);
                $parts = str_replace('{__cookie_replace__}', '";"', $parts);
            } else {
                $parts = preg_split('/\;[ \t]*/', $cookie);
            }

            list($name, $value) = explode('=', array_shift($parts), 2);
            $parsed = ['name' => $name, 'value' => urldecode($value)];

            foreach ($parts as $part) {
                if (strpos($part, '=') !== false) {
                    list($key, $value) = explode('=', $part);
                } else {
                    $key = $part;
                    $value = true;
                }

                $key = strtolower($key);
                if ($key === 'httponly') {
                    $key = 'httpOnly';
                }
                if ($key === 'expires') {
                    $key = 'expire';
                    $value = strtotime($value);
                }
                if (!isset($parsed[$key])) {
                    $parsed[$key] = $value;
                }
            }
            $cookies[] = $parsed;
        }

        return $cookies;
    }

    /**
     * Makes a request.
     *
     * @param \Cake\Http\Request $request Cake request.
     * @return \Cake\Http\Response Cake response.
     */
    protected function doRequest($request)
    {
        $response = new Response();

        try {
            $response = $this->runApplication($request);
        } catch (\PHPUnit_Exception $e) {
            throw $e;
        } catch (\Exception $e) {
            $response = $this->handleError($e);
        }

        return $response;
    }

    /**
     * Run application CakePHP >= 3.4
     *
     * @return \Cake\Http\Response Cake response.
     */
    protected function runApplication($request)
    {
        $applicationClass = $this->getApplicationClassName();
        $server = new \Cake\Http\Server(new $applicationClass(CONFIG));

        $server->getEventManager()->on(
            'Dispatcher.beforeDispatch',
            ['priority' => 999],
            [$this, 'controllerSpy']
        );
        $response = $server->run($request);

        return $response;
    }

    /**
     * Attempts to render an error response for a given exception.
     *
     * This method will attempt to use the configured exception renderer.
     * If that class does not exist, the built-in renderer will be used.
     *
     * @param \Exception $exception Exception to handle.
     * @return void
     * @throws \Exception
     */
    protected function handleError($exception)
    {
        $class = Configure::read('Error.exceptionRenderer');
        if (empty($class) || !class_exists($class)) {
            $class = 'Cake\Error\ExceptionRenderer';
        }
        $instance = new $class($exception);
        return $instance->render();
    }

    /**
     * [controllerSpy description]
     * @param \Cake\Event\Event $event Event.
     */
    public function controllerSpy(Event $event)
    {
        if (!$event->getData('controller')) {
            return;
        }

        $this->cake['controller'] = $event->getData('controller');
        $eventManager = $event->getData('controller')->getEventManager();

        $eventManager->on(
            'Controller.startup',
            ['priority' => 999],
            [$this, 'authSpy']
        );


        $eventManager->on(
            'View.beforeRender',
            ['priority' => 999],
            [$this, 'viewSpy']
        );
    }

    /**
     * [authSpy description]
     * @param \Cake\Event\Event $event Event.
     */
    public function authSpy(Event $event)
    {
        if ($event->subject()->Auth) {
            $this->cake['auth'] = $event->subject()->Auth;
        }
    }

    /**
     * [viewSpy description]
     * @param \Cake\Event\Event $event Event.
     */
    public function viewSpy(Event $event)
    {
        $this->cake['view'] = $event->subject();
    }

    /**
     * Get Application class name
     *
     * @return string
     */
    protected function getApplicationClassName()
    {
        return '\\' . Configure::read('App.namespace') . '\Application';
    }

    /**
     * App has Application class
     *
     * @return bool
     */
    protected function hasApplicationClass()
    {
        return class_exists('\\' . Configure::read('App.namespace') . '\Application');
    }
}
