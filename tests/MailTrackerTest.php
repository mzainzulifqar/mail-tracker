<?php

namespace jdavidbakr\MailTracker\Tests;

use Mockery;
use Exception;
use Faker\Factory;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Config;
use jdavidbakr\MailTracker\MailTracker;
use Illuminate\Mail\MailServiceProvider;
use jdavidbakr\MailTracker\Model\SentEmail;
use jdavidbakr\MailTracker\RecordBounceJob;
use Orchestra\Testbench\Exceptions\Handler;
use jdavidbakr\MailTracker\RecordDeliveryJob;
use jdavidbakr\MailTracker\RecordTrackingJob;
use jdavidbakr\MailTracker\RecordComplaintJob;
use jdavidbakr\MailTracker\RecordLinkClickJob;
use Illuminate\Contracts\Debug\ExceptionHandler;
use jdavidbakr\MailTracker\Events\EmailSentEvent;
use jdavidbakr\MailTracker\Events\ViewEmailEvent;
use jdavidbakr\MailTracker\Exceptions\BadUrlLink;
use jdavidbakr\MailTracker\Events\LinkClickedEvent;
use Aws\Sns\MessageValidator as SNSMessageValidator;
use jdavidbakr\MailTracker\Events\EmailDeliveredEvent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use jdavidbakr\MailTracker\Events\ComplaintMessageEvent;
use jdavidbakr\MailTracker\Events\PermanentBouncedMessageEvent;

class IgnoreExceptions extends Handler
{
    public function __construct()
    {
    }
    public function report(Exception $e)
    {
    }
    public function render($request, Exception $e)
    {
        throw $e;
    }
}

class MailTrackerTest extends SetUpTest
{
    protected function disableExceptionHandling()
    {
        $this->app->instance(ExceptionHandler::class, new IgnoreExceptions);
    }

    public function testSendMessage()
    {
        // Create an old email to purge
        Config::set('mail-tracker.expire-days', 1);
        $old_email = \jdavidbakr\MailTracker\Model\SentEmail::create([
                'hash' => Str::random(32),
            ]);
        $old_url = \jdavidbakr\MailTracker\Model\SentEmailUrlClicked::create([
                'sent_email_id' => $old_email->id,
                'hash' => Str::random(32),
            ]);
        // Go into the future to make sure that the old email gets removed
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::now()->addWeek());

        Event::fake();

        $faker = Factory::create();
        $email = $faker->email;
        $subject = $faker->sentence;
        $name = $faker->firstName . ' ' .$faker->lastName;
        \View::addLocation(__DIR__);
        \Mail::send('email.test', [], function ($message) use ($email, $subject, $name) {
            $message->from('from@johndoe.com', 'From Name');
            $message->sender('sender@johndoe.com', 'Sender Name');

            $message->to($email, $name);

            $message->cc('cc@johndoe.com', 'CC Name');
            $message->bcc('bcc@johndoe.com', 'BCC Name');

            $message->replyTo('reply-to@johndoe.com', 'Reply-To Name');

            $message->subject($subject);

            $message->priority(3);
        });

        Event::assertDispatched(EmailSentEvent::class);

