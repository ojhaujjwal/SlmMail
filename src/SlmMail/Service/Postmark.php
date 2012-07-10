<?php
/**
 * Copyright (c) 2012 Jurian Sluiman.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the names of the copyright holders nor the names of the
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package     SlmMail
 * @subpackage  Service
 * @author      Jurian Sluiman <jurian@juriansluiman.nl>
 * @copyright   2012 Jurian Sluiman.
 * @license     http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @link        http://juriansluiman.nl
 */
namespace SlmMail\Service;

use Zend\Mail\Message,
    Zend\Http\Client,
    Zend\Http\Request,
    Zend\Http\Response,
    Zend\Json\Json,
    Zend\Mail\Exception\RuntimeException,
    SlmMail\Mail\Message\Postmark as PostmarkMessage;

class Postmark
{
    const API_URI         = 'http://api.postmarkapp.com/';
    const RECIPIENT_LIMIT = 20;

    protected $apiKey;
    protected $client;
    protected $filters = array(
        'HardBounce',
        'Transient',
        'Unsubscribe',
        'Subscribe',
        'AutoResponder',
        'AddressChange',
        'DnsError',
        'SpamNotification',
        'OpenRelayTest',
        'Unknown',
        'SoftBounce',
        'VirusNotification',
        'ChallengeVerification',
        'BadEmailAddress',
        'SpamComplaint',
        'ManuallyDeactivated',
        'Unconfirmed',
        'Blocked'
    );

    /**
     * Constructor
     *
     * @param string $api_key
     */
    public function __construct ($api_key)
    {
        $this->apiKey = $api_key;
    }

    /**
     * Send message to Postmark service
     *
     * @link http://developer.postmarkapp.com/developer-build.html
     * @param Message $message
     * @return stdClass
     */
    public function sendEmail (Message $message)
    {
        $data = array(
            'Subject'  => $message->getSubject(),
            'HtmlBody' => $message->getBody(),
            'TextBody' => $message->getBodyText(),
        );

        $to = array();
        foreach ($message->to() as $address) {
            $to[] = $address->toString();
        }
        $data['To'] = implode(',', $to);

        $cc = array();
        foreach ($message->cc() as $address) {
            $cc[] = $address->toString();
        }
        if (self::RECIPIENT_LIMIT < count($cc)) {
            throw new RuntimeException('Limitation exceeded for CC recipients');
        } elseif (count($cc)) {
            $data['Cc'] = implode(',', $cc);
        }

        $bcc = array();
        foreach ($message->bcc() as $address) {
            $bcc[] = $address->toString();
        }
        if (self::RECIPIENT_LIMIT < count($bcc)) {
            throw new RuntimeException('Limitation exceeded for BCC recipients');
        } elseif (count($bcc)) {
            $data['Bcc'] = implode(',', $bcc);
        }

        $from = $message->from();
        if (1 !== count($from)) {
            throw new RuntimeException('Postmark requires a registered and confirmed from address');
        }
        $from->rewind();
        $data['From'] = $from->current()->toString();

        $replyTo = $message->replyTo();
        if (1 < count($replyTo)) {
            throw new RuntimeException('Postmark has only support for one reply-to address');
        } elseif (count($replyTo)) {
            $from->rewind();
            $data['ReplyTo'] = $replyTo->current()->toString();
        }

        if ($message instanceof PostmarkMessage
            && null !== ($tag = $message->getTag())
        ) {
            $data['Tag'] = $tag;
        }

        /**
         * @todo Handling attachments for emails
         *
         * Example code how that possibly might work:
         *
         * <code>
         * if ($hasAttachment) {
         *      $attachments = array();
         *      foreach ($message->getAttachmentCollection() as $attachment) {
         *          $attachments[] = array(
         *              'ContentType' => $attachment->getContentType(),
         *              'Name'        => $attachment->getName(),
         *              'Content'     => $attachment->getContent(),
         *          );
         *      }
         *      $data['Attachments'] = $attachments;
         *  }
         * </code>
         */

        $response = $this->prepareHttpClient('/email')
                         ->setMethod(Request::METHOD_POST)
                         ->setRawBody(Json::encode($data))
                         ->send();

        return $this->parseResponse($response);
    }

    /**
     * Get a summary of inactive emails and bounces by type
     *
     * @link http://developer.postmarkapp.com/developer-bounces.html#get-delivery-stats
     * @return StdClass
     */
    public function getDeliveryStats ()
    {
        $response = $this->prepareHttpClient('/deliverystats')
                         ->send();

        return $this->parseResponse($response);
    }

