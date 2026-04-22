<?php

declare(strict_types=1);

namespace Henryavila\Codeguard\Install;

enum WarningCode: string
{
    case PhpVersionTooLow = 'php-version-too-low';
    case ComposerLockStale = 'composer-lock-stale';
    case CaptainhookPluginBlocked = 'captainhook-plugin-blocked';
    case CaptainhookBinaryMissing = 'captainhook-binary-missing';
    case CaptainhookInstallFailed = 'captainhook-install-failed';
    case NodeMissingForFullPreset = 'node-missing-for-full-preset';
    case StubPublishFailed = 'stub-publish-failed';
    case DeptracWriteFailed = 'deptrac-write-failed';
    case LegacyStubPresent = 'legacy-stub-present';
}
