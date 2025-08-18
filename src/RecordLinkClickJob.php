<?php

namespace jdavidbakr\MailTracker;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use jdavidbakr\MailTracker\Model\SentEmail;
use jdavidbakr\MailTracker\Events\LinkClickedEvent;
use jdavidbakr\MailTracker\Model\SentEmailUrlClicked;
use jdavidbakr\MailTracker\Events\EmailDeliveredEvent;

class RecordLinkClickJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $sentEmail;
    public $url;
    public $domain;

    public function retryUntil()
    {
        return now()->addDays(5);
    }

    public function __construct($sentEmail, $url, $domain = null)
    {
        $this->sentEmail = $sentEmail;
        $this->url = $url;
        $this->domain = $domain;
    }

    public function handle()
    {
        $this->sentEmail->clicks++;
        $this->sentEmail->save();
        
        // Find or create the URL clicked record and increment clicks
        $url_clicked = SentEmailUrlClicked::where([
            'sent_email_id' => $this->sentEmail->id,
            'url' => $this->url,
        ])->first();
        
        if ($url_clicked) {
            $url_clicked->clicks++;
            $url_clicked->save();
        } else {
            // Fallback: create new record if not found
            $url_clicked = SentEmailUrlClicked::create([
                'sent_email_id' => $this->sentEmail->id,
                'url' => $this->url,
                'hash' => $this->sentEmail->hash,
                'domain' => $this->domain,
                'clicks' => 1,
            ]);
        }
        
        Event::dispatch(new LinkClickedEvent($this->sentEmail));
    }
}