    /**
     * Get a portion of bounces according to the specified input criteria
     *
     * The $count and $offset are mandatory. For type, a specific set of types
     * are available, defined as filter.
     *
     * @see $filters
     * @link http://developer.postmarkapp.com/developer-bounces.html#get-bounces
     * @param int $count
     * @param int $offset
     * @param string $type
     * @param string $inactive
     * @param string $emailFilter
     * @return StdClass
     */
    public function getBounces ($count, $offset, $type = null, $inactive = null, $emailFilter = null)
    {
        if (null !== $type &&!in_array($type, $this->filters)) {
            throw new RuntimeException(sprintf(
                'Type %s is not a supported filter',
                $type
            ));
        }

        $params   = compact('count', 'offset', 'type', 'inactive', 'emailFilter');
        $params   = $this->filterNullParams($params);
        $response = $this->prepareHttpClient('/bounces')
                         ->setParameterGet($params)
                         ->send();

        return $this->parseResponse($response);
    }

    /**
     * Get details about a single bounce
     *
     * @link http://developer.postmarkapp.com/developer-bounces.html#get-a-single-bounce
     * @param int $id
     * @return stdClass
     */
    public function getBounce ($id)
    {
        $response = $this->prepareHttpClient('/bounces/' . $id)
                         ->send();

        return $this->parseResponse($response);
    }

    /**
     * Get the raw source of the bounce Postmark accepted
     *
     * @link http://developer.postmarkapp.com/developer-bounces.html#get-bounce-dump
     * @param int $id
     * @return string
     */
    public function getBounceDump ($id)
    {
        $response = $this->prepareHttpClient('/bounces/' . $id . '/dump')
                         ->send();

        $response = $this->parseResponse($response);
        return $response->Body;
    }

    /**
     * Get a list of tags used for the current Postmark server
     *
     * @link http://developer.postmarkapp.com/developer-bounces.html#get-bounce-tags
     * @return array
     */
    public function getBounceTags ()
    {
        $response = $this->prepareHttpClient('/bounces/tags')
                         ->send();

        return $this->parseResponse($response);
    }

    /**
     * Activates a deactivated bounce
     *
     * @link http://developer.postmarkapp.com/developer-bounces.html#activate-a-bounce
     * @param int $id
     * @return StdClass
     */
    public function activateBounce ($id)
    {
        $response = $this->prepareHttpClient('/bounces/' . $id . '/activate')
                         ->setMethod(Request::METHOD_PUT)
                         ->send();

        return $this->parseResponse($response);
    }

    public function getHttpClient ()
    {
        if (null === $this->client) {
            $this->client = new Client;

            $headers = array(
                'Accept'                  => 'application/json',
                'X-Postmark-Server-Token' => $this->apiKey
            );
            $this->client->setMethod(Request::METHOD_GET)
                         ->setHeaders($headers);
        }

        return $this->client;
    }

    public function setHttpClient (Client $client)
    {
        $this->client = $client;
    }

    /**
     * Get a http client instance
     *
     * @param string $path
     * @return Client
     */
    protected function prepareHttpClient ($path)
    {
        return $this->getHttpClient()->setUri(self::API_URI . $path);
    }

    /**
     * Filter null values from the array
     *
     * Because parameters get interpreted when they are send, remove them
     * from the list before the request is sent.
     *
     * @param array $params
     * @param array $exceptions
     * @return array
     */
    protected function filterNullParams (array $params, array $exceptions = array())
    {
        $return = array();
        foreach ($params as $key => $value) {
            if (null !== $value || in_array($key, $exceptions)) {
                $return[$key] = $value;
            }
        }

        return $return;
    }

    /**
     * Parse a Reponse object and check for errors
     *
     * @param Response $response
     * @return StdClass
     */
    protected function parseResponse (Response $response)
    {
        if (!$response->isOk()) {
            switch ($response->getStatusCode()) {
                case 401:
                    throw new RuntimeException('Could not send request: authentication error');
                    break;
                case 422:
                    $error = Json::decode($response->getBody());
                    throw new RuntimeException(sprintf(
                        'Could not send request: api error code %s (%s)',
                        $error->ErrorCode, $error->Message));
                    break;
                case 500:
                    throw new RuntimeException('Could not send request: Postmark server error');
                    break;
                default:
                    throw new RuntimeException('Unknown error during request to Postmark server');
            }
        }

        return Json::decode($response->getBody());
    }
}