<?php

namespace Modules\Subscriptions\Services\Entitlements;

/**
 * Effective entitlements for one tenant at one point in time.
 *
 * features: code => [enabled, value (int|null = unlimited), tier, type, source]
 */
final class ResolvedEntitlements
{
    public const SOURCE_SUBSCRIPTION = 'subscription';

    public const SOURCE_LEGACY = 'legacy';

    /**
     * @param  array<string, array{enabled: bool, value: int|null, tier: string|null, type: string, source: string}>  $features
     * @param  array<string, mixed>|null  $plan
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $source,
        public readonly ?array $plan,
        public readonly array $features,
        public readonly bool $defaultAllow,
        public readonly ?string $validUntil = null,
    ) {}

    public function allows(string $code): bool
    {
        $code = strtoupper($code);

        return isset($this->features[$code])
            ? (bool) $this->features[$code]['enabled']
            : $this->defaultAllow;
    }

    public function hasEntitlement(string $code): bool
    {
        return isset($this->features[strtoupper($code)]);
    }

    /**
     * Numeric limit for a limit/quota feature. null = unlimited, 0 = not included.
     */
    public function limit(string $code): ?int
    {
        $code = strtoupper($code);
        if (! isset($this->features[$code])) {
            return null;
        }

        $entry = $this->features[$code];
        if (! $entry['enabled']) {
            return 0;
        }

        return $entry['value'] === null ? null : (int) $entry['value'];
    }

    public function limitIsUnlimited(string $code): bool
    {
        $code = strtoupper($code);
        if (! isset($this->features[$code])) {
            return false;
        }

        $entry = $this->features[$code];

        return $entry['enabled']
            && in_array($entry['type'], ['LIMIT', 'QUOTA', 'USAGE'], true)
            && $entry['value'] === null;
    }

    public function limitSource(string $code): ?string
    {
        $code = strtoupper($code);

        return $this->features[$code]['source'] ?? null;
    }

    public function tier(string $code): ?string
    {
        $code = strtoupper($code);

        return ($this->features[$code]['enabled'] ?? false) ? ($this->features[$code]['tier'] ?? null) : null;
    }

    /**
     * @return list<string>
     */
    public function enabledCodes(): array
    {
        return array_keys(array_filter($this->features, static fn (array $f) => (bool) $f['enabled']));
    }

    public function hash(): string
    {
        $features = $this->features;
        ksort($features);

        return hash('sha256', json_encode([$this->source, $this->plan['version_id'] ?? null, $features]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'source' => $this->source,
            'plan' => $this->plan,
            'features' => $this->features,
            'default_allow' => $this->defaultAllow,
            'valid_until' => $this->validUntil,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) $data['tenant_id'],
            (string) $data['source'],
            $data['plan'] ?? null,
            (array) ($data['features'] ?? []),
            (bool) ($data['default_allow'] ?? false),
            $data['valid_until'] ?? null,
        );
    }
}
