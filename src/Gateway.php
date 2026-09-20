<?php
declare(strict_types=1);
namespace Tihloh\VendoGateway;
use Tihloh\VendoGateway\Command\CommandService;
use Tihloh\VendoGateway\Config\ConfigService;
use Tihloh\VendoGateway\Device\DeviceService;
use Tihloh\VendoGateway\Event\EventDispatcher;
use Tihloh\VendoGateway\Event\EventService;
use Tihloh\VendoGateway\Firmware\FirmwareService;
use Tihloh\VendoGateway\Heartbeat\HeartbeatService;
use Tihloh\VendoGateway\Maintenance\MaintenanceService;
use Tihloh\VendoGateway\Pairing\PairingService;
use Tihloh\VendoGateway\State\StateService;
final readonly class Gateway
{
    public function __construct(
        public PairingService $pairings,
        public DeviceService $devices,
        public HeartbeatService $heartbeats,
        public ConfigService $configs,
        public CommandService $commands,
        public EventService $events,
        public EventDispatcher $dispatcher,
        public StateService $states,
        public FirmwareService $firmware,
        public MaintenanceService $maintenance
    ) {}

    public function deviceInfo(string $deviceId): array
    {
        $device=$this->devices->get($deviceId)??throw new \InvalidArgumentException('Device not found.');
        $resolved=$this->configs->resolve($deviceId);
        $firmware=null;
        try{$firmware=$this->firmware->deviceStatus($deviceId);}
        catch(\Throwable $e){$firmware=['error'=>$e->getMessage()];}
        return[
            'device'=>[
                'device_id'=>$device->deviceId,
                'hardware_uid'=>$device->hardwareUid,
                'state'=>$device->state,
                'hardware_model'=>$device->hardwareModel,
                'hardware_revision'=>$device->hardwareRevision,
                'firmware_version'=>$device->firmwareVersion,
                'last_seen_at'=>$device->lastSeenAt?->format(DATE_ATOM),
                'last_ip'=>$device->lastIp,
            ],
            'capabilities'=>$this->devices->capabilities($deviceId),
            'reported_state'=>$this->configs->reportedState($deviceId),
            'desired_state'=>$this->states->desired($deviceId),
            'config'=>[
                'version'=>$resolved['version'],
                'revision'=>$resolved['revision'],
                'profile'=>$resolved['profile'],
                'profile_version'=>$resolved['profile_version'],
                'firmware_channel'=>$resolved['firmware_channel'],
                'values'=>$resolved['config'],
            ],
            'firmware'=>$firmware,
        ];
    }
}
