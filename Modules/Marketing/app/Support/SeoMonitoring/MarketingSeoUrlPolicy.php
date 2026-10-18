<?php

namespace Modules\Marketing\Support\SeoMonitoring;

use InvalidArgumentException;

final class MarketingSeoUrlPolicy
{
    public function __construct(
        private readonly MarketingSeoMonitoringConfig $config,
    ) {
    }

    public function isAllowedMonitoringUrl(string $url): bool
    {
        try {
            $parsed = parse_url($url);
            if ($parsed === false) {
                return false;
            }
            if (($parsed['scheme'] ?? '') !== 'https') {
                return false;
            }
            if (($parsed['host'] ?? '') !== $this->config->apexHost()) {
                return false;
            }
            if (isset($parsed['user']) || isset($parsed['pass'])) {
                return false;
            }
            $port = $parsed['port'] ?? 443;
            if ((int) $port !== 443) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isAllowedLeakUrl(string $url): bool
    {
        return in_array($url, $this->config->leakUrls(), true);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertFetchAllowed(string $url, bool $leakProbe = false): void
    {
        if ($leakProbe) {
            if (! $this->isAllowedLeakUrl($url)) {
                throw new InvalidArgumentException('URL is not on the leak probe allowlist.');
            }
            $this->assertResolvedIpsSafe($this->resolveHost($url));

            return;
        }

        if (! $this->isAllowedMonitoringUrl($url)) {
            throw new InvalidArgumentException('URL is not on the monitoring allowlist.');
        }

        $this->assertResolvedIpsSafe($this->resolveHost($url));
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $url): array
    {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';
        if ($host === '') {
            throw new InvalidArgumentException('URL has no host.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records) || $records === []) {
            $fallback = @gethostbynamel($host);
            if ($fallback === false || $fallback === []) {
                throw new InvalidArgumentException('Could not resolve hostname.');
            }

            return array_values($fallback);
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            throw new InvalidArgumentException('Could not resolve hostname.');
        }

        return array_values(array_unique($ips));
    }

    /**
     * @param  list<string>  $ips
     */
    private function assertResolvedIpsSafe(array $ips): void
    {
        foreach ($ips as $ip) {
            if (! $this->isIpAllowed($ip)) {
                throw new InvalidArgumentException('Resolved IP is not permitted for monitoring.');
            }
        }
    }

    public function isIpAllowed(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ! $this->isPrivateOrReservedIpv4($ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ! $this->isPrivateOrReservedIpv6($ip);
        }

        return false;
    }

    private function isPrivateOrReservedIpv4(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }

        $ranges = [
            ['0.0.0.0', '0.255.255.255'],
            ['10.0.0.0', '10.255.255.255'],
            ['127.0.0.0', '127.255.255.255'],
            ['169.254.0.0', '169.254.255.255'],
            ['172.16.0.0', '172.31.255.255'],
            ['192.0.0.0', '192.0.0.255'],
            ['192.168.0.0', '192.168.255.255'],
            ['224.0.0.0', '239.255.255.255'],
            ['240.0.0.0', '255.255.255.255'],
        ];

        foreach ($ranges as [$start, $end]) {
            if ($long >= ip2long($start) && $long <= ip2long($end)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateOrReservedIpv6(string $ip): bool
    {
        $lower = strtolower($ip);
        if ($lower === '::1' || str_starts_with($lower, 'fe80:') || str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
            return true;
        }

        return false;
    }
}
