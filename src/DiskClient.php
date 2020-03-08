<?php

/**
 * Часть библиотеки для работы с сервисами Яндекса
 *
 * @package    Arhitector\Yandex
 * @version    2.0
 * @author     Arhitector
 * @license    MIT License
 * @copyright  2016 Arhitector
 * @link       https://github.com/jack-theripper
 */

namespace Arhitector\Yandex;

use Arhitector\Yandex\Client\AbstractClient;
use Arhitector\Yandex\Client\Container\ContainerTrait;
use Arhitector\Yandex\Client\Exception\ForbiddenException;
use Arhitector\Yandex\Client\Exception\UnauthorizedException;
use Arhitector\Yandex\Client\Exception\UnsupportedException;
use Arhitector\Yandex\Client\HttpClientTrait;
use Arhitector\Yandex\Client\OAuth;
use Arhitector\Yandex\Client\OAuthAuthentication;
use Arhitector\Yandex\Disk\Operation;
use Arhitector\Yandex\Disk\Resource\Opened;
use Arhitector\Yandex\Entity\DiskTrait;
use League\Event\Emitter;
use League\Event\EmitterTrait;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Zend\Diactoros\Request;
use Zend\Diactoros\Stream;
use Zend\Diactoros\Uri;

/**
 * The entry point for working with the disk.
 *
 * @package Arhitector\Yandex
 * @mixin DiskTrait
 */
class DiskClient extends AbstractClient /*implements \ArrayAccess, \IteratorAggregate, \Countable*/
{
    use ContainerTrait, EmitterTrait {
        toArray as protected _toArray;
    }

    /**
     * The base address of API. The default path component of the URI.
     */
    const API_BASE_PATH = 'https://cloud-api.yandex.net/v1/disk/';

    /**
     * @var string The access token for disk api.
     */
    protected $accessToken = null;

    /**
     * @var string[] A list identifiers of operations per session
     */
    protected $operations = [];

    /**
     * @var    string    для обращения к API требуется маркер доступа
     */
    protected $tokenRequired = true;

    /**
     * The entry point for working with the disk. You can set the access token or omit it if you only want to work with
     * public resources. You can also use your own client implementation otherwise will be used the first available
     * compatible client.
     *
     * @param string               $accessToken The access token for disk api or `null`
     * @param ClientInterface|null $client      The HTTP-client or `NULL` to use client by default.
     */
    public function __construct(?string $accessToken = null, ?ClientInterface $client = null)
    {
        parent::__construct($client); // The abstraction has a constructor for configuring the client

        if ($accessToken !== null)
        {
            $this->setAccessToken($accessToken);
        }

        // Used the default emitter (event transmitter) that comes with the league/event package
        $this->setEmitter(new Emitter());
    }

    /**
     * Sets the access token. All characters including spaces are taken into account.
     *
     * @param string $accessToken A new access token.
     *
     * @return DiskClient
     */
    public function setAccessToken(string $accessToken): DiskClient
    {
        if ( ! is_string($accessToken) || trim($accessToken) == '')
        {
            throw new \InvalidArgumentException('The OAuth token must not be an empty string.');
        }

        $this->accessToken = $accessToken;

        return $this;
    }

    /**
     * Returns the current access token or `null` if not set.
     *
     * @return string
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * Retrieve information about the disc
     *
     * @param array $allowed
     *
     * @return array
     */
    public function toArray(array $allowed = null)
    {
        if ( true || ! $this->_toArray())
        {
            $response = $this->sendRequest($this->createRequest('GET', '/'));

            if ($response->getStatusCode() == 200)
            {
                $response = json_decode($response->getBody(), true);

                if ( ! is_array($response))
                {
                    throw new UnsupportedException('Получен не поддерживаемый формат ответа от API Диска.');
                }

                $this->setContents($response += [
                    'free_space' => $response['total_space'] - $response['used_space']
                ]);
            }
        }

        return $this->_toArray($allowed);
    }

