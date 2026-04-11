<?php

namespace App\Integrations;

use App\Exceptions\ConnectionFailedException;
use App\Organization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Integration
{
    public $timeout = 10.0;

    public $name = '';

    public $save_session = false;

    protected $response;

    protected $error_message;

    protected $error_codes = [0, 400, 401, 404, 405, 422, 500, 503, 504]; // 405 Method not allowed

    protected $error_code;

    protected $error_fatal;

    protected $status_code;

    protected $status_message;

    private $data_type = 'form_params';

    private $cookies = false;

    private $cookie_jar = false;

    private $client = null;

    public function __construct(
        public Organization $organization,
    ) {
        if (env('APP_ENV') === 'testing') {
            $this->testing_fakes();
        }
    }

    public function json()
    {
        $this->client()->asJson();

        return $this;
    }

    public function form()
    {
        $this->client()->asForm();

        return $this;
    }

    public function cookies(bool $cookies = true)
    {
        $this->cookies = $cookies;

        return $this;
    }

    public function storeCookies(mixed $cookies)
    {
        $this->cookie_jar = $cookies;

        return $this;
    }

    public function get($url, $data = [])
    {
        $this->send('GET', $url, $data);

        return $this->response();
    }

    public function post($url, $data)
    {
        $this->send('POST', $url, $data);

        return $this->response();
    }

    public function put(string $url, $data)
    {
        $this->send('PUT', $url, $data);

        return $this->response();
    }

    public function delete(string $url, $data = [])
    {
        $this->send('DELETE', $url, $data);

        return $this->response();
    }

    public function error()
    {
        return $this->error_message;
    }

    public function hasError(): bool
    {
        return $this->error_message ? true : false;
    }

    private function buildClient()
    {

        if (method_exists($this, 'auth')) {
            $auth = $this->auth();
            $this->client()->withBasicAuth($auth[0], $auth[1]);
        }

        if (method_exists($this, 'headers')) {
            $this->client()->withHeaders($this->headers());
        }

        // $data['http_errors'] = false;
        // $data['verify'] = env('APP_ENV') == 'production';

        return $this;

    }

    protected function statusErrorCodes()
    {
        return $this->error_codes;
    }

    public function statusCode()
    {
        return $this->status_code;
    }

    public function errorCode()
    {
        return $this->error_code;
    }

    public function response()
    {
        if ($this->hasError()) {
            return [
                'status_code' => $this->status_code,
                'status_message' => $this->status_message,
                'error' => [
                    'message' => $this->error_message,
                    'code' => $this->error_code,
                    'fatal' => $this->error_fatal,
                ],
                'content' => null,
            ];
        } else {
            return [
                'status_code' => $this->status_code,
                'status_message' => $this->status_message,
                'content' => $this->response,
            ];
        }
    }

    public function response_content()
    {
        if ($this->response() && array_key_exists('content', $this->response())) {
            return $this->response()['content'];
        }

        return null;
    }

    public function setResponse($response)
    {
        $this->response = $response;
    }

    public function setError($message, $code, $fatal = false, $quiet = false)
    {
        $url = '';
        if (url()->current() !== '') {
            $url = url()->current();
        }

        $this->error_message = $message;
        $this->error_code = $code;
        $this->error_fatal = $fatal;

        if ($this->error_message) {
            $error_message = "URL: $url \n
            Message: $message";

            if (! $quiet) {
                Log::critical($error_message, ['organization_id' => $this->organization->id]);
            }

            if ($fatal) {
                throw new \Exception($error_message);
            }
        }
    }

    public function ignoreErrorCode($code)
    {
        if ($key = array_search($code, $this->error_codes)) {
            unset($this->error_codes[$key]);
        }

        return $this;
    }

    private function hasConnection($url)
    {
        // Test for connection. If not found, retry 5 times
        if (! $passed = $this->testConnection($url)) {

            // If response 503, 504, retry
            $num = 0;
            while (((in_array($this->status_code, $this->error_codes)) && $num < 5)) {
                sleep(1);
                $passed = $this->testConnection($url);
                $num++;
            }
        }

        return $passed;
    }

    private function httpError($name, $data = null, $url = null)
    {
        $error_message = "$url $name - {$this->name}: [{$this->status_code}] {$data}";
        throw new ConnectionFailedException($error_message);
    }

    public function resetClient()
    {
        $this->client = null;

        return $this;
    }

    public function client()
    {
        $settings = collect([
            'timeout' => $this->timeout,
            'verify' => false,
        ]);
        if ($this->cookie_jar) {
            $settings = $settings->merge(['cookies' => $this->cookie_jar]);
        }
        if (! $this->client) {
            $this->client = Http::withOptions($settings->all());
        }

        return $this->client;
    }

    private function send($request_type, $url, $data)
    {
        // if (! $this->hasConnection($url)) {
        //     $this->httpError('Failed Connection Check: '.$this->error_message, $url);
        //
        //     return;
        // }

        $this->buildClient();

        $response = $this->client()->$request_type($url, $data);
        $this->storeCookies($response->cookies());

        $this->status_code = $response->getStatusCode();
        $this->status_message = $response->getReasonPhrase();

        $body = $response->getBody();
        $contents = $body->getContents();

        if (in_array($this->status_code, $this->statusErrorCodes())) {
            $this->httpError(__('messages.exception.http_error'), $contents, $url);
        }
        $this->parseResponse($contents);
        $this->error_codes = [0, 400, 401, 404, 405, 422, 500, 503, 504];

        if (! $this->save_session) {
            $this->resetClient();
        }
    }

    private function testConnection(string $url): bool
    {
        // reset last error message
        $this->error_message = null;

        $url = $this->basePath();

        $verify = env('APP_ENV') == 'production' ? 2 : 0;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
        $data = curl_exec($ch);
        $this->status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (! $data && ! in_array($this->status_code, [200, 302])) {
            $this->error_message = $this->status_code.': '.__('messages.exception.curl_error').': '.curl_error($ch)."\n";
        }
        if (in_array($this->status_code, [404, 500, 501, 503, 504])) {
            $this->httpError(__('messages.exception.test_connection_error'), $data, $url);
        }

        // Return true if there are no errors
        return is_null($this->error_message);
    }
}
