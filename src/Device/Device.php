<?php
declare(strict_types=1);

namespace Tihloh\VendoGateway\Device;

final readonly class Device
{
    public function __construct(
        public string $deviceId,
        public ?string $hardwareUid,
        public string $state,
        public ?string $hardwareModel,
        public ?string $hardwareRevision,
        public ?string $firmwareVersion,
        public ?\DateTimeImmutable $lastSeenAt,
        public ?string $lastIp=null
    ) {}
}
