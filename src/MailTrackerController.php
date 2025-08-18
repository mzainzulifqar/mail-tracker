<?php

namespace jdavidbakr\MailTracker;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controller;
use jdavidbakr\MailTracker\Exceptions\BadUrlLink;

class MailTrackerController extends Controller
{
    public function getT(Request $request, $hash)
    {
        // Create a 1x1 ttransparent pixel and return it
        $pixel = sprintf('%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c', 71, 73, 70, 56, 57, 97, 1, 0, 1, 0, 128, 255, 0, 192, 192, 192, 0, 0, 0, 33, 249, 4, 1, 0, 0, 0, 0, 44, 0, 0, 0, 0, 1, 0, 1, 0, 0, 2, 2, 68, 1, 0, 59);
        $response = response($pixel, 200);
        $response->header('Content-type', 'image/gif');
        $response->header('Content-Length', 42);
        $response->header('Cache-Control', 'private, no-cache, no-cache=Set-Cookie, proxy-revalidate');
        $response->header('Expires', 'Wed, 11 Jan 2000 12:59:00 GMT');
        $response->header('Last-Modified', 'Wed, 11 Jan 2006 12:59:00 GMT');
        $response->header('Pragma', 'no-cache');

        $tracker = Model\SentEmail::where('hash', $hash)->first();

        if ($tracker) {
            // Check if this is a genuine user view or automated email client prefetch
            $shouldRecord = true;
            if (config('mail-tracker.filter-email-client-clicks', true)) {
                $shouldRecord = $this->isGenuineUserClick($request);
            }
            
            if ($shouldRecord) {
                RecordTrackingJob::dispatch($tracker);
            }
        }

        return $response;
    }

    public function getL(Request $request,$url, $hash)
    {   
        $url = base64_decode(str_replace('$', '/', $url));
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new BadUrlLink('Mail hash: ' . $hash);
        }
        return $this->linkClicked($request, $url, $hash);
    }

    public function getN(Request $request)
    {
        $url = $request->l;
        $hash = $request->h;
        return $this->linkClicked($request, $url, $hash);
    }

    protected function linkClicked(Request $request, $url, $hash)
    {
        if ($url == '' || $hash == '') {
            return;
        }
        
        $tracker = Model\SentEmail::where('hash', $hash)
            ->first();
   
        if ($tracker) {
            // Check if this is a genuine user click or automated email client prefetch
            $shouldRecord = true;
            if (config('mail-tracker.filter-email-client-clicks', true)) {
                $shouldRecord = $this->isGenuineUserClick($request);
            }
            
            if ($shouldRecord) {
                $domain = $this->getCurrentDomain();
                RecordLinkClickJob::dispatch($tracker, $url, $domain);
            }
            return redirect($url);
        }

        throw new BadUrlLink('Mail hash: ' . $hash);
    }

    /**
     * Determine if this is a genuine user click or automated email client prefetch
     */
    protected function isGenuineUserClick($request)
    {
        $userAgent = $request->header('User-Agent', '');
        $headers = $request->headers;

        // Common email client user agents that prefetch/preview links
        $emailClientPatterns = [
            // Apple Mail link preview
            '/AppleWebKit.*Mobile.*Safari.*(?:AppleMail|MailKit)/i',
            '/AppleMail/i',
            '/MailKit/i',
            '/iOS.*Mail/i',
            
            // Gmail image proxy and link scanning
            '/GoogleImageProxy/i',
            '/Gmail.*Image.*Proxy/i',
            '/Google.*SafeBrowsing/i',
            '/Google.*LinkScanner/i',
            
            // Outlook link preview
            '/Microsoft.*Office.*Outlook/i',
            '/Outlook.*LinkPreview/i',
            '/Microsoft.*SafeLinks/i',
            '/SkypeUriPreview/i',
            
            // Yahoo Mail
            '/Yahoo.*Mail/i',
            '/YahooMailProxy/i',
            
            // Other email security/preview services
            '/Mimecast/i',
            '/Proofpoint/i',
            '/Barracuda/i',
            '/MessageLabs/i',
            '/TrendMicro/i',
            '/Symantec.*Email/i',
            
            // Bot patterns
            '/bot/i',
            '/crawler/i',
            '/spider/i',
            '/preview/i',
            '/prefetch/i',
            '/scanner/i',
        ];

        // Check user agent against email client patterns
        foreach ($emailClientPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                \Log::info('MailTracker: Email client prefetch detected', [
                    'user_agent' => $userAgent,
                    'pattern' => $pattern,
                    'ip' => $request->ip()
                ]);
                return false; // It's an email client prefetch
            }
        }

        // Check for suspicious headers that indicate automated requests
        $suspiciousHeaders = [
            'X-Purpose' => ['preview', 'prefetch'],
            'X-Moz' => ['prefetch'],
            'Purpose' => ['preview', 'prefetch'],
            'Sec-Purpose' => ['prefetch'],
        ];

        foreach ($suspiciousHeaders as $headerName => $suspiciousValues) {
            $headerValue = $headers->get($headerName, '');
            foreach ($suspiciousValues as $suspiciousValue) {
                if (stripos($headerValue, $suspiciousValue) !== false) {
                    \Log::info('MailTracker: Suspicious header detected', [
                        'header' => $headerName,
                        'value' => $headerValue,
                        'ip' => $request->ip()
                    ]);
                    return false; // It's a prefetch request
                }
            }
        }

        // Additional checks for genuine browsers
        $genuineBrowserPatterns = [
            '/Chrome\/\d+\.\d+/i',
            '/Firefox\/\d+\.\d+/i',
            '/Safari\/\d+\.\d+.*Version\/\d+\.\d+/i',
            '/Edge\/\d+\.\d+/i',
            '/Opera\/\d+\.\d+/i',
        ];

        $hasGenuineBrowser = false;
        foreach ($genuineBrowserPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                $hasGenuineBrowser = true;
                break;
            }
        }

        // If we can't identify a genuine browser, be cautious
        if (!$hasGenuineBrowser && !empty($userAgent)) {
            \Log::info('MailTracker: Unidentified user agent', [
                'user_agent' => $userAgent,
                'ip' => $request->ip()
            ]);
            return false; // Unknown/suspicious user agent
        }

        // Additional check: if no referer and suspicious timing patterns
        $referer = $request->header('Referer', '');
        if (empty($referer) && $this->isPotentialBotRequest($request)) {
            \Log::info('MailTracker: Potential bot request detected', [
                'user_agent' => $userAgent,
                'ip' => $request->ip(),
                'no_referer' => true
            ]);
            return false;
        }

        // Passed all checks - likely a genuine user click
        return true;
    }

    /**
     * Check for potential bot request patterns
     */
    protected function isPotentialBotRequest($request)
    {
        $userAgent = $request->header('User-Agent', '');
        
        // Empty or very short user agent is suspicious
        if (empty($userAgent) || strlen($userAgent) < 10) {
            return true;
        }

        // Check for common bot indicators
        $botIndicators = [
            'headless',
            'phantom',
            'selenium',
            'webdriver',
            'automation',
        ];

        foreach ($botIndicators as $indicator) {
            if (stripos($userAgent, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Legacy method - keep for backward compatibility
     */
    protected function agentChecker($request) 
    {
        return $this->isGenuineUserClick($request);
    }

    /**
     * Get current domain from request
     */
    protected function getCurrentDomain()
    {
        $request = request();
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
}
