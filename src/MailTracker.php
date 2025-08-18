<?php

namespace jdavidbakr\MailTracker;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Event;
use jdavidbakr\MailTracker\Model\SentEmail;
use jdavidbakr\MailTracker\Events\EmailSentEvent;
use jdavidbakr\MailTracker\Model\SentEmailUrlClicked;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mime\Email;

class MailTracker implements EventSubscriberInterface
{
    protected $hash;

    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => 'onMessage',
        ];
    }

    /**
     * Handle the Symfony Mailer message event
     */
    public function onMessage(MessageEvent $event): void
    {
        $email = $event->getMessage();
        
        if (!$email instanceof Email) {
            return;
        }

        // Create the trackers
        $this->createTrackers($email);

        // Purge old records
        $this->purgeOldRecords();
    }

    /**
     * Create the tracking elements for the email
     */
    public function createTrackers($email)
    {
        foreach ($email->getTo() as $to) {
            $this->hash = Str::random(32);
            $sent_email = $this->createSentEmail($email, $to->getAddress());

            // Add tracking pixel and update links
            if ($email->getHtmlBody()) {
                $this->injectTrackingPixel($email, $sent_email);
                $this->updateTrackingLinks($email, $sent_email);
                
                // Update the content in database with the modified version
                if (config('mail-tracker.log-content', true)) {
                    $sent_email->content = $email->getHtmlBody() ?? $email->getTextBody() ?? '';
                    $sent_email->save();
                }
            }

            // Add custom header for tracking
            $email->getHeaders()->addTextHeader('X-Mailer-Hash', $this->hash);

            // Fire the event
            Event::dispatch(new EmailSentEvent($sent_email));
        }
    }

    /**
     * Create a SentEmail record
     */
    protected function createSentEmail($email, $recipient_email)
    {
        $sent_email = new SentEmail();
        $sent_email->hash = $this->hash;
        $sent_email->headers = json_encode($this->getHeaders($email));
        $sent_email->sender = $this->getSenderEmail($email);
        $sent_email->recipient = $recipient_email;
        $sent_email->subject = $email->getSubject() ?? '';
        $sent_email->domain = $this->getCurrentDomain();
        
        // Capture email content - try multiple methods
        $htmlBody = $email->getHtmlBody();
        $textBody = $email->getTextBody();
        
        // Debug logging
        \Log::info('MailTracker Content Debug', [
            'has_html_body' => !empty($htmlBody),
            'has_text_body' => !empty($textBody),
            'html_length' => strlen($htmlBody ?? ''),
            'text_length' => strlen($textBody ?? ''),
            'subject' => $email->getSubject()
        ]);
        
        $content = '';
        if ($htmlBody) {
            $content = $htmlBody;
        } elseif ($textBody) {
            $content = $textBody;
        } else {
            // Try to get content from email body
            $body = $email->getBody();
            if ($body) {
                if (method_exists($body, 'getBody')) {
                    $content = $body->getBody();
                } elseif (method_exists($body, '__toString')) {
                    $content = (string) $body;
                }
            }
        }
        
        $sent_email->content = $content;
        $sent_email->opens = 0;
        $sent_email->clicks = 0;
        $sent_email->message_id = null; // Will be set later if available
        $sent_email->meta = json_encode([]);
        $sent_email->save();

        return $sent_email;
    }

    /**
     * Get headers from email
     */
    protected function getHeaders($email)
    {
        $headers = [];
        foreach ($email->getHeaders()->all() as $name => $header) {
            if (is_array($header)) {
                $headers[$name] = array_map(function($h) {
                    return $h->getBodyAsString();
                }, $header);
            } else {
                $headers[$name] = $header->getBodyAsString();
            }
        }
        return $headers;
    }

    /**
     * Get sender name from email
     */
    protected function getSenderName($email)
    {
        $from = $email->getFrom();
        if (empty($from)) {
            return '';
        }
        $fromAddress = reset($from);
        return $fromAddress->getName() ?? '';
    }

    /**
     * Get sender email from email
     */
    protected function getSenderEmail($email)
    {
        $from = $email->getFrom();
        if (empty($from)) {
            return '';
        }
        $fromAddress = reset($from);
        return $fromAddress->getAddress();
    }

    /**
     * Inject tracking pixel into email
     */
    protected function injectTrackingPixel($email, $sent_email)
    {
        $tracking_pixel = '<img src="'.route('mailTracker_t', [$sent_email->hash]).'" width="1" height="1" border="0" style="border:0;width:1px;height:1px;" />';
        
        $body = $email->getHtmlBody();
        if ($body) {
            // Try to inject before closing body tag
            if (stripos($body, '</body>') !== false) {
                $body = str_ireplace('</body>', $tracking_pixel.'</body>', $body);
            } else {
                $body .= $tracking_pixel;
            }
            $email->html($body);
        }
    }

    /**
     * Update links in email for tracking
     */
    protected function updateTrackingLinks($email, $sent_email)
    {
        $body = $email->getHtmlBody();
        if (!$body) {
            return;
        }

        $dom = new \DOMDocument();
        $dom->loadHTML($body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $links = $dom->getElementsByTagName('a');
        $modified = false;

        for ($i = $links->length - 1; $i >= 0; $i--) {
            $link = $links->item($i);
            $href = $link->getAttribute('href');

            if ($href && filter_var($href, FILTER_VALIDATE_URL)) {
                $encoded_url = base64_encode($href);
                $tracking_url = route('mailTracker_l', ['url' => $encoded_url, 'hash' => $sent_email->hash]);
                $link->setAttribute('href', $tracking_url);
                $modified = true;

                // Store the original URL for tracking (only once per unique URL)
                SentEmailUrlClicked::firstOrCreate([
                    'sent_email_id' => $sent_email->id,
                    'url' => $href,
                ], [
                    'hash' => Str::random(32),
                    'clicks' => 0,
                    'domain' => $this->getCurrentDomain()
                ]);
            }
        }

        if ($modified) {
            $email->html($dom->saveHTML());
        }
    }

    /**
     * Purge old tracking records
     */
    public function purgeOldRecords()
    {
        if (config('mail-tracker.expire-days') > 0) {
            $expire_date = now()->subDays(config('mail-tracker.expire-days'));
            SentEmail::where('created_at', '<', $expire_date)->delete();
        }
    }

    /**
     * Legacy method compatibility
     */
    public function beforeSendPerformed($event)
    {
        // Legacy compatibility - no longer used
    }

    /**
     * Legacy method compatibility  
     */
    public function sendPerformed($event)
    {
        // Legacy compatibility - no longer used
    }

    /**
     * Get current domain from request
     */
    protected function getCurrentDomain()
    {
        if (app()->has('request')) {
            $request = app('request');
            $host = $request->getHost();
            
            // For multi-tenant setup, extract subdomain
            if (strpos($host, '.') !== false) {
                $parts = explode('.', $host);
                if (count($parts) >= 2) {
                    // Return the subdomain (e.g., 'demo' from 'demo.lp.test')
                    return $parts[0];
                }
            }
            
            return $host;
        }
        
        return null;
    }
}