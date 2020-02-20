<?php

namespace App\Services;

class InputService
{

    /**
     * клиент, с помощью которого собираются данные из источника
     * @var string
     */
    private string $client;

    /**
     * УРЛ источника данных
     * @var string
     */
    private string $sourceUrl;

    /**
     * УРЛ точки АПИ для получения авторизационного токена
     * @var string
     */
    private string $authTokenApiPoint;

    /**
     * json_decode-ированный ответ от источника данных
     * @var array
     */
    public array $sourceResponse;

    //данные для авторизации на стороне источника данных
    public $clientIdSp = null;
    public $clientSecretSp = null;


    /**
     * InputService constructor.
     * @param string $client - клиент (guzzle, curl, file_get_contents)
     * @param string|null $sourceUrl - УРЛ источника данных
     * @param string|null $authTokenApiPoint - УРЛ точки АПИ для получения авторизационного токена
     * @param array $authData
     */
    public function __construct($client = 'guzzle', string $sourceUrl = null, string $authTokenApiPoint = null, array $authData = [])
    {

        $this->setClient($client);
        $this->setSourceUrl($sourceUrl);
        $this->setAuthData($authData);
        $this->setAuthTokenApiPoint($authTokenApiPoint);

    }

    /**
     * @return string
     */
    public function getClient(): string
    {
        return $this->client;
    }

    /**
     * @param string $client
     * @return InputService
     */
    public function setClient(string $client): InputService
    {
        $this->client = $client;
        return $this;
    }

    /**
     * @return string
     */
    public function getAuthTokenApiPoint(): string
    {
        return $this->authTokenApiPoint;
    }

    /**
     * @param string $authTokenApiPoint
     * @return InputService
     */
    public function setAuthTokenApiPoint(string $authTokenApiPoint): InputService
    {
        $this->authTokenApiPoint = $authTokenApiPoint;
        return $this;
    }

    /**
     * @return string
     * @throws \Exception
     */
    public function getSourceUrl(): string
    {
        if (empty($this->sourceUrl)) {
            throw new \Exception('No source URL specified. Cannot continue.');
        }
        return $this->sourceUrl;
    }

    /**
     * @param string $sourceUrl
     * @return InputService
     */
    public function setSourceUrl(string $sourceUrl): InputService
    {
        $this->sourceUrl = $sourceUrl;
        return $this;
    }

    /**
     * @param array $authData
     * @return InputService
     */
    public function setAuthData(array $authData): InputService
    {
        if (!empty($authData) && count($authData == 2)) {
            $this->clientIdSp = $authData[0] ?? null;
            $this->clientSecretSp = $authData[1] ?? null;
        }
        return $this;
    }

    /**
     * проверяет, является ли строка json-ом
     *
     * @param $string
     * @return bool
     */
    private function isJson($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * возвращает авторизационный токен из АПИ источника
     * @return string|null
     * @throws \Exception
     */
    private function getAuthToken()
    {

        $authTokenApiPoint = $this->getAuthTokenApiPoint();

        if (empty($authTokenApiPoint)) {
            throw new \Exception('No authTokenApiPoint URL specified. Cannot continue.');
        }

        $response = file_get_contents($authTokenApiPoint . '?query=' . sprintf('{auth(client_id:%s,client_secret:"%s"){token}}', $this->clientIdSp, $this->clientSecretSp));

        $content = json_decode($response);
        return $content->data->auth->token ?? null;
    }

    /**
     * запрашивает из источника данные, возвращает json_decode-ированный ответ
     * @return false|mixed|string
     * @throws \Exception
     */
    public function getDataFromSource()
    {

        //требуется ли авторизация (да, если установлены данные для авторизации)
        $authSet = !empty($this->clientIdSp) && !empty($this->clientSecretSp);

        switch ($this->getClient()) {

            case 'guzzle':

                $client = new \GuzzleHttp\Client([
                    'verify' => false,
                    'timeout' => 10.0,
                ]);

                $params['headers'] = [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ];

                if ($authSet) {
                    $params['headers'] = [
                        'Authorization' => 'Bearer ' . $this->getAuthToken(),
                    ];
                }

                try {

                    $response = $client->get($this->getSourceUrl(), $params);

                } catch (\Exception $e) {

                    if ($e->getCode() != 200) {
                        throw new \Exception('Got ' . $e->getCode() . ' code instead of 200 from source. Check source url: ' . $this->getSourceUrl());
                    }

                }

                $content = $response->getBody()->getContents();

                if (empty($content)) {
                    throw new \Exception('No data from source. Check source url: ' . $this->getSourceUrl());
                }

                if (!$this->isJson($content)) {
                    throw new \Exception('Not a JSON response from source. Check source url: ' . $this->getSourceUrl());
                }

                $this->sourceResponse = json_decode($content, 1);
                break;


            case 'curl':

                $ch = curl_init();

                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    $authSet ? 'Authorization: Bearer ' . $this->getAuthToken() : '',
                ]);

                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_URL, $this->getSourceUrl());
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15); //timeout in seconds
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

                $content = curl_exec($ch);

                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
                    throw new \Exception('Got ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' code instead of 200 from source. Check source url: ' . $this->getSourceUrl());
                }

                curl_close($ch);

                if (empty($content)) {
                    throw new \Exception('No data from source. Check source url: ' . $this->getSourceUrl());
                }

                if (!$this->isJson($content)) {
                    throw new \Exception('Not a JSON response from source. Check source url: ' . $this->getSourceUrl());
                }

                $this->sourceResponse = json_decode($content, 1);
                break;


            case 'file_get_contents':

                $opts = [
                    "ssl" => [
                        "verify_peer" => false,
                        "verify_peer_name" => false,
                    ],
                    "http" => [
                        "method" => "GET",
                        'ignore_errors' => true,
                        "header" => "Accept-language: en\r\n" .
                            "Content-Type: application/json\r\n" .
                            "Accept: application/json\r\n" .
                            ($authSet ? "Authorization: Bearer " . $this->getAuthToken() . "\r\n" : ''),
                    ],
                ];

                $context = stream_context_create($opts);

                $content = file_get_contents($this->getSourceUrl(), false, $context);

                if (substr($http_response_header[0], 9, 3) != 200) {
                    throw new \Exception('Got ' . $http_response_header[0] . ' code header instead of 200 from source. Check source url: ' . $this->getSourceUrl());
                }

                if (empty($content)) {
                    throw new \Exception('No data from source. Check source url: ' . $this->getSourceUrl());
                }

                if (!$this->isJson($content)) {
                    throw new \Exception('Not a JSON response from source. Check source url: ' . $this->getSourceUrl());
                }

                $this->sourceResponse = json_decode($content, 1);
                break;

            default:
                throw new \Exception('Client type not provided');
        }

        return $this->sourceResponse;

    }

}
