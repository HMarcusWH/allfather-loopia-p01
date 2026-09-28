<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;

/** Fixed endpoint, explicit activation, one bounded POST. No redirects, retries, tools or logging. */
final class OpenAIResponsesTransport implements ModelTransport
{
    public const ENDPOINT = ModelPolicy::ENDPOINT;
    public const MAX_RESPONSE_BYTES = ModelPolicy::MAX_RESPONSE_BYTES;
    public function __construct(private readonly bool $activated = false) {}
    public function isLive(): bool { return true; }
    public function send(PreparedModelRequest $request, string $credential): ModelResponse
    {
        if (!$this->activated) return new ModelResponse(0,'',null,'MODEL_TRANSPORT_INACTIVE');
        if (($request->provenance()['activation_status'] ?? '') !== 'ACTIVE') return new ModelResponse(0,'',null,'MODEL_PROMPT_INACTIVE');
        if (!function_exists('curl_init')) return new ModelResponse(0,'',null,'MODEL_TRANSPORT_UNAVAILABLE');
        if ($credential === '' || strlen($credential) > 1024 || preg_match('/[\x00-\x20\x7f]/', $credential)) return new ModelResponse(0,'',null,'MODEL_CREDENTIAL_INVALID');
        $body=''; $requestId=null; $overflow=false; $started=microtime(true);
        $curl=curl_init(self::ENDPOINT);
        curl_setopt_array($curl,[
            CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$request->jsonBody(),
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$credential,'Content-Type: application/json','Accept: application/json'],
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_MAXREDIRS=>0, CURLOPT_CONNECTTIMEOUT_MS=>ModelPolicy::CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS=>ModelPolicy::TOTAL_TIMEOUT_MS, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_PROXY=>'',
            CURLOPT_WRITEFUNCTION=>static function ($handle,string $bytes) use (&$body,&$overflow):int {
                if (strlen($body)+strlen($bytes)>self::MAX_RESPONSE_BYTES) { $overflow=true; return 0; }
                $body.=$bytes;return strlen($bytes);
            },
            CURLOPT_HEADERFUNCTION=>static function ($handle,string $line) use (&$requestId):int {
                if (preg_match('/^x-request-id:\s*([A-Za-z0-9_.:-]{1,128})\s*$/iD',trim($line),$m)) $requestId=$m[1];
                return strlen($line);
            },
        ]);
        $ok=curl_exec($curl); $errno=curl_errno($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        $failure=$overflow?'MODEL_RESPONSE_TOO_LARGE':($ok===false?($errno===CURLE_OPERATION_TIMEDOUT?'MODEL_TIMEOUT':'MODEL_NETWORK_OUTCOME_UNKNOWN'):null);
        return new ModelResponse($status,$failure===null?$body:'',$requestId,$failure,(int)round((microtime(true)-$started)*1000));
    }
}
