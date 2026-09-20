<?php
declare(strict_types=1);
namespace Tihloh\VendoGateway\Device;
use PDO;
use Tihloh\VendoGateway\Security\SecretProtector;
final class PdoDeviceRepository implements DeviceRepository
{
    public function __construct(private PDO $pdo,private SecretProtector $protector) {}
    public function find(string $deviceId): ?Device{$s=$this->pdo->prepare('SELECT * FROM vg_devices WHERE device_id=? LIMIT 1');$s->execute([$deviceId]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?$this->map($r):null;}
    public function findByHardwareUid(string $hardwareUid): ?Device{$s=$this->pdo->prepare('SELECT * FROM vg_devices WHERE hardware_uid=? ORDER BY id DESC LIMIT 1');$s->execute([$hardwareUid]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?$this->map($r):null;}
    public function secret(string $deviceId): ?string{$s=$this->pdo->prepare('SELECT device_secret_encrypted FROM vg_devices WHERE device_id=? LIMIT 1');$s->execute([$deviceId]);$v=$s->fetchColumn();return is_string($v)&&$v!==''?$this->protector->decrypt($v):null;}
    public function create(string $deviceId,?string $hardwareUid,string $deviceSecret,?string $hardwareModel,?string $hardwareRevision,?string $firmwareVersion): Device{$now=gmdate('Y-m-d H:i:s');$s=$this->pdo->prepare('INSERT INTO vg_devices (device_id,hardware_uid,device_secret_hash,device_secret_encrypted,hardware_model,hardware_revision,firmware_version,state,created_at,updated_at) VALUES (?,?,?,?,?,?,?,"active",?,?)');$s->execute([$deviceId,$hardwareUid,password_hash($deviceSecret,PASSWORD_DEFAULT),$this->protector->encrypt($deviceSecret),$hardwareModel,$hardwareRevision,$firmwareVersion,$now,$now]);return $this->find($deviceId)??throw new \RuntimeException('Device creation failed.');}
    public function heartbeat(string $deviceId,?string $firmwareVersion,?string $ip,\DateTimeImmutable $at): void{$d=$at->format('Y-m-d H:i:s');$this->pdo->prepare('UPDATE vg_devices SET firmware_version=COALESCE(?,firmware_version),last_seen_at=?,last_ip=?,updated_at=? WHERE device_id=?')->execute([$firmwareVersion,$d,$ip,$d,$deviceId]);}
    public function replaceCapabilities(string $deviceId,array $capabilities,\DateTimeImmutable $reportedAt): void{$json=json_encode($capabilities,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$this->pdo->prepare('UPDATE vg_devices SET capabilities_json=?,updated_at=? WHERE device_id=?')->execute([$json,$reportedAt->format('Y-m-d H:i:s'),$deviceId]);}
    public function capabilities(string $deviceId): array{$s=$this->pdo->prepare('SELECT capabilities_json FROM vg_devices WHERE device_id=? LIMIT 1');$s->execute([$deviceId]);$json=$s->fetchColumn();if(!is_string($json)||$json==='')return[];$value=json_decode($json,true);return is_array($value)?$value:[];}
    public function setState(string $deviceId,string $state,\DateTimeImmutable $at): void{if(!in_array($state,['active','suspended','revoked'],true))throw new \InvalidArgumentException('Invalid device state.');$this->pdo->prepare('UPDATE vg_devices SET state=?,updated_at=? WHERE device_id=?')->execute([$state,$at->format('Y-m-d H:i:s'),$deviceId]);}
    private function map(array $r): Device{return new Device($r['device_id'],$r['hardware_uid']?:null,$r['state'],$r['hardware_model']?:null,$r['hardware_revision']?:null,$r['firmware_version']?:null,$r['last_seen_at']?new \DateTimeImmutable($r['last_seen_at'],new \DateTimeZone('UTC')):null,$r['last_ip']?:null);}
}