        $this->assertDatabaseHas('sent_emails', [
                'recipient' => $name.' <'.$email.'>',
                'subject' => $subject,
                'sender' => 'From Name <from@johndoe.com>',
                'recipient' => "{$name} <{$email}>",
            ]);
        $this->assertNull($old_email->fresh());
        $this->assertNull($old_url->fresh());
    }

    public function testSendMessageWithMailRaw()
    {
        $faker = Factory::create();
        $email = $faker->email;
        $name = $faker->firstName . ' ' .$faker->lastName;
        $content = 'Text to e-mail';
        View::addLocation(__DIR__);

        Mail::raw($content, function ($message) use ($email, $name) {
            $message->from('from@johndoe.com', 'From Name');

            $message->to($email, $name);
        });

        $this->assertDatabaseHas('sent_emails', [
            'recipient' => $name.' <'.$email.'>',
            'sender' => 'From Name <from@johndoe.com>',
            'recipient' => "{$name} <{$email}>",
            'content' => $content
        ]);
    }

    /**
     * @test
     */
    public function it_doesnt_track_if_told_not_to()
    {
        Event::fake();

        $faker = Factory::create();
        $email = $faker->email;
        $subject = $faker->sentence;
        $name = $faker->firstName . ' ' .$faker->lastName;
        \View::addLocation(__DIR__);
        \Mail::send('email.test', [], function ($message) use ($email, $subject, $name) {
            $message->from('from@johndoe.com', 'From Name');
            $message->sender('sender@johndoe.com', 'Sender Name');

            $message->to($email, $name);

            $message->cc('cc@johndoe.com', 'CC Name');
            $message->bcc('bcc@johndoe.com', 'BCC Name');

            $message->replyTo('reply-to@johndoe.com', 'Reply-To Name');

            $message->subject($subject);

            $message->priority(3);

            $message->getHeaders()->addTextHeader('X-No-Track', Str::random(10));
        });

        $this->assertDatabaseMissing('sent_emails', [
                'recipient' => $name.' <'.$email.'>',
                'subject' => $subject,
                'sender' => 'From Name <from@johndoe.com>',
                'recipient' => "{$name} <{$email}>",
            ]);
    }

    /**
     * @test
     */
    public function no_track_doesnt_crash_ses()
    {
        Event::fake();
        Config::set('mail.driver', 'ses');

        $faker = Factory::create();
        $email = $faker->email;
        $subject = $faker->sentence;
        $name = $faker->firstName . ' ' .$faker->lastName;
        \View::addLocation(__DIR__);
        \Mail::send('email.test', [], function ($message) use ($email, $subject, $name) {
            $message->from('from@johndoe.com', 'From Name');
            $message->sender('sender@johndoe.com', 'Sender Name');

            $message->to($email, $name);

            $message->cc('cc@johndoe.com', 'CC Name');
            $message->bcc('bcc@johndoe.com', 'BCC Name');

            $message->replyTo('reply-to@johndoe.com', 'Reply-To Name');

            $message->subject($subject);

            $message->priority(3);

            $message->getHeaders()->addTextHeader('X-No-Track', Str::random(10));
        });

        $this->assertDatabaseMissing('sent_emails', [
                'recipient' => $name.' <'.$email.'>',
                'subject' => $subject,
                'sender' => 'From Name <from@johndoe.com>',
                'recipient' => "{$name} <{$email}>",
            ]);
    }

    /**
     * @test
     */
    public function testPing()
    {
        $this->disableExceptionHandling();
        Bus::fake();
        $track = \jdavidbakr\MailTracker\Model\SentEmail::create([
                'hash' => Str::random(32),
            ]);
        $pings = $track->opens;
        $pings++;
        $url = route('mailTracker_t', [$track->hash]);

        $response = $this->get($url);

        $response->assertSuccessful();
        Bus::assertDispatched(RecordTrackingJob::class, function ($e) use ($track) {
            return $e->sentEmail->id == $track->id;
        });
    }

    public function testLegacyLink()
    {
        Bus::fake();
        $track = \jdavidbakr\MailTracker\Model\SentEmail::create([
                'hash' => Str::random(32),
            ]);
        $clicks = $track->clicks;
        $clicks++;
        $redirect = 'http://'.Str::random(15).'.com/'.Str::random(10).'/'.Str::random(10).'/'.rand(0, 100).'/'.rand(0, 100).'?page='.rand(0, 100).'&x='.Str::random(32);
        $url = route('mailTracker_l', [
                MailTracker::hash_url($redirect), // Replace slash with dollar sign
                $track->hash
            ]);
        $response = $this->get($url);

        $response->assertRedirect($redirect);
        Bus::assertDispatched(RecordLinkClickJob::class, function ($job) use ($track, $redirect) {
            return $job->sentEmail->id == $track->id &&
                $job->url == $redirect;
        });
    }

    public function testLink()
    {
        Bus::fake();
        $track = \jdavidbakr\MailTracker\Model\SentEmail::create([
                'hash' => Str::random(32),
            ]);
        $redirect = 'http://'.Str::random(15).'.com/'.Str::random(10).'/'.Str::random(10).'/'.rand(0, 100).'/'.rand(0, 100).'?page='.rand(0, 100).'&x='.Str::random(32);
        $url = route('mailTracker_n', [
                'l' => $redirect,
                'h' => $track->hash
            ]);
            
        $response = $this->get($url);

        $response->assertRedirect($redirect);
        Bus::assertDispatched(RecordLinkClickJob::class, function ($job) use ($track, $redirect) {
            return $job->sentEmail->id == $track->id &&
                $job->url == $redirect;
        });
    }

    /**
     * @test
     */
    public function it_throws_exception_on_invalid_link()
    {
        $this->disableExceptionHandling();
        $this->expectException(BadUrlLink::class);
        $track = \jdavidbakr\MailTracker\Model\SentEmail::create([
                'hash' => Str::random(32),
            ]);

        Event::fake();

        $clicks = $track->clicks;
        $clicks++;

        $redirect = 'http://'.Str::random(15).'.com/'.Str::random(10).'/'.Str::random(10).'/'.rand(0, 100).'/'.rand(0, 100).'?page='.rand(0, 100).'&x='.Str::random(32);

        // Do it with an invalid hash
        $url = route('mailTracker_n', [
                'l' => $redirect,
                'h' => 'bad-hash'
            ]);
        $response = $this->get($url);

        $response->assertStatus(500);
    }

    /**
     * @test
     */
    public function random_string_in_link_does_not_crash(Type $var = null)
    {
        $this->disableExceptionHandling();
        $this->expectException(BadUrlLink::class);
        $url = route('mailTracker_l', [
            Str::random(32),
            'the-mail-hash',
        ]);

        $this->get($url);
    }

    /**
     * @test
     *
     * Note that to complete this test, you must have aws credentials as well as a valid
     * from address in the mail config.
     */
    public function it_retrieves_the_mesage_id_from_ses()
    {
        Config::set('mail.driver', 'ses');
        $headers = Mockery::mock();
        $headers->shouldReceive('get')
            ->with('X-No-Track')
            ->once()
            ->andReturn(null);
        $this->mailer_hash = '';
        $headers->shouldReceive('addTextHeader')
            ->once()
            ->andReturnUsing(function ($key, $value) {
                $this->mailer_hash = $value;
            });
        $mailer_hash_header = Mockery::mock();
        $mailer_hash_header->shouldReceive('getFieldBody')
            ->once()
            ->andReturnUsing(function () {
                return $this->mailer_hash;
            });
        $headers->shouldReceive('get')
            ->with('X-Mailer-Hash')
            ->once()
            ->andReturn($mailer_hash_header);
        $headers->shouldReceive('get')
            ->with('X-SES-Message-ID')
            ->once()
            ->andReturn(Mockery::mock([
                'getFieldBody' => 'aws-mailer-hash'
            ]));
        $headers->shouldReceive('toString')
            ->once();
        $event = Mockery::mock(\Swift_Events_SendEvent::class, [
            'getMessage' => Mockery::mock([
                'getTo' => [
                    'destination@example.com' => 'Destination Person'
                ],
                'getFrom' => [
                    'from@example.com' => 'From Name'
                ],
                'getHeaders' => $headers,
                'getSubject' => 'The message subject',
                'getBody' => 'The body',
                'getContentType' => 'text/html',
                'setBody' => null,
                'getChildren' => [],
                'getId' => 'message-id',
            ]),
        ]);
        $tracker = new MailTracker();

        $tracker->beforeSendPerformed($event);
        $tracker->sendPerformed($event);

        $sent_email = \jdavidbakr\MailTracker\Model\SentEmail::orderBy('id', 'desc')->first();
        $this->assertEquals('aws-mailer-hash', $sent_email->message_id);
    }

    /**
     * SNS Tests
     */

    /**
     * @test
     */
    public function it_confirms_a_subscription()
    {
        $url = action('\jdavidbakr\MailTracker\SNSController@callback');
        $response = $this->post($url, [
                'message' => json_encode([
                        // Required
                        'Message' => 'test subscription message',
                        'MessageId' => Str::random(10),
                        'Timestamp' => \Carbon\Carbon::now()->timestamp,
                        'TopicArn' => Str::random(10),
                        'Type' => 'SubscriptionConfirmation',
                        'Signature' => Str::random(32),
                        'SigningCertURL' => Str::random(32),
                        'SignatureVersion' => 1,
                        // Request-specific
                        'SubscribeURL' => 'http://google.com',
                        'Token' => Str::random(10),
                    ])
            ]);
        $response->assertSee('subscription confirmed');
    }

    /**
     * @test
     */
    public function it_processes_with_registered_topic()
    {
        $topic = Str::random(32);
        Config::set('mail-tracker.sns-topic', $topic);
        $url = action('\jdavidbakr\MailTracker\SNSController@callback');
        $response = $this->post($url, [
                'message' => json_encode([
                        // Required
                        'Message' => 'test subscription message',
                        'MessageId' => Str::random(10),
                        'Timestamp' => \Carbon\Carbon::now()->timestamp,
                        'TopicArn' => $topic,
                        'Type' => 'SubscriptionConfirmation',
                        'Signature' => Str::random(32),
                        'SigningCertURL' => Str::random(32),
                        'SignatureVersion' => 1,
                        // Request-specific
                        'SubscribeURL' => 'http://google.com',
                        'Token' => Str::random(10),
                    ])
            ]);
        $response->assertSee('subscription confirmed');
    }

    /**
     * @test
     */
    public function it_ignores_invalid_topic()
    {
        $topic = Str::random(32);
        Config::set('mail-tracker.sns-topic', $topic);
        $url = action('\jdavidbakr\MailTracker\SNSController@callback');
        $response = $this->post($url, [
                'message' => json_encode([
                        // Required
                        'Message' => 'test subscription message',
                        'MessageId' => Str::random(10),
                        'Timestamp' => \Carbon\Carbon::now()->timestamp,
                        'TopicArn' => Str::random(32),
                        'Type' => 'SubscriptionConfirmation',
                        'Signature' => Str::random(32),
                        'SigningCertURL' => Str::random(32),
                        'SignatureVersion' => 1,
                        // Request-specific
                        'SubscribeURL' => 'http://google.com',
                        'Token' => Str::random(10),
                    ])
            ]);
        $response->assertSee('invalid topic ARN');
    }

    /**
     * @test
     */
    public function it_processes_a_delivery()
    {
        $this->disableExceptionHandling();
        Bus::fake();
        $message = [
            'notificationType' => 'Delivery',
        ];

        $response = $this->post(action('\jdavidbakr\MailTracker\SNSController@callback'), [
                'message' => json_encode([
                    'Message' => json_encode($message),
                    'MessageId' => Str::uuid(),
                    'Timestamp' => Carbon::now()->timestamp,
                    'TopicArn' => Str::uuid(),
                    'Type' => 'Notification',
                    'Signature' => Str::uuid(),
                    'SigningCertURL' => Str::uuid(),
                    'SignatureVersion' => Str::uuid(),
                ])
            ]);

        $response->assertSee('notification processed');
        Bus::assertDispatched(RecordDeliveryJob::class, function ($job) use ($message) {
            return $job->message == (object)$message;
        });
    }

    /**
     * @test
     */
    public function it_processes_a_bounce()
    {
        Bus::fake();
        $message = [
            'notificationType' => 'Bounce',
        ];

        $response = $this->post(action('\jdavidbakr\MailTracker\SNSController@callback'), [
                'message' => json_encode([
                    'Message' => json_encode($message),
                    'MessageId' => Str::uuid(),
                    'Timestamp' => Carbon::now()->timestamp,
                    'TopicArn' => Str::uuid(),
                    'Type' => 'Notification',
                    'Signature' => Str::uuid(),
                    'SigningCertURL' => Str::uuid(),
                    'SignatureVersion' => Str::uuid(),
                ])
            ]);

        $response->assertSee('notification processed');
        Bus::assertDispatched(RecordBounceJob::class, function ($job) use ($message) {
            return $job->message == (object)$message;
        });
    }

    /**
     * @test
     */
    public function it_processes_a_complaint()
    {
        $this->disableExceptionHandling();
        Bus::fake();
        $message = [
            'notificationType' => 'Complaint',
        ];

        $response = $this->post(action('\jdavidbakr\MailTracker\SNSController@callback'), [
                'message' => json_encode([
                    'Message' => json_encode($message),
                    'MessageId' => Str::uuid(),
                    'Timestamp' => Carbon::now()->timestamp,
                    'TopicArn' => Str::uuid(),
                    'Type' => 'Notification',
                    'Signature' => Str::uuid(),
                    'SigningCertURL' => Str::uuid(),
                    'SignatureVersion' => Str::uuid(),
                ])
            ]);

        $response->assertSee('notification processed');
        Bus::assertDispatched(RecordComplaintJob::class, function ($job) use ($message) {
            return $job->message == (object)$message;
        });
    }

    /**
     * @test
     */
    public function it_handles_ampersands_in_links()
    {
        Event::fake();
        Config::set('mail-tracker.track-links', true);
        Config::set('mail-tracker.inject-pixel', true);
        Config::set('mail.driver', 'array');
        (new MailServiceProvider(app()))->register();
        // Must re-register the MailTracker to get the test to work
        $this->app['mailer']->getSwiftMailer()->registerPlugin(new MailTracker());

        $faker = Factory::create();
        $email = $faker->email;
        $subject = $faker->sentence;
        $name = $faker->firstName . ' ' .$faker->lastName;
        \View::addLocation(__DIR__);

        \Mail::send('email.testAmpersand', [], function ($message) use ($email, $subject, $name) {
            $message->from('from@johndoe.com', 'From Name');
            $message->sender('sender@johndoe.com', 'Sender Name');

            $message->to($email, $name);

            $message->cc('cc@johndoe.com', 'CC Name');
            $message->bcc('bcc@johndoe.com', 'BCC Name');

            $message->replyTo('reply-to@johndoe.com', 'Reply-To Name');

            $message->subject($subject);

            $message->priority(3);
        });

        $driver = $this->app['swift.transport']->driver();
        $this->assertEquals(1, count($driver->messages()));

        $mes = $driver->messages()[0];
        $body = $mes->getBody();
        $hash = $mes->getHeaders()->get('X-Mailer-Hash')->getValue();

        $matches = null;
        preg_match_all('/(<a[^>]*href=[\'"])([^\'"]*)/', $body, $matches);
        $links = $matches[2];
        $aLink = $links[1];

        $expected_url = "http://www.google.com?q=foo&x=bar";
        $this->assertNotNull($aLink);
        $this->assertNotEquals($expected_url, $aLink);

        $response = $this->call('GET', $aLink);
        $response->assertRedirect($expected_url);

        Event::assertDispatched(LinkClickedEvent::class);

        $this->assertDatabaseHas('sent_emails_url_clicked', [
            'url' => $expected_url,
            'clicks' => 1,
        ]);

        $track = \jdavidbakr\MailTracker\Model\SentEmail::whereHash($hash)->first();
        $this->assertNotNull($track);
        $this->assertEquals(1, $track->clicks);
    }

    /**
     * @test
     */
    public function it_retrieves_header_data()
    {
        $faker = Factory::create();
        $email = $faker->email;
        $subject = $faker->sentence;
        $name = $faker->firstName . ' ' .$faker->lastName;
        $header_test = Str::random(10);
        \View::addLocation(__DIR__);
        \Mail::send('email.test', [], function ($message) use ($email, $subject, $name, $header_test) {
            $message->from('from@johndoe.com', 'From Name');
            $message->sender('sender@johndoe.com', 'Sender Name');

            $message->to($email, $name);

            $message->cc('cc@johndoe.com', 'CC Name');
            $message->bcc('bcc@johndoe.com', 'BCC Name');

            $message->replyTo('reply-to@johndoe.com', 'Reply-To Name');

            $message->subject($subject);

            $message->priority(3);

            $message->getHeaders()->addTextHeader('X-Header-Test', $header_test);
        });

        $track = \jdavidbakr\MailTracker\Model\SentEmail::orderBy('id', 'desc')->first();
        $this->assertEquals($header_test, $track->getHeader('X-Header-Test'));
    }

    /**
     * @test
     */
    public function it_handles_secondary_connection()
    {
        // Create an old email to purge
        Config::set('mail-tracker.expire-days', 1);

        Config::set('mail-tracker.connection', 'secondary');
        $this->app['migrator']->setConnection('secondary');
        $this->artisan('migrate', ['--database' => 'secondary']);

        $old_email = \jdavidbakr\MailTracker\Model\SentEmail::create([
            'hash' => Str::random(32),
        ]);
        $old_url = \jdavidbakr\MailTracker\Model\SentEmailUrlClicked::create([
            'sent_email_id' => $old_email->id,
            'hash' => Str::random(32),
        ]);
        // Go into the future to make sure that the old email gets removed
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::now()->addWeek());

        Event::fake();

        $faker = Factory::create();
        $email = $faker->email;
        $subject = $faker->sentence;
        $name = $faker->firstName . ' ' .$faker->lastName;
        \View::addLocation(__DIR__);
        \Mail::send('email.test', [], function ($message) use ($email, $subject, $name) {
            $message->from('from@johndoe.com', 'From Name');
            $message->sender('sender@johndoe.com', 'Sender Name');

            $message->to($email, $name);

            $message->cc('cc@johndoe.com', 'CC Name');
            $message->bcc('bcc@johndoe.com', 'BCC Name');

            $message->replyTo('reply-to@johndoe.com', 'Reply-To Name');

            $message->subject($subject);

            $message->priority(3);
        });

        Event::assertDispatched(EmailSentEvent::class);

        $this->assertDatabaseHas('sent_emails', [
            'recipient' => $name.' <'.$email.'>',
            'subject' => $subject,
            'sender' => 'From Name <from@johndoe.com>'
        ], 'secondary');
        $this->assertNull($old_email->fresh());
        $this->assertNull($old_url->fresh());
    }

    /**
     * @test
     */
    public function it_can_retrieve_url_clicks_from_eloquent()
    {
        Event::fake();
        $track = \jdavidbakr\MailTracker\Model\SentEmail::create([
            'hash' => Str::random(32),
        ]);
        $message_id = Str::random(32);
        $track->message_id = $message_id;
        $track->save();

        $urlClick = \jdavidbakr\MailTracker\Model\SentEmailUrlClicked::create([
            'sent_email_id' => $track->id,
            'url' => 'https://example.com',
            'hash' => Str::random(32)
        ]);
        $urlClick->save();
        $this->assertTrue($track->urlClicks->count() === 1);
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $track->urlClicks);
        $this->assertInstanceOf(\jdavidbakr\MailTracker\Model\SentEmailUrlClicked::class, $track->urlClicks->first());
    }
}