    /**
     * Clean the trash.
     *
     * @return bool|Operation
     */
    public function cleanTrash()
    {
        $response = $this->sendRequest($this->createRequest('DELETE', '/trash/resources'));
        $statusCode = $response->getStatusCode();

        if ($statusCode == 204)
        {
            return true;
        }

        if ($response->getStatusCode() == 202)
        {
            $response = json_decode($response->getBody(), true);

            if ( ! empty($response['operation']))
            {
                return new Operation($response['operation'], $this);
            }

            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // If you need to provide authentication according to the type of service.
        if ($this->tokenRequired && trim($this->getAccessToken()) != '')
        {
            $request = $request->withHeader('Authorization', sprintf('OAuth %s', $this->getAccessToken()));
        }

        $response = parent::sendRequest($request);

        if ($response->getStatusCode() == 200) // Successful
        {

        }

        if ($response->getStatusCode() == 202) // We got operation identifier
        {
            if (($responseBody = json_decode($response->getBody(), true)) && isset($responseBody['href']))
            {
                $operation = $this->createUri($responseBody['href']);

                if ( ! $operation->getQuery())
                {
                    $responseBody['operation'] = substr(strrchr($operation->getPath(), '/'), 1);
                    $stream = new Stream('php://temp', 'w');
                    $stream->write(json_encode($responseBody));
                    $this->addOperation($responseBody['operation']);

                    return $response->withBody($stream);
                }
            }
        }

        return $response;
    }

    /**
     * Adds the operation ID to the list.
     *
     * @param string $identifier
     *
     * @return DiskClient
     */
    protected function addOperation(string $identifier): DiskClient
    {
        // We don't have to add empty ID's to the list, but we also can't throw an exception because
        // the status of the operation will be lost
        if (trim($identifier) != '')
        {
            $this->operations[] = $identifier;
        }

        return $this;
    }






    /**
     * Работа с ресурсами на диске
     *
     * @param string  $path Путь к новому либо уже существующему ресурсу
     * @param integer $limit
     * @param integer $offset
     *
     * @return   \Arhitector\Yandex\Disk\Resource\Closed
     *
     * @example
     *
     * $disk->getResource('any_file.ext') -> upload( __DIR__.'/file_to_upload');
     * $disk->getResource('any_file.ext') // Mackey\Yandex\Disk\Resource\Closed
     *      ->toArray(); // если ресурса еще нет, то исключение NotFoundException
     *
     * array (size=11)
     * 'public_key' => string 'wICbu9SPnY3uT4tFA6P99YXJwuAr2TU7oGYu1fTq68Y=' (length=44)
     * 'name' => string 'Gameface - Gangsigns_trapsound.ru.mp3' (length=37)
     * 'created' => string '2014-10-08T22:13:49+00:00' (length=25)
     * 'public_url' => string 'https://yadi.sk/d/g0N4hNtXcrq22' (length=31)
     * 'modified' => string '2014-10-08T22:13:49+00:00' (length=25)
     * 'media_type' => string 'audio' (length=5)
     * 'path' => string 'disk:/applications_swagga/1/Gameface - Gangsigns_trapsound.ru.mp3' (length=65)
     * 'md5' => string '8c2559f3ce1ece12e749f9e5dfbda59f' (length=32)
     * 'type' => string 'file' (length=4)
     * 'mime_type' => string 'audio/mpeg' (length=10)
     * 'size' => int 8099883
     */
    public function getResource($path, $limit = 20, $offset = 0)
    {
        if ( ! is_string($path))
        {
            throw new \InvalidArgumentException('Ресурс, должен быть строкового типа - путь к файлу/папке.');
        }

        if (stripos($path, 'app:/') !== 0 && stripos($path, 'disk:/') !== 0)
        {
            $path = 'disk:/'.ltrim($path, ' /');
        }

        return (new Disk\Resource\Closed($path, $this))->setLimit($limit, $offset);
    }

    /**
     * Список всех файлов.
     *
     * @param int $limit
     * @param int $offset
     *
     * @return \Arhitector\Yandex\Disk\Resource\Collection
     *
     * @example
     *
     * $disk->getResources(100, 0) // Arhitector\Yandex\Disk\Resource\Collection
     *      ->toArray();
     *
     * array (size=2)
     * 0 => object(Arhitector\Yandex\Disk\Resource\Closed)[30]
     * .....
     */
    public function getResources($limit = 20, $offset = 0)
    {
        return (new Disk\Resource\Collection(function ($parameters) {
            $response = $this->sendRequest((new Request($this->createUri('resources/files')->withQuery(http_build_query($parameters, null, '&')), 'GET')));

            if ($response->getStatusCode() == 200)
            {
                $response = json_decode($response->getBody(), true);

                if (isset($response['items']))
                {
                    return array_map(function ($item) {
                        return new Disk\Resource\Closed($item, $this, $this->getCurrentUri());
                    }, $response['items']);
                }
            }

            return [];
        }))->setLimit($limit, $offset);
    }

    /**
     * Работа с опубликованными ресурсами
     *
     * @param mixed $public_key Публичный ключ к опубликованному ресурсу.
     * @param int $limit
     * @param int $offset
     *
     * @return Disk\Resource\Opened
     */
    public function getPublicResource(string $public_key, int $limit = 20, int $offset = 0): Opened
    {
        if ( ! is_string($public_key))
        {
            throw new \InvalidArgumentException('Публичный ключ ресурса должен быть строкового типа.');
        }

        return (new Disk\Resource\Opened($public_key, $this))
            ->setLimit($limit, $offset);
    }

    /**
     * Получение списка опубликованных файлов и папок
     *
     * @param int $limit
     * @param int $offset
     *
     * @return \Arhitector\Yandex\Disk\Resource\Collection
     */
    public function getPublicResources($limit = 20, $offset = 0)
    {
        return (new Disk\Resource\Collection(function ($parameters) {
            $previous = $this->setAccessTokenRequired(false);
            $response = $this->sendRequest((new Request($this->requestUri->withPath($this->getCurrentUri()
                    ->getPath().'resources/public')->withQuery(http_build_query($parameters, null, '&')), 'GET')));
            $this->setAccessTokenRequired($previous);

            if ($response->getStatusCode() == 200)
            {
                $response = json_decode($response->getBody(), true);

                if (isset($response['items']))
                {
                    return array_map(function ($item) {
                        return new Disk\Resource\Opened($item, $this, $this->getCurrentUri());
                    }, $response['items']);
                }
            }

            return [];
        }))->setLimit($limit, $offset);
    }

    /**
     * Ресурсы в корзине.
     *
     * @param string $path путь к файлу в корзине
     * @param int    $limit
     * @param int    $offset
     *
     * @return \Arhitector\Yandex\Disk\Resource\Removed
     * @example
     *
     * $disk->getTrashResource('file.ext') -> toArray() // файл в корзине
     * $disk->getTrashResource('trash:/file.ext') -> delete()
     */
    public function getTrashResource($path, $limit = 20, $offset = 0)
    {
        if ( ! is_string($path))
        {
            throw new \InvalidArgumentException('Ресурс, должен быть строкового типа - путь к файлу/папке, либо NULL');
        }

        if (stripos($path, 'trash:/') === 0)
        {
            $path = substr($path, 7);
        }

        return (new Disk\Resource\Removed('trash:/'.ltrim($path, ' /'), $this, $this->getCurrentUri()))->setLimit($limit,
            $offset);
    }

    /**
     * Содержимое всей корзины.
     *
     * @param int $limit
     * @param int $offset
     *
     * @return \Arhitector\Yandex\Disk\Resource\Collection
     */
    public function getTrashResources($limit = 20, $offset = 0)
    {
        return (new Disk\Resource\Collection(function ($parameters) {
            if ( ! empty($parameters['sort']) && ! in_array($parameters['sort'],
                    ['deleted', 'created', '-deleted', '-created']))
            {
                throw new \UnexpectedValueException('Допустимые значения сортировки - deleted, created и со знаком "минус".');
            }

            $response = $this->sendRequest((new Request($this->getCurrentUri()->withPath($this->getCurrentUri()
                    ->getPath().'trash/resources')->withQuery(http_build_query($parameters + ['path' => 'trash:/'],
                null, '&')), 'GET')));

            if ($response->getStatusCode() == 200)
            {
                $response = json_decode($response->getBody(), true);

                if (isset($response['_embedded']['items']))
                {
                    return array_map(function ($item) {
                        return new Disk\Resource\Removed($item, $this, $this->getCurrentUri());
                    }, $response['_embedded']['items']);
                }
            }

            return [];
        }))->setSort('created')->setLimit($limit, $offset);
    }



    /**
     * Последние загруженные файлы
     *
     * @param integer $limit
     * @param integer $offset
     *
     * @return   \Arhitector\Yandex\Disk\Resource\Collection
     *
     * @example
     *
     * $disk->uploaded(limit, offset) // коллекия закрытых ресурсов
     */
    public function uploaded($limit = 20, $offset = 0)
    {
        return (new Disk\Resource\Collection(function ($parameters) {
            $response = $this->sendRequest((new Request($this->getCurrentUri()->withPath($this->getCurrentUri()
                    ->getPath().'resources/last-uploaded')->withQuery(http_build_query($parameters, null, '&')),
                'GET')));

            if ($response->getStatusCode() == 200)
            {
                $response = json_decode($response->getBody(), true);

                if (isset($response['items']))
                {
                    return array_map(function ($item) {
                        return new Disk\Resource\Closed($item, $this, $this->getCurrentUri());
                    }, $response['items']);
                }
            }

            return [];
        }))->setLimit($limit, $offset);
    }

    /**
     * Получить статус операции.
     *
     * @param string $identifier идентификатор операции или NULL
     *
     * @return  \Arhitector\Yandex\Disk\Operation
     *
     * @example
     *
     * $disk->getOperation('identifier operation')
     */
    public function getOperation($identifier)
    {
        return new Disk\Operation($identifier, $this);
    }

    /**
     * Возвращает количество асинхронных операций экземпляра.
     *
     * @return int
     */
    public function count()
    {
        return sizeof($this->getOperations());
    }

    /**
     * Получить все операции, полученные во время выполнения сценария
     *
     * @return array
     *
     * @example
     *
     * $disk->getOperations()
     *
     * array (size=124)
     *  0 => 'identifier_1',
     *  1 => 'identifier_2',
     *  2 => 'identifier_3',
     */
    public function getOperations()
    {
        return $this->operations;
    }


    	protected function authentication(RequestInterface $request)
    	{
    		if ($this->tokenRequired)
    		{
    			return $request->withHeader('Authorization', sprintf('OAuth %s', 'AgAAAAAWBdH4AANc6AXecIgRP0oyifkXo5SRW4o'));
    		}

    		return $request;
    	}
    /**
     * Этот экземпляр используется в качестве обёртки
     *
     * @return boolean
     */
    public function isWrapper()
    {
        //return in_array(\Mackey\Yandex\Disk\Stream\Wrapper::SCHEME, stream_get_wrappers());
        return false;
    }

    /**
     * Устаналивает необходимость токена при запросе.
     *
     * @param $tokenRequired
     *
     * @return boolean  возвращает предыдущее состояние
     */
    protected function setAccessTokenRequired($tokenRequired)
    {
        $previous = $this->tokenRequired;
        $this->tokenRequired = (bool) $tokenRequired;

        return $previous;
    }

}