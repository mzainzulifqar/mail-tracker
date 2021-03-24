<?php

namespace jdavidbakr\MailTracker;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use jdavidbakr\MailTracker\Model\SentEmail;
use jdavidbakr\MailTracker\Events\PermanentBouncedMessageEvent;

class RecordBounceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    public function retryUntil()
    {
        return now()->addDays(5);
    }

    public function handle()
    {
        $sent_email = SentEmail::where('message_id', $this->message->mail->messageId)->first();
        if ($sent_email) {
            $meta = collect($sent_email->meta);
            $current_codes = [];
            if ($meta->has('failures')) {
                $current_codes = $meta->get('failures');
            }
            foreach ($this->message->bounce->bouncedRecipients as $failure_details) {
                $current_codes[] = $failure_details;
            }
            $meta->put('failures', $current_codes);
            $meta->put('success', false);
            $meta->put('sns_message_bounce', $this->message); // append the full message received from SNS to the 'meta' field
            $sent_email->meta = $meta;
            $sent_email->save();

            if ($this->message->bounce->bounceType == 'Permanent') {
                foreach ($this->message->bounce->bouncedRecipients as $recipient) {
                    Event::dispatch(new PermanentBouncedMessageEvent($recipient->emailAddress, $sent_email));
                }
            }
        }
    }
}
