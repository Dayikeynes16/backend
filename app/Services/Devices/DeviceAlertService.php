<?php

namespace App\Services\Devices;

use App\Models\Device;
use App\Models\DeviceRelease;
use App\Models\User;
use App\Notifications\DeviceBatteryLow;
use App\Notifications\DeviceOutdated;
use App\Notifications\DeviceRegistered;
use App\Notifications\DeviceSilent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Qué avisos manda un equipo y a quién.
 *
 * Cada aviso tiene una marca en la fila del equipo (`battery_alert_level`,
 * `silent_alerted_at`, `outdated_alert_version`) que se pone al avisar y se
 * limpia cuando la situación se resuelve: así no se repite. Un equipo
 * silenciado o dado de baja actualiza las marcas igual pero no envía nada; de
 * lo contrario, al reactivarlo llegaría de golpe todo lo que pasó.
 *
 * Nada de esto puede tumbar un latido: un fallo al notificar se registra y se
 * sigue.
 */
class DeviceAlertService
{
    public function afterHeartbeat(Device $device, bool $wasNew): void
    {
        if ($wasNew) {
            $this->notify($device, new DeviceRegistered($device));
        }

        $this->checkBattery($device);
    }

    private function checkBattery(Device $device): void
    {
        $level = $device->battery_level;

        if ($level === null || $device->battery_charging || $level > 20) {
            if ($device->battery_alert_level !== null) {
                $device->forceFill(['battery_alert_level' => null])->saveQuietly();
            }

            return;
        }

        $threshold = $level <= 10 ? 10 : 20;
        if ($device->battery_alert_level === $threshold || ($threshold === 20 && $device->battery_alert_level === 10)) {
            return;
        }

        $device->forceFill(['battery_alert_level' => $threshold])->saveQuietly();
        $this->notify($device, new DeviceBatteryLow($device));
    }

    /**
     * Silencio medido dentro de la ventana: una tablet apagada de noche no avisa
     * a las 08:00, avisa cuando lleva 30 min de ventana sin reportar. Una vez por
     * episodio; cualquier latido limpia la marca.
     *
     * @return bool si avisó
     */
    public function checkSilence(Device $device, Carbon $now): bool
    {
        if ($device->silent_alerted_at !== null || $device->isRetired()) {
            return false;
        }

        $tz = config('app.timezone');
        $local = $now->copy()->timezone($tz);
        $start = $local->copy()->setTimeFromTimeString(config('devices.silence_window.start'));
        $end = $local->copy()->setTimeFromTimeString(config('devices.silence_window.end'));

        if ($local->lt($start) || $local->gt($end)) {
            return false;
        }

        $since = $device->last_seen_at->copy()->timezone($tz)->max($start);
        if ($since->diffInMinutes($local) < config('devices.silence_minutes')) {
            return false;
        }

        $device->forceFill(['silent_alerted_at' => $now])->saveQuietly();
        $this->notify($device, new DeviceSilent($device));

        return true;
    }

    /** @return bool si avisó */
    public function checkOutdated(Device $device, DeviceRelease $release, Carbon $now): bool
    {
        if ($device->isRetired() || $device->app_version === null) {
            return false;
        }
        if (version_compare($device->app_version, $release->version, '>=')) {
            return false;
        }
        if ($release->known_since->diffInHours($now) < config('devices.outdated_hours')) {
            return false;
        }
        if ($device->outdated_alert_version === $release->version) {
            return false;
        }

        $device->forceFill(['outdated_alert_version' => $release->version])->saveQuietly();
        $this->notify($device, new DeviceOutdated($device, $release->version));

        return true;
    }

    /**
     * Administradores de la sucursal del equipo más los de la empresa. Nunca el
     * cajero: al mostrador no se le interrumpe por esto.
     *
     * @return Collection<int, User>
     */
    public function recipients(Device $device): Collection
    {
        return User::query()
            ->where('tenant_id', $device->tenant_id)
            ->where(function ($q) use ($device) {
                $q->where(function ($b) use ($device) {
                    $b->where('branch_id', $device->branch_id)
                        ->whereHas('roles', fn ($r) => $r->where('name', 'admin-sucursal'));
                })->orWhereHas('roles', fn ($r) => $r->where('name', 'admin-empresa'));
            })
            ->get();
    }

    public function notify(Device $device, Notification $notification): void
    {
        if ($device->isMuted() || $device->isRetired()) {
            return;
        }

        try {
            foreach ($this->recipients($device) as $user) {
                $user->notify($notification);
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar sobre un equipo', [
                'device_id' => $device->device_id,
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
