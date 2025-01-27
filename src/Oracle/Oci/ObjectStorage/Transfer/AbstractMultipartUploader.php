<?php
/**Copyright (c) 2023, Oracle and/or its affiliates. All rights reserved.
 * This software is dual-licensed to you under the Universal Permissive License
 * (UPL) 1.0 as shown at https://oss.oracle.com/licenses/upl or Apache License
 * 2.0 as shown at http://www.apache.org/licenses/LICENSE-2.0. You may choose
 * either license.
*/
namespace Oracle\Oci\ObjectStorage\Transfer;

use GuzzleHttp\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Oracle\Oci\ObjectStorage\ObjectStorageAsyncClient;
use Oracle\Oci\Common\Logging\Logger;
use UploadManagerConstants;

abstract class AbstractMultipartUploader extends AbstractUploader
{
    protected $config;

    protected $uploadId;

    protected $partsToCommit = [];

    protected $partsToRetry = [];

    public function __construct(ObjectStorageAsyncClient $client, UploadManagerRequest &$uploadManagerRequest)
    {
        parent::__construct($client, $uploadManagerRequest);
        $this->config = $uploadManagerRequest->getUploadConfig();
    }

    public function promise(): PromiseInterface
    {
        if ($this->promise) {
            return $this->promise;
        }

        return $this->promise = Promise\Each::ofLimit(
            $this->prepareUpload(),
            $this->config[UploadManagerConstants::ALLOW_PARALLEL_UPLOADS] ?
                $this->config[UploadManagerConstants::CONCURRENCY] : 1
        )->then(
            function () {
                if (count($this->partsToRetry) > 0) {
                    throw new MultipartUploadException(
                        $this->uploadId,
                        $this->uploadManagerRequest,
                        $this->partsToCommit,
                        $this->partsToRetry
                    );
                }
                return $this->partsToCommit;
            }
        );
    }

    protected function prepareUpload()
    {
        foreach ($this->prepareSources() as $source) {
            $params = array_merge($this->initUploadRequest(), [
                'uploadPartNum'=>$source['partNum'],
                'uploadPartBody'=> &$source['content'],
                'contentLength'=> $source['length'],
                'uploadId'=> $this->uploadId,
            ]);
            Logger::logger(static::class)->debug("Preparing for multipart uploading part: ".$params['uploadPartNum']);
            yield $this->client->uploadPartAsync(
                $params
            )->then(function ($response) use ($source) {
                Logger::logger(static::class)->debug("multipart uploading part: ".$source['partNum']." success");
                array_push($this->partsToCommit, [
                        'partNum' => $source['partNum'],
                        'etag' => $response->getHeaders()['etag'][0]
                    ]);
            }, function ($e) use ($source) {
                Logger::logger(static::class)->debug("multipart uploading part: ".$source['partNum']." failed, error details: ".$e);
                array_push($this->partsToRetry, [
                    'partNum' => $source['partNum'],
                    'length' => $source['length'],
                    'position' => $source['position'],
                    'exception' => $e
                ]);
            });
            unset($source);
        }
    }
    abstract protected function prepareSources();
}
