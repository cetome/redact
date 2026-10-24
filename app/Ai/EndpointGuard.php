<?php

namespace App\Ai;

use App\Billing\Plan;
use Closure;

/**
 * Safety of an organisation's own AI endpoint (an OpenAI-compatible server it names):
 *
 *  - cetome's SaaS (not self-hosted): https only, no user-info, and every address the host resolves
 *    to must be public: loopback, private, link-local, carrier-grade NAT, reserved and IPv4-mapped
 *    private addresses are refused (SSRF). Checked when saved and again before each call, and the
 *    call is pinned to the checked address (no DNS rebinding between the check and the call);
 *  - self-hosted: http or https, any host (Ollama on localhost or on the LAN is the usual case);
 *  - always: no redirects (a redirect could lead elsewhere), connect and answer timeouts.
 */
class EndpointGuard
{
    /** @var Closure(string): list<string> host => its IP addresses */
    private Closure $resolver;

    /** @param  (Closure(string): list<string>)|null  $resolver */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::dns(...);
    }

    /** Does this install apply the strict (SaaS) rules? */
    public function strict(): bool
    {
        return ! Plan::selfHosted();
    }

    /** `null` when the endpoint may be used, else the reason (a translation key of org_ai.errors). */
    public function refusal(string $url): ?string
    {
        return $this->check($url)[0];
    }

    /**
     * HTTP client options of a call to this endpoint: no redirects, and on the SaaS the address
     * pinned to a checked public IP. Throws AiException when the endpoint is refused now.
     *
     * @return array<string, mixed>
     */
    public function clientOptions(string $url): array
    {
        [$refusal, $ips] = $this->check($url);
        if ($refusal !== null) {
            throw AiException::unavailable();
        }
        $options = ['allow_redirects' => false];
        if ($this->strict() && $ips !== []) {
            $parts = parse_url($url);
            $host = trim((string) ($parts['host'] ?? ''), '[]');
            $port = (int) ($parts['port'] ?? 443);
            $ip = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];
            $options['curl'] = [CURLOPT_RESOLVE => [$host.':'.$port.':'.$ip]];
        }

        return $options;
    }

    /**
     * @return array{0: ?string, 1: list<string>} the refusal (or null) and the checked addresses
     */
    private function check(string $url): array
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($parts === false || $host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return ['invalid', []];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['invalid', []];
        }
        if (! $this->strict()) {
            return [null, []];
        }
        if ($scheme !== 'https') {
            return ['https_only', []];
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            return ['unresolved', []];
        }
        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                return ['private', []];
            }
        }

        return [null, $ips];
    }

    /** A public unicast address (not loopback, private, link-local, CGNAT, reserved, documentation…). */
    public static function isPublic(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) is judged as the IPv4 address it maps.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $blocked = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
            '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
            '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            '::/128', '::1/128', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
        ];
        foreach ($blocked as $range) {
            if (self::inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /** @return list<string> */
    private static function dns(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
