<?php

namespace App\Notifications;

use App\Models\ConjunctionAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConjunctionAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ConjunctionAlert $alert) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $alert     = $this->alert;
        $tca       = $alert->tca->format('D, d M Y H:i') . ' UTC';
        $hoursAway = round($alert->hoursUntilTca(), 1);
        $risk      = $alert->riskLevel();
        $trackerUrl = rtrim(config('app.url'), '/') . '/#tracker/' . $alert->primary_norad_id;

        return (new MailMessage())
            ->subject("[{$risk}] Conjunction alert — {$alert->primary_name}")
            ->greeting('Conjunction Warning')
            ->line("**{$alert->primary_name}** (NORAD {$alert->primary_norad_id}) has a predicted close approach with debris object **{$alert->secondary_name}**.")
            ->line('')
            ->line("| Field | Value |")
            ->line("|-------|-------|")
            ->line("| Time of Closest Approach | {$tca} |")
            ->line("| Time until TCA | {$hoursAway} hours |")
            ->line("| Predicted miss distance | {$alert->miss_distance_km} km |")
            ->line("| Risk score | {$alert->risk_score} / 100 ({$risk}) |")
            ->action('Track in SatView', $trackerUrl)
            ->line('This alert was generated automatically. Miss distances are estimates based on current TLE data and simplified propagation. Always verify with authoritative sources before taking action.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'conjunction_alert_id'  => $this->alert->id,
            'primary_norad_id'      => $this->alert->primary_norad_id,
            'primary_name'          => $this->alert->primary_name,
            'secondary_norad_id'    => $this->alert->secondary_norad_id,
            'secondary_name'        => $this->alert->secondary_name,
            'tca'                   => $this->alert->tca->toIso8601String(),
            'miss_distance_km'      => $this->alert->miss_distance_km,
            'risk_score'            => $this->alert->risk_score,
            'risk_level'            => $this->alert->riskLevel(),
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
