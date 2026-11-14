<?php

namespace ErnestDefoe\MemberDesk;

use Flarum\Extension\ExtensionManager;

/**
 * Which optional extensions are switched on. Every column, filter, tab and action that leans on
 * one asks here first; a missing extension simply means that piece isn't offered.
 */
class Optional
{
    /** @var array<string, bool> */
    protected array $memo = [];

    public function __construct(protected ExtensionManager $extensions)
    {
    }

    public function on(string $id): bool
    {
        return $this->memo[$id] ??= $this->extensions->isEnabled($id);
    }

    public function gdpr(): bool
    {
        return $this->on('flarum-gdpr') && class_exists(\Flarum\Gdpr\Models\ErasureRequest::class);
    }

    public function suspend(): bool
    {
        return $this->on('flarum-suspend');
    }

    public function audit(): bool
    {
        return $this->on('flarum-audit') && class_exists(\Flarum\Audit\AuditLogger::class);
    }

    public function banIps(): bool
    {
        return $this->on('fof-ban-ips') && class_exists(\FoF\BanIPs\BannedIP::class);
    }

    public function notes(): bool
    {
        return $this->on('fof-moderator-notes');
    }

    public function warnings(): bool
    {
        return $this->on('fof-moderator-warnings');
    }

    public function masquerade(): bool
    {
        return $this->on('fof-masquerade');
    }

    public function docket(): bool
    {
        return $this->on('ernestdefoe-docket') && class_exists(\ErnestDefoe\Docket\Report::class);
    }

    public function flags(): bool
    {
        return $this->on('flarum-flags') && class_exists(\Flarum\Flags\Flag::class);
    }

    public function likes(): bool
    {
        return $this->on('flarum-likes');
    }

    /** @return array<string, bool> */
    public function all(): array
    {
        return [
            'gdpr' => $this->gdpr(),
            'suspend' => $this->suspend(),
            'audit' => $this->audit(),
            'banIps' => $this->banIps(),
            'notes' => $this->notes(),
            'warnings' => $this->warnings(),
            'masquerade' => $this->masquerade(),
            'likes' => $this->likes(),
            'docket' => $this->docket(),
            'flags' => $this->flags(),
        ];
    }
}
