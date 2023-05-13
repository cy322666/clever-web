<?php

namespace App\Services\Bizon365;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use JetBrains\PhpStorm\ArrayShape;

class Client
{
    private static string $base_url = 'https://online.bizon365.ru/api/';
    
    private static string $version = 'v1';
    
    private string $token;
    
    private string $login;
    private string $password;
    
    private \GuzzleHttp\Client $http;
    
    public function __construct()
    {
        $this->http = new \GuzzleHttp\Client();
    }
    
    public function setLogin(string $login): static
    {
        $this->login = $login;
        
        return $this;
    }
    
    public function setPassword(string $password): static
    {
        $this->password = $password;
        
        return $this;
    }
    
    public function setToken(string $token): static
    {
        $this->token = $token;
        
        return $this;
    }
    
    /**
     * @throws GuzzleException
     */
    public function webinar(string $id)
    {
        $response = $this->http->get(self::$base_url.self::$version.'/webinars/reports/get?webinarId='.$id, [
            'headers' => [
                'X-Token' => $this->token,
            ]
        ]);
        
        return json_decode(self::parse($response));
    }
    
    public function auth(): static
    {
        $response = $this->http->post(self::$base_url.self::$version.'/auth/login', [
    
            'headers' => [
                'Content-type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'username' => $this->login,
                'password' => $this->password,
            ]
        ]);

        if($response->getStatusCode()) {

            dd($response->getBody()->getContents());
        }
        return $this;

    }
    
    private static function parse(Response $response)
    {
        if($response->getStatusCode() == 200) {
            
            return $response->getBody()->getContents();
        }
    }
}