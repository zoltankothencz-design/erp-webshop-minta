<?php
declare(strict_types=1);

namespace App\Integration;

/**
 * Minden külső hívás ezen megy át. Amit ez egy helyen megold, azt nem kell minden integrációban újraírni:
 *  - időkorlát (egy lassú külső rendszer nem akaszthatja meg a miénket)
 *  - újrapróbálkozás növekvő várakozással, de csak átmeneti hibánál (429, 5xx, hálózati hiba)
 *  - végleges hiba (pl. 400, 401, 404) azonnal kivételt dob, újrapróbálás értelmetlen
 *  - titkok (kulcsok, tokenek) soha nem kerülnek a hibaüzenetbe
 */
final class HttpClient
{
    /** @var callable|null  fn(string $method, string $url, array $headers, ?string $body, int $timeout): array{status:int, body:string} */
    private $transport;
    /** @var callable */
    private $sleeper;

    public function __construct(
        private int $timeout = 10,
        private int $maxAttempts = 3,
        ?callable $transport = null,
        ?callable $sleeper = null,
    ) {
        $this->transport = $transport;
        $this->sleeper = $sleeper ?? static fn(int $ms) => usleep($ms * 1000);
    }

    /**
     * @param array{headers?:array<string,string>, json?:mixed, form?:array<string,string>, query?:array<string,string|int>} $options
     * @return array{status:int, json:mixed, body:string, attempts:int}
     */
    public function request(string $method, string $url, array $options = []): array
    {
        if (!empty($options['query'])) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
        }
        $headers = $options['headers'] ?? [];
        $body = null;
        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $headers['Content-Type'] = 'application/json';
        } elseif (isset($options['form'])) {
            $body = http_build_query($options['form']);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }
        $headers['Accept'] = $headers['Accept'] ?? 'application/json';

        $lastError = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $res = ($this->transport ?? [$this, 'curlTransport'])($method, $url, $headers, $body, $this->timeout);
            } catch (\RuntimeException $e) {   // hálózati hiba (nincs kapcsolat, időtúllépés)
                $lastError = new IntegrationException('Hálózati hiba: ' . $e->getMessage(), 0, true);
                $this->backoff($attempt);
                continue;
            }
            $status = (int) $res['status'];
            if ($status >= 200 && $status < 300) {
                $json = json_decode($res['body'], true);
                return ['status' => $status, 'json' => $json, 'body' => $res['body'], 'attempts' => $attempt];
            }
            $retryable = $status === 429 || $status >= 500;
            $lastError = new IntegrationException(
                sprintf('%s %s -> HTTP %d: %s', $method, $this->safeUrl($url), $status, mb_substr(trim($res['body']), 0, 200)),
                $status,
                $retryable
            );
            if (!$retryable) {
                throw $lastError;
            }
            $this->backoff($attempt);
        }
        throw new IntegrationException($lastError->getMessage() . " (feladva {$this->maxAttempts} próbálkozás után)", $lastError->status, true);
    }

    /** 200 ms, 400 ms, 800 ms ... az utolsó próbálkozás után nincs várakozás. */
    private function backoff(int $attempt): void
    {
        if ($attempt < $this->maxAttempts) {
            ($this->sleeper)(200 * (2 ** ($attempt - 1)));
        }
    }

    /** A query-stringből kivesszük, ami titok lehet. */
    private function safeUrl(string $url): string
    {
        return preg_replace('/([?&](?:key|token|api_key|access_token)=)[^&]*/i', '$1***', $url) ?? $url;
    }

    private function curlTransport(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($err !== '' ? $err : 'ismeretlen curl hiba');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $out];
    }
}
