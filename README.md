# Vendo Gateway PHP

Framework-neutral Composer package for securely connecting ESP8266/ESP32 vending and IoT hardware to PHP applications.

```bash
composer require tihloh/vendogate-php
```

## What the package owns

- device pairing and registration
- permanent device identity and bearer-token authentication over HTTPS
- heartbeat and capability reporting
- remote configuration and device profiles
- desired/reported state
- command queue, delivery, retry, ACK/failure and expiry
- idempotent sequenced device events
- replayable host event handlers
- OTA firmware metadata and channels
- device suspend/revoke lifecycle
- database migrations and cleanup

It intentionally does **not** contain business rules such as coin value, Wi-Fi rates, voucher creation, charging prices or customer credit. Host applications interpret generic hardware events.

## Requirements

- PHP 8.2+
- PDO
- OpenSSL
- MariaDB/MySQL-compatible database

## Setup

```php
use Tihloh\VendoGateway\Database\Migrator;
use Tihloh\VendoGateway\GatewayFactory;

$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
(new Migrator($pdo))->migrate();
$gateway = GatewayFactory::pdo($pdo, $_ENV['VENDO_GATEWAY_MASTER_KEY']);
```

Use a random application master key of at least 32 characters. Changing it without re-encrypting stored device secrets will invalidate those secrets.

Device capabilities are stored as one JSON document in `vg_devices.capabilities_json`; they are not normalized into a separate capability table.

## Host API examples

```php
// Create the short setup code that the device enters during first-time setup.
$pairing=$gateway->pairings->prepare((string)$adminId,['site_id'=>12]);
$setupCode=$pairing['setup_code'];

// Send generic commands.
$gateway->commands->queue($deviceId, 'coin.enable');
$gateway->commands->queue($deviceId, 'relay.set', ['channel' => 1, 'value' => true]);

// Configure a reusable device profile.
$gateway->configs->saveProfile('wifi-vendo', 'Wi-Fi Vendo', [
    'poll' => ['active_ms' => 1000, 'idle_ms' => 15000]
], ['coin' => []], 'stable');
$gateway->configs->assignProfile($deviceId, 'wifi-vendo');

// Desired state.
$gateway->states->setDesired($deviceId, ['coin_enabled' => false]);
```

### Consume device events

```php
use Tihloh\VendoGateway\Event\DeviceEvent;
use Tihloh\VendoGateway\Event\EventHandler;

$gateway->dispatcher->listen('coin.inserted', new class implements EventHandler {
    public function handle(DeviceEvent $event): void
    {
        // Host application decides what the pulses mean financially.
    }
});
```

Events are inserted before handlers run. If a handler fails, the event remains unprocessed and can be replayed:

```php
$gateway->events->processPending();
```

## Device protocol

Default API base: `/vendo/v1`.

Recommended device routes:

```text
GET  /vendo/v1/discover
POST /vendo/v1/pairings
GET  /vendo/v1/pairings/{id}
POST /vendo/v1/pairings/{id}/ack
POST /vendo/v1/heartbeat
POST /vendo/v1/sync
POST /vendo/v1/events
GET  /vendo/v1/commands
POST /vendo/v1/commands/{id}/ack
GET  /vendo/v1/config
POST /vendo/v1/state
POST /vendo/v1/firmware/check
```

The package provides framework-neutral endpoint classes under `Tihloh\VendoGateway\Http`; your application maps them to its router.

Pairing credential delivery is retry-safe: after an administrator claims a pairing, the device may retrieve `device_id` and `device_secret` repeatedly until it has persisted them and explicitly acknowledges delivery. The server clears the encrypted one-time secret only after `POST /pairings/{id}/ack` succeeds.

Post-pairing requests use the device secret directly as a bearer token over HTTPS:

```text
X-Vendo-Device: DEV-...
Authorization: Bearer <device_secret>
```

This path has no timestamp, NTP, nonce, request sequence or HMAC requirement, so a device can communicate as soon as Wi-Fi is available. Signed-request authentication is no longer accepted.

See [`docs/protocol-v1.md`](docs/protocol-v1.md) for the wire protocol.

## Local setup/recovery

The local ESP setup password is deliberately separate from server credentials. The server package never trusts the universal initial AP password.

Expected firmware behavior:

- unconfigured: universal initial AP password
- initial setup: force a new local setup password
- Wi-Fi failure: use saved local setup password
- physical reset held 10 seconds: temporary recovery AP; reset only local setup/AP password
- full factory reset: authenticated setup UI or authenticated `system.factory_reset` command only

## Maintenance

Periodically run:

```php
$gateway->events->processPending(100);
$gateway->maintenance->cleanup();
```

## Development

```bash
composer install
composer test
```

License: MIT.


## Host-driven OTA management

Firmware releases are built from the private vendogate-firmware source repository
and published as binaries plus manifest.json in tihloh/vendogate-firmware-releases.
The host application renders the UI and authorizes its users; the gateway owns
release fetching, validation, version/target matching, configuration, and commands.

- Call firmware->deviceStatus(deviceId) on a PHP page load. It checks the release
  catalog using persisted device identity and a shared five-minute cache. No ESP
  request or command is made, and an offline device can be checked.
- Call firmware->requestCheck(deviceId) for a manual refresh. Return its status
  to the frontend immediately; this does not enqueue a firmware.check command.
- Enable Update only when update_available is strictly true. Null means unknown,
  incompatible, or incomplete metadata, and error explains why.
- Call firmware->requestUpdate(deviceId) to queue a firmware.update command with
  the exact version, target, hardware model/revision, HTTPS URL, size, and SHA-256.
  Commands expire after 24 hours and run when the enrolled ESP next polls while idle.
- Call firmware->saveSettings(deviceId, settings) to validate/persist policy and
  queue config.refresh. Settings: auto_check (default true), auto_update (default
  false), check_interval_hours (integer 1–24), and channel (stable). Enabling
  auto_update requires auto_check. These settings control the ESP's own scheduler,
  independently of page-load/manual release discovery.
- firmware->settings(deviceId) returns the normalized policy for rendering.

Release metadata is read as a single pinned snapshot. Missing targets, checksum
mismatches, unknown installed versions, and failed refreshes cannot enable Update.
Public firmware binaries stay separate from private source; hosts need no GitHub
source-repository credentials.

Command responses include both command_id and the legacy id alias. Configuration
responses retain the nested config plus legacy top-level settings. Acknowledgements
accept explicit status or the older ok boolean, including failure results.
